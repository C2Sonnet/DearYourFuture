#!/usr/bin/env python3
"""Audited logical DB projection + immutable storage backup. No DB connection or automatic cutover."""
import argparse, fcntl, json, os, pathlib, re, shutil, tempfile
import publish as p
import storage_admin as admin
import mysql_plan

def verify(bundle, expected):
    bundle=pathlib.Path(bundle)
    if not re.fullmatch('[a-f0-9]{64}',expected) or bundle.is_symlink() or not bundle.is_dir(): raise ValueError('invalid bundle')
    actual=p.inventory(bundle); manifest=bundle/'manifest.json'
    if actual.pop('manifest.json',None)!=expected: raise ValueError('bundle manifest SHA256 mismatch')
    m=p.read(manifest)
    if m.get('schema')!=1 or m.get('files')!=actual or set(x.name for x in bundle.iterdir())!={'manifest.json','plan.json','backup'}: raise ValueError('bundle inventory mismatch')
    summary=admin.verify_backup(bundle/'backup')
    rebuilt=mysql_plan.plan(bundle/'backup/storage')
    if p.read(bundle/'plan.json')!=rebuilt or m['catalog_sha256']!=rebuilt['catalog_sha256'] or m['summary']!=summary: raise ValueError('projection mismatch')
    return {'summary':summary,'catalog_sha256':rebuilt['catalog_sha256'],'plan_sha256':actual['plan.json'],'manifest_sha256':expected,'database_accessed':False,'release_gate':'HOLD'}

def create(dest):
    dest=pathlib.Path(dest).absolute();admin.ensure_new(dest)
    mutex=p.STORE/'system/publish.mutex'
    if mutex.is_symlink() or not mutex.is_file(): raise ValueError('existing publication mutex required')
    stage=pathlib.Path(tempfile.mkdtemp(prefix='.cp-recovery-',dir=dest.parent))
    try:
        with mutex.open('r') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            plan=mysql_plan.plan();admin.backup(stage/'backup');p.atomic(stage/'plan.json',plan)
            p.atomic(stage/'manifest.json',{'schema':1,'files':p.inventory(stage),'catalog_sha256':plan['catalog_sha256'],'summary':plan['summary']})
            expected=p.filehash(stage/'manifest.json');result=verify(stage,expected)
        admin.ensure_new(dest);os.rename(stage,dest);p.syncdir(dest.parent);return result
    finally:
        if stage.exists():shutil.rmtree(stage)

def restore(bundle,expected,dest):
    bundle=pathlib.Path(bundle).absolute();dest=pathlib.Path(dest).absolute();admin.ensure_new(dest);result=verify(bundle,expected)
    stage=pathlib.Path(tempfile.mkdtemp(prefix='.cp-recovery-restore-',dir=dest.parent))
    try:
        admin.restore(bundle/'backup',stage/'storage');(stage/'storage/system/publish.mutex').touch(exist_ok=False)
        shutil.copy2(bundle/'plan.json',stage/'plan.json')
        if p.filehash(stage/'plan.json')!=result['plan_sha256'] or mysql_plan.plan(stage/'storage')!=p.read(stage/'plan.json'):raise ValueError('restored projection mismatch')
        admin.ensure_new(dest);os.rename(stage,dest);p.syncdir(dest.parent);return result
    finally:
        if stage.exists():shutil.rmtree(stage)

def main():
    a=argparse.ArgumentParser();a.add_argument('action',choices=['create','verify','restore']);a.add_argument('--bundle',type=pathlib.Path,required=True);a.add_argument('--sha256');a.add_argument('--dest',type=pathlib.Path);x=a.parse_args()
    try:
        if x.action=='create':r=create(x.bundle)
        elif x.action=='verify':r=verify(x.bundle,x.sha256 or '')
        else:
            if x.dest is None:raise ValueError('destination required')
            r=restore(x.bundle,x.sha256 or '',x.dest)
        print(json.dumps(r,ensure_ascii=False))
    except (OSError,ValueError,KeyError,TypeError,RuntimeError):a.exit(1,'Recovery bundle refused; inspect isolated files privately.\n')
if __name__=='__main__':main()
