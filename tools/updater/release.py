#!/usr/bin/env python3
"""Offline signed-release verifier and fail-closed upgrade/restore helper.

Never download releases from untrusted URLs. The private signing key is never shipped.
MySQL backup + php PDO migration are mandatory for a live apply. No auto-rollback
of partially applied MySQL DDL is attempted.
"""
from __future__ import annotations
import argparse
import hashlib
import hmac
import io
import json
import os
import re
import shutil
import stat
import subprocess
import sys
import tarfile
import tempfile
from pathlib import Path, PurePosixPath
from zipfile import ZipFile

ROOT_ALLOWED = {"README.md", "INSTALL.md", "INSTALL_NEW.md", "SECURITY.md", "CHANGELOG.md", "VERSION", "composer.json", "release-manifest.json"}
PREFIX_ALLOWED = ("app/", "public/", "database/", "deploy/", "tools/", "resources/")
PINNED_DOCS_ALLOWED = {'docs/ACCEPTED_RESEARCH_FEED_d1.0.1.json', 'docs/research_external_links_source/JOB_LINK_CATALOG_d1.0.2.json', 'docs/CHANGE25_BATCH20_NORMALIZED_R06.json', 'docs/research_snapshot_d1.0.4_R06/COMPANY_JOB_RELATIONS_d1.0.4_R06.json', 'docs/research_snapshot_d1.0.4_R06/DATA_MANIFEST_d1.0.4_R06.json', 'docs/research_snapshot_d1.0.4_R06/RESEARCH_DELTA_d1.0.4_R06.json', 'docs/research_snapshot_d1.0.4_R06/JOB_EMPLOYMENT_TYPES_d1.0.4_R06.csv', 'docs/research_snapshot_d1.0.4_R06/JOB_SCREENING_MATRIX_d1.0.4_R06.csv', 'docs/research_snapshot_d1.0.4_R06/PUBLIC_SOURCE_CHECKS_d1.0.4_R06.csv', 'docs/research_snapshot_d1.0.4_R06/RESEARCH_AUDIT_d1.0.4_R06.md'}
MAX_MEMBER = 50 * 1024 * 1024
MAX_TOTAL = 250 * 1024 * 1024
MAX_ENTRIES = 2000
TARGET_VERSION = "1.1.0"
TARGET_SCHEMA = 41


def invalid(message: str):
    raise ValueError(message)


def supported_source(schema: int, version: str) -> bool:
    """Only historically matching 0.1.x sources or an OLDER Schema41 beta.

    Release labels are never treated as a reason to skip a schema check.
    Neither a same-version reinstall nor a downgrade is an upgrade.
    """
    if type(schema) is not int or not isinstance(version, str):
        return False
    if 1 <= schema < TARGET_SCHEMA:
        return version == f"0.1.{schema}"
    if schema != TARGET_SCHEMA:
        return False
    if version == f"0.1.{TARGET_SCHEMA}":
        return True
    old = re.fullmatch(r"0\.219\.([1-9][0-9]*)-beta", version)
    target = re.fullmatch(r"0\.219\.([1-9][0-9]*)-beta", TARGET_VERSION)
    if target:
        return bool(old and int(old.group(1)) < int(target.group(1)))
    # First stable release follows the beta train. Keep strict source and schema
    # validation; never treat equal version, arbitrary version, or downgrade as valid.
    if TARGET_VERSION == "1.0.0":
        return bool(old)
    if TARGET_VERSION == "1.0.1":
        return bool(old) or version == "1.0.0"
    if TARGET_VERSION == "1.1.0":
        # Same-schema safe-upgrade eligibility, not authorization to bypass the
        # independently verified signature/backup/locks.
        return bool(old) or (version in ("1.0.0", "1.0.1"))
    return False


def safe_path(name: str) -> bool:
    if not name or name.startswith("/") or "\\" in name or "\x00" in name:
        return False
    path = PurePosixPath(name)
    if len(path.parts) == 0 or any(p in (".", "..", "") or p.startswith(".env") for p in path.parts):
        return False
    return name==path.as_posix() and (name in ROOT_ALLOWED or name in PINNED_DOCS_ALLOWED or name.startswith(PREFIX_ALLOWED))


def verify_archive(archive: Path, signature: Path, key_file: Path) -> tuple[dict, dict[str, bytes]]:
    try:
        from cryptography.exceptions import InvalidSignature
        from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
        from cryptography.hazmat.primitives.serialization import load_pem_public_key
    except ImportError as e:
        raise RuntimeError("cryptography package with Ed25519 support required") from e
    if not key_file.is_file() or key_file.is_symlink():
        invalid("No independently pinned public key")
    public_key = load_pem_public_key(key_file.read_bytes())
    if not isinstance(public_key, Ed25519PublicKey):
        invalid("Pinned release key must be Ed25519")
    if archive.stat().st_size > MAX_TOTAL or not signature.is_file():
        invalid("Archive or detached signature missing/oversized")
    with ZipFile(archive) as z:
        members = [x for x in z.infolist() if not x.is_dir()]
        names = [x.filename for x in members]
        if len(z.infolist()) > MAX_ENTRIES or len(names) != len(set(names)) or "release-manifest.json" not in names:
            invalid("Duplicated or missing manifest/invalid member count")
        if any(not safe_path(n) for n in names):
            invalid("Unsafe ZIP member path")
        if any(((m.external_attr >> 16) & 0o170000) not in (0, stat.S_IFREG) for m in members):
            invalid("Symlinks or nonregular ZIP members are forbidden")
        if any(m.file_size > MAX_MEMBER for m in members) or sum(m.file_size for m in members) > MAX_TOTAL:
            invalid("Oversized ZIP members")
        manifest_bytes = z.read("release-manifest.json")
        if len(manifest_bytes) > 512*1024:
            invalid("Oversized manifest")
        try:
            public_key.verify(signature.read_bytes(), manifest_bytes)
        except InvalidSignature as e:
            raise ValueError("Detached release signature invalid") from e
        manifest = json.loads(manifest_bytes)
        if not isinstance(manifest, dict) or manifest.get("format") != "kmm-signed-release-v1" or manifest.get("version") != TARGET_VERSION or manifest.get("schema_version") != TARGET_SCHEMA:
            invalid("Release manifest format or version invalid")
        expected = manifest.get("files")
        if not isinstance(expected, dict) or set(expected) != (set(names)-{"release-manifest.json"}):
            invalid("Manifest file list differs from ZIP contents")
        files: dict[str, bytes] = {}
        for name in names:
            if name == "release-manifest.json":
                continue
            data = z.read(name)
            digest=expected[name]
            if not isinstance(digest, str) or not re.fullmatch(r"[a-f0-9]{64}",digest) or hashlib.sha256(data).hexdigest()!=digest:
                invalid("Release member digest mismatch: " + name)
            files[name]=data
        if files.get("VERSION",b"").strip()!=TARGET_VERSION.encode("ascii"):
            invalid("VERSION file disagrees with release")
    return manifest, files


def lock_record(root: Path,name: str) -> tuple[dict,bytes]:
    key=(root/"storage/private/app.key").read_bytes()
    if len(key)!=32:
        invalid("Invalid installation HMAC key")
    record=json.loads((root/f"storage/system/{name}.lock").read_text())
    body=record.get("data")
    if not isinstance(body,dict):
        invalid("Invalid installation lock")
    raw=json.dumps(body,ensure_ascii=False,separators=(",", ":")).encode()
    # PHP json_encode escapes slashes and non-ASCII by default. Existing metadata is ASCII.
    if not hmac.compare_digest(hmac.new(key,raw,hashlib.sha256).hexdigest(),str(record.get("mac",""))):
        invalid("Tampered installation lock")
    return body,key


def write_lock(root:Path,name:str,data:dict,key:bytes):
    raw=json.dumps(data,ensure_ascii=False,separators=(",", ":")).encode()
    value=json.dumps({"data":data,"mac":hmac.new(key,raw,hashlib.sha256).hexdigest()},ensure_ascii=False,separators=(",", ":")).encode()
    target=root/f"storage/system/{name}.lock"
    atomic_write(target,value,0o600)


def file_sha256(path:Path)->str:
    """Stream large archives without loading a full database snapshot into RAM."""
    digest=hashlib.sha256()
    with path.open('rb') as source:
        for chunk in iter(lambda:source.read(1024*1024),b''):
            digest.update(chunk)
    return digest.hexdigest()


def atomic_write(path:Path,data:bytes,mode=0o644):
    fd,p=tempfile.mkstemp(prefix=".kmm_write_",dir=path.parent)
    try:
        with os.fdopen(fd,"wb") as stream:
            os.fchmod(stream.fileno(),mode)
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(p,path)
    finally:
        if os.path.exists(p):os.unlink(p)


def check_live(root:Path) -> tuple[dict,dict,bytes]:
    if not root.is_dir() or root.is_symlink():invalid("Site root must be a real local directory")
    old,key=lock_record(root,"install")
    update,_=lock_record(root,"update")
    if old.get("phase")!="INSTALLED" or update.get("phase")!="READY" or any(old.get(k)!=update.get(k) for k in ("install_id","schema","version")):
        invalid("Site is not in READY state")
    current_version=(root/"VERSION").read_text().strip()
    installed_version=old.get("version")
    installed_schema=old.get("schema")
    if not isinstance(installed_version,str) or installed_version!=current_version or type(installed_schema) is not int:
        invalid("Old release version mismatch")
    if not supported_source(installed_schema, installed_version):
        invalid("Installed release/version and schema pair is unsupported or not older than target")
    manifest=json.loads((root/"release-manifest.json").read_text())
    if manifest.get("version")!=old["version"] or manifest.get("schema_version")!=old["schema"]:
        invalid("Installed manifest version differs from installation state")
    # v0.1.9+ records runtime-deployment hashes separately from source hashes.
    # Legacy source manifests also list docs/tests/resources/storage/.gitkeep that
    # are intentionally absent from a deployable ZIP. Verify ALL runtime members,
    # not those repository-only files, to avoid false failures and false trust.
    has_deployment_manifest=isinstance(manifest.get("files"),dict)
    recorded=manifest.get("files") if has_deployment_manifest else (manifest.get("source_sha256") or manifest.get("file_sha256"))
    if not isinstance(recorded,dict):invalid("Installed runtime integrity manifest missing")
    if has_deployment_manifest and any(not safe_path(n) for n in recorded):
        invalid("Deployment integrity manifest includes a nonruntime path")
    expected={n:h for n,h in recorded.items() if safe_path(n)}
    if not {"VERSION","public/index.php"}.issubset(expected) or len(expected)<2:
        invalid("Installed runtime baseline missing mandatory members")
    for name,digest in expected.items():
        if not isinstance(digest,str) or not re.fullmatch(r"[a-f0-9]{64}",digest):
            invalid("Invalid installed baseline checksum: "+name)
        existing=root/name
        if any((root/part).is_symlink() for part in list(PurePosixPath(name).parents) if str(part)!="."):
            invalid("Symlink in installed runtime path: " + name)
        if not existing.is_file() or existing.is_symlink() or hashlib.sha256(existing.read_bytes()).hexdigest()!=digest:
            invalid("Installed application file differs from baseline: " + name)
    if not (root/"storage/private/database.json").is_file():invalid("Database config missing")
    return old,update,key


def cnf_file(root:Path):
    cfg=json.loads((root/"storage/private/database.json").read_text())
    for field in ("host","database","username","password"):
        if not isinstance(cfg.get(field),str) or any(x in cfg[field] for x in ("\n","\r","\x00")):
            invalid("Invalid database configuration")
    if not re.fullmatch(r"[A-Za-z0-9_]{1,64}",cfg["database"]):invalid("Invalid database name")
    def q(s):return '"'+s.replace('\\','\\\\').replace('"','\\"')+'"'
    contents='[client]\nhost='+q(cfg["host"])+'\nport='+str(int(cfg.get("port",3306)))+'\nuser='+q(cfg["username"])+'\npassword='+q(cfg["password"])+'\n'
    fd,p=tempfile.mkstemp(prefix=".kmm_mysql_",dir=root/"storage/private")
    with os.fdopen(fd,"w") as f:
        os.fchmod(f.fileno(),0o600)
        f.write(contents)
    return Path(p),cfg["database"]



def write_private_backup_archive(root:Path,backups:Path,db_dump:Path,original_update_lock:bytes,temporary_config:Path)->Path:
    """Filesystem-only archive writer, testable without executing MySQL.

    A backup contains database credentials and private user files. Reserve mode
    0600 *before* the first archive byte; unlink partial archives on any error.
    """
    from datetime import datetime, timezone
    out=backups/("kmm_backup_"+datetime.now(timezone.utc).strftime('%Y%m%d')+"_"+os.urandom(8).hex()+".tar.gz")
    fd=os.open(out,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
    try:
        with os.fdopen(fd,'wb') as output:
            with tarfile.open(fileobj=output,mode='w:gz',format=tarfile.PAX_FORMAT) as tar:
                tar.add(db_dump,arcname='database.sql',recursive=False)
                for path in sorted(root.rglob('*')):
                    if path.is_relative_to(backups) or path==temporary_config or path==db_dump:continue
                    if path.is_symlink():invalid('Symlink found in installed site; backup refused')
                    if path.is_file():
                        rel=path.relative_to(root).as_posix()
                        if rel=='storage/system/update.lock':
                            record=tarfile.TarInfo('files/'+rel)
                            record.size=len(original_update_lock)
                            record.mode=0o600
                            tar.addfile(record,io.BytesIO(original_update_lock))
                        else:tar.add(path,arcname='files/'+rel,recursive=False)
            output.flush()
            os.fsync(output.fileno())
        return out
    except BaseException:
        out.unlink(missing_ok=True)
        raise


def make_backup(root:Path, original_update_lock:bytes)->tuple[Path,str]:
    backups=root/"storage/backups"
    backups.mkdir(parents=True,exist_ok=True,mode=0o700)
    if backups.is_symlink():invalid("Backup directory symlink forbidden")
    mysql_dump=shutil.which("mysqldump")
    if not mysql_dump:invalid("MySQL dump tool unavailable; refused before changes")
    config, dbname=cnf_file(root)
    db_dump=None
    try:
        fd,tmp=tempfile.mkstemp(prefix=".kmm_db_backup_",dir=backups)
        db_dump=Path(tmp)
        with os.fdopen(fd,"wb") as output:
            os.fchmod(output.fileno(),0o600)
            command=[mysql_dump,f"--defaults-extra-file={config}","--single-transaction","--quick","--hex-blob","--no-tablespaces","--routines","--events","--triggers","--set-gtid-purged=OFF",dbname]
            subprocess.run(command,stdout=output,stderr=subprocess.PIPE,check=True,timeout=300)
        if db_dump.stat().st_size<100:invalid("DB dump unexpectedly empty")
        out=write_private_backup_archive(root,backups,db_dump,original_update_lock,config)
        return out,file_sha256(out)
    finally:
        config.unlink(missing_ok=True)
        if db_dump:db_dump.unlink(missing_ok=True)


def apply_release(root:Path,release:Path,signature:Path,confirmed:bool):
    pub=root/"storage/private/release-verification.pem"
    manifest,files=verify_archive(release,signature,pub)
    if manifest.get("deployment_allowed") is not True:
        invalid("Release is versioned but not authorized for production deployment")
    old,update,key=check_live(root)
    if not confirmed:
        print(f"PLAN ONLY: {old['version']} → {manifest['version']}; {len(files)} signed application files. No changes made.")
        return
    if os.geteuid()==0: invalid("Do not run updater as root")
    if not shutil.which("php") or not shutil.which("mysqldump") or not shutil.which("mysql"):
        invalid("PHP/MySQL dump/restore CLI prerequisites missing; no changes made")
    php_modules=subprocess.run(["php","-m"],capture_output=True,text=True,check=True).stdout.lower()
    if "pdo_mysql" not in php_modules: invalid("PDO MySQL is mandatory for apply; only static checks may run")
    guard=root/"storage/system/.updater.guard"
    guard.parent.mkdir(exist_ok=True,parents=True)
    import fcntl
    with guard.open("a+b") as g:
        fcntl.flock(g,fcntl.LOCK_EX|fcntl.LOCK_NB)
        old,_,key=check_live(root)
        original_update=(root/"update.lock").read_bytes()
        # Quiesce all web writes BEFORE taking the database snapshot.
        write_lock(root,"update",{**old,"phase":"BACKUP"},key)
        try:
            backup,digest=make_backup(root,original_update)
        except Exception:
            # Only the maintenance lock changed; return to READY safely.
            atomic_write(root/"update.lock",original_update,0o600)
            raise
        record={**old,"phase":"UPDATING","backup_basename":backup.name,"backup_sha256":digest}
        write_lock(root,"update",record,key)
        try:
            for name,data in sorted(files.items()):
                target=root/name
                target.parent.mkdir(parents=True,exist_ok=True)
                if target.is_symlink() or any(x.is_symlink() for x in target.parents if x!=root.parent):
                    invalid("Symlink in destination path: "+name)
                if name=="VERSION":continue
                atomic_write(target,data,0o644)
            atomic_write(root/"release-manifest.json",json.dumps(manifest,ensure_ascii=False,indent=2).encode(),0o644)
            atomic_write(root/"VERSION",files["VERSION"],0o644)
            cmd=["php",str(root/"tools/upgrade/migrate.php"),"--apply","--confirm"]
            subprocess.run(cmd,cwd=root,check=True,timeout=300)
            print("UPDATE APPLIED: "+manifest["version"]+"; backup stored locally: "+str(backup))
        except Exception:
            print("UPDATE INCOMPLETE. Maintenance lock preserved. DO NOT rerun installer. Restore using the verified backup.",file=sys.stderr)
            raise


MAX_BACKUP_ENTRIES=50000
MAX_BACKUP_UNPACKED=100 * 1024 * 1024 * 1024

def verify_backup_members(tar:tarfile.TarFile)->dict[str,tarfile.TarInfo]:
    """Offline, read-only structural check; no MySQL or production keys needed.

    Integrity/authenticity requires the HMAC-sealed hash check in restore_backup.
    This function only protects against malformed or unsafe archive members.
    """
    members=tar.getmembers()
    if len(members)>MAX_BACKUP_ENTRIES or sum(m.size for m in members)>MAX_BACKUP_UNPACKED:
        invalid("Backup has too many/oversized members")
    found={}
    for member in members:
        if not member.isfile() or member.issym() or member.islnk():
            invalid("Unsafe backup member type")
        name=member.name
        if name!="database.sql":
            if not name.startswith("files/"):
                invalid("Unexpected backup member")
            rel=name[6:]
            pp=PurePosixPath(rel)
            if not rel or rel.startswith("/") or "\\" in rel or any(x in ("", ".", "..") for x in rel.split("/")) or pp.as_posix()!=rel:
                invalid("Unsafe backup relative path")
        if name in found:
            invalid("Duplicate backup path")
        found[name]=member
    required={"database.sql","files/VERSION","files/storage/system/install.lock","files/storage/system/update.lock", "files/storage/private/app.key", "files/storage/private/database.json", "files/public/index.php"}
    if not required.issubset(found) or found["database.sql"].size < 100:
        invalid("Backup is missing required contents or DB dump is empty")
    return found


# Historical additive-only DDL inventory is pinned in this restore tool.
# Only tables introduced AFTER the backed-up schema may be dropped on restore;
# never erase any original table. This helper is offline-testable.
RESTORE_ADDITIONS = {
    2: ["auth_tokens", "user_profiles", "mail_delivery_events"],
    3: ["companies", "sources", "brands", "company_locations", "jobs", "job_locations", "company_sources", "business_change_events", "project_seed_records"],
    4: ["library_documents", "library_document_versions"],
    5: ["application_tracks", "application_events", "application_audit"],
    6: ["edit_proposals", "public_change_events"],
    7: ["messages", "message_recipients", "message_reports", "message_user_settings", "message_blocks", "message_broadcasts", "message_delivery_jobs"],
    8: ["seed_record_states", "seed_user_overlays", "seed_update_batches", "seed_merge_conflicts", "seed_batch_effects", "seed_admin_notices", "history_import_batches", "history_import_items"],
    9: [],
    10: [],
    11: ["seed_entity_states", "seed_entity_overlays", "seed_entity_conflicts", "message_sender_states"],
    12: [],
    13: ["user_favorite_companies", "user_company_notes", "user_saved_filters", "user_guide_steps"],
    14: ["user_job_eligibility_checks", "library_document_metadata", "company_fact_claims", "user_reminder_settings", "user_reminder_deliveries"],
    15: [],
    16: [],
    17: [],
    18: [],
    19: [],
    20: [],
    21: [],
    22: [],
    23: [],
    24: [],
    25: [],
    26: [],
    27: [],
    28: [],
    29: [],
    30: [],
    31: ['research_identity_review_states'],
    32: [],
    33: [],
    34: [],
    35: [],
    36: [],
    37: ["research_import_batches","research_import_items","research_import_outbox"],
    38: ["research_external_links"],
    39: ["research_supplement_claims"],
    40: ["research_publication_batches","research_publication_outbox"],
    41: ["research_link_revision_guards"],
}

def restore_drop_tables(old_schema:int)->list[str]:
    # Schema41 -> Schema41 is a metadata-only release update: no DDL to undo.
    # For legacy upgrades, remove only tables absent from the source schema.
    if type(old_schema) is not int or not 1 <= old_schema <= TARGET_SCHEMA:
        invalid("Unknown backup source schema")
    return [table for version, tables in RESTORE_ADDITIONS.items()
            if version > old_schema for table in tables]


# Preflight is deliberately read-only. It can be run without a MySQL server,
# installed database driver, or private *release signing* key. It validates
# the locally sealed recovery image before any DROP/restore operation.
BACKUP_NAME_PATTERN = re.compile(r"\Akmm_backup_[0-9]{8}_[a-f0-9]{16}\.tar\.gz\Z")
BACKUP_METADATA_LIMITS = {
    'files/VERSION': 64,
    'files/release-manifest.json': 512 * 1024,
    'files/storage/private/app.key': 32,
    'files/storage/private/database.json': 64 * 1024,
    'files/storage/system/install.lock': 64 * 1024,
    'files/storage/system/update.lock': 64 * 1024,
}


def _backup_metadata(tar:tarfile.TarFile,members:dict[str,tarfile.TarInfo],name:str)->bytes:
    member=members.get(name)
    limit=BACKUP_METADATA_LIMITS[name]
    if member is None or member.size > limit or member.size < 1:
        invalid('Required recovery metadata missing/oversized: '+name)
    handle=tar.extractfile(member)
    if handle is None:invalid('Unreadable recovery metadata: '+name)
    with handle:
        value=handle.read(limit+1)
    if len(value)!=member.size or len(value)>limit:
        invalid('Truncated recovery metadata: '+name)
    return value


def _verify_backup_lock(raw:bytes,key:bytes,name:str)->dict:
    try:
        record=json.loads(raw)
        body=record['data']
        mac=record['mac']
        if not isinstance(body,dict) or not isinstance(mac,str):raise ValueError()
        serialized=json.dumps(body,ensure_ascii=False,separators=(',',':')).encode()
    except (ValueError,TypeError,KeyError,UnicodeError) as exc:
        raise ValueError('Corrupt original '+name+' lock') from exc
    if not hmac.compare_digest(hmac.new(key,serialized,hashlib.sha256).hexdigest(),mac):
        invalid('Original '+name+' lock HMAC invalid')
    return body


def verify_backup_identity(tar:tarfile.TarFile,members:dict[str,tarfile.TarInfo],
                           lock:dict,key:bytes,current_database_json:bytes)->dict:
    """Match the sealed backup to THIS installation and migration origin.

    This validates metadata, not SQL dump semantics or recoverability. Neither
    a database driver nor a signing key is consulted. No file is modified.
    """
    saved_key=_backup_metadata(tar,members,'files/storage/private/app.key')
    if len(key)!=32 or not hmac.compare_digest(saved_key,key):
        invalid('Backup belongs to a different installation HMAC key')
    install=_verify_backup_lock(_backup_metadata(tar,members,'files/storage/system/install.lock'),key,'install')
    update=_verify_backup_lock(_backup_metadata(tar,members,'files/storage/system/update.lock'),key,'update')
    if install.get('phase')!='INSTALLED' or update.get('phase')!='READY':
        invalid('Backup does not contain an installed/READY starting state')
    for field in ('install_id','schema','version'):
        if install.get(field) is None or install.get(field)!=update.get(field) or install.get(field)!=lock.get(field):
            invalid('Backup installation identity/version differs from pending upgrade: '+field)
    old_schema=install['schema']
    if not supported_source(old_schema, install.get('version')):
        invalid('Backup source schema/version unsupported or target not newer')
    version_bytes=_backup_metadata(tar,members,'files/VERSION')
    try:
        version=version_bytes.decode('ascii').strip()
        manifest=json.loads(_backup_metadata(tar,members,'files/release-manifest.json'))
        previous_cfg=json.loads(_backup_metadata(tar,members,'files/storage/private/database.json'))
        present_cfg=json.loads(current_database_json)
    except (UnicodeError,ValueError,TypeError) as exc:
        raise ValueError('Backup VERSION/manifest/database config invalid') from exc
    if version!=install['version'] or not isinstance(manifest,dict) or manifest.get('version')!=version or manifest.get('schema_version')!=old_schema:
        invalid('Backup VERSION and original manifest differ from installation lock')
    if not isinstance(previous_cfg,dict) or not isinstance(present_cfg,dict):
        invalid('Database config is not a dictionary')
    # A wrong DB target would be destroyed by a valid restoration. Host/user/
    # database/port must match the currently selected connection. Never print
    # secrets or connection details in errors or generated evidence.
    for field in ('host','database','username'):
        if not isinstance(previous_cfg.get(field),str) or not previous_cfg[field] or previous_cfg.get(field)!=present_cfg.get(field):
            invalid('Backup database target differs from current configuration')
    try:
        saved_port=int(previous_cfg.get('port',3306))
        current_port=int(present_cfg.get('port',3306))
    except (ValueError,TypeError):
        invalid('Invalid source/target database port')
    if saved_port!=current_port or not (1<=saved_port<=65535):
        invalid('Backup database port differs from current configuration')
    # Password may be intentionally rotated since the backup; the database
    # identity, not a secret's value, is the invariant we must preserve.
    return {'source_version':version,'source_schema':old_schema,
            'tables_to_remove_after_failed_update':restore_drop_tables(old_schema),
            'snapshot_identity_verified':True}


def restore_preflight(root:Path,basename:str)->dict:
    """Read-only archive + installation identity guard shared by plan/restore."""
    if not root.is_dir() or root.is_symlink():invalid('Site root is missing or linked')
    if not isinstance(basename,str) or BACKUP_NAME_PATTERN.fullmatch(basename) is None:
        invalid('Backup basename is not an updater-generated filename')
    lock,key=lock_record(root,'update')
    if lock.get('phase')!='UPDATING' or basename!=lock.get('backup_basename'):
        invalid('Not the sealed in-progress upgrade backup')
    backup_dir=root/'storage/backups'
    path=backup_dir/basename
    if backup_dir.is_symlink() or not path.is_file() or path.is_symlink():
        invalid('Backup archive missing or linked')
    expected=lock.get('backup_sha256')
    if not isinstance(expected,str) or re.fullmatch(r'[a-f0-9]{64}',expected) is None:
        invalid('Missing HMAC-protected backup checksum')
    if not hmac.compare_digest(file_sha256(path),expected):
        invalid('Backup checksum mismatch')
    current_database_path=root/'storage/private/database.json'
    if current_database_path.is_symlink() or current_database_path.stat().st_size>64*1024:
        invalid('Invalid current database configuration file')
    current_database_json=current_database_path.read_bytes()
    with tarfile.open(path,'r:gz') as tar:
        members=verify_backup_members(tar)
        return verify_backup_identity(tar,members,lock,key,current_database_json)


def restore_backup(root:Path,basename:str,ack:bool):
    if not ack:invalid("Restore requires --i-understand-data-loss")
    if os.geteuid()==0:invalid("Do not restore as root")
    # Fail closed against a foreign, stale or inconsistent recovery archive
    # BEFORE contacting MySQL or attempting any destructive table operation.
    restore_preflight(root,basename)
    body,key=lock_record(root,"update")
    path=root/"storage/backups"/basename
    mysql=shutil.which("mysql")
    if not mysql:invalid("MySQL restore CLI unavailable")
    config,dbname=cnf_file(root)
    try:
        with tarfile.open(path,"r:gz") as tar:
            files=verify_backup_members(tar)
            # Remove only tables added by a failed schema upgrade. Schema41
            # metadata-only updates add none; do not delete any original table.
            old_schema=int(body["schema"])
            new_tables=restore_drop_tables(int(body["schema"]))
            if new_tables:
                commands="SET FOREIGN_KEY_CHECKS=0;"+"".join("DROP TABLE IF EXISTS `"+t+"`;" for t in reversed(new_tables))+"SET FOREIGN_KEY_CHECKS=1;"
                subprocess.run([mysql,f"--defaults-extra-file={config}",dbname,"--execute="+commands],stderr=subprocess.PIPE,check=True,timeout=300)
            with tar.extractfile(files["database.sql"]) as dump:
                subprocess.run([mysql,f"--defaults-extra-file={config}",dbname],stdin=dump,stderr=subprocess.PIPE,check=True,timeout=300)
            # Remove only new release code files absent from the pre-update backup.
            # Never delete user storage or database data by walking arbitrary paths.
            original={n[6:] for n in files if n.startswith("files/")}
            for prefix in ("app", "public", "database", "deploy", "tools"):
                directory=root/prefix
                if directory.is_dir():
                    for f in directory.rglob("*"):
                        if f.is_file() and not f.is_symlink() and f.relative_to(root).as_posix() not in original:
                            f.unlink()
            # Restore the original code first, old locks last. No unverified paths extracted.
            for name,member in sorted(files.items()):
                if not name.startswith("files/") or name.startswith("files/storage/system/") and name.endswith(".lock"):
                    continue
                rel=name[6:]
                dest=root/rel
                dest.parent.mkdir(parents=True,exist_ok=True)
                if dest.is_symlink() or any((root/part).is_symlink() for part in list(PurePosixPath(rel).parents) if str(part)!="."):
                    invalid("Refusing symlink restore destination")
                with tar.extractfile(member) as stream:atomic_write(dest,stream.read(),0o600 if rel.startswith("storage/") else 0o644)
            for rel in ("storage/system/install.lock","storage/system/update.lock"):
                with tar.extractfile(files["files/"+rel]) as stream:atomic_write(root/rel,stream.read(),0o600)
        print("RESTORE COMPLETE: code and database restored from local backup; verify the site before permitting traffic")
    finally: config.unlink(missing_ok=True)


def main():
    a=argparse.ArgumentParser(description="KMM offline signed updater; no remote fetching")
    sub=a.add_subparsers(dest="cmd",required=True)
    v=sub.add_parser("verify")
    v.add_argument("--release",type=Path,required=True);v.add_argument("--signature",type=Path,required=True);v.add_argument("--public-key",type=Path,required=True)
    for name in ("plan","apply"):
        p=sub.add_parser(name)
        p.add_argument("--root",type=Path,required=True);p.add_argument("--release",type=Path,required=True);p.add_argument("--signature",type=Path,required=True)
        if name=="apply":p.add_argument("--confirm",action="store_true")
    r=sub.add_parser("restore")
    r.add_argument("--root",type=Path,required=True);r.add_argument("--backup-basename",required=True);r.add_argument("--i-understand-data-loss",action="store_true")
    p=sub.add_parser("restore-plan",help="read-only sealed backup/source identity preflight, no MySQL")
    p.add_argument("--root",type=Path,required=True);p.add_argument("--backup-basename",required=True)
    args=a.parse_args()
    try:
        if args.cmd=="verify":
            manifest,files=verify_archive(args.release,args.signature,args.public_key)
            print(f"VERIFIED: {manifest['version']}, {len(files)} files; Ed25519 signature + SHA256; NOT production acceptance")
        elif args.cmd in ("plan","apply"):
            invalid("Legacy Python deployment is disabled for v1.2.1 lock layout. Use root/update.php with a separately signed release.")
        elif args.cmd=="restore-plan":
            report=restore_preflight(args.root.absolute(),args.backup_basename)
            print(json.dumps({"status":"OFFLINE_PREFLIGHT_ONLY","source":report["source_version"],
                              "target":TARGET_VERSION,"tables_to_remove_count":len(report["tables_to_remove_after_failed_update"]),
                              "db_execution":False,"actual_restore_verified":False},sort_keys=True))
        else:
            invalid("Legacy Python restore is disabled for v1.2.1 root-lock layout. Do not erase the existing DB; use the sealed backup and administrator-guided recovery.")
    except Exception as e:
        print("REFUSED: "+str(e),file=sys.stderr)
        sys.exit(1)
if __name__=="__main__":main()
