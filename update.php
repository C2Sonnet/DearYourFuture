<?php
/**
 * キミの未来へ / KMM -- single-file offline CLI release updater (schema-preserving).
 * Place in the site root (the parent of public/), next to the downloaded ZIP.
 * PHP >= 8.4, extensions: zip, pdo_mysql, sodium. External utilities: mysqldump, mysql.
 *
 * PLAN (no filesystem/DB mutation):
 *   php update.php --zip=KimiNoMirai_v1.2.0_Release.zip --sha256=<trusted SHA256> --plan
 * APPLY (requires manifest authorization and detached Ed25519 signature):
 *   php update.php --zip=KimiNoMirai_v1.2.0_Release.zip --sha256=<trusted SHA256> \
 *       --signature=KimiNoMirai_v1.2.0_Release.sig \
 *       --pubkey=storage/private/release_public_key.hex --apply --confirm [--confirm-deletes] [--confirm-unmanaged-docs]
 *
 * Whole-database replacement is deliberately prohibited unless a verified, schema-specific
 * shadow migration with restoration of all user-owned tables is supplied. A dump is NOT
 * proof of safe recreation. This tool retains the previous database and only commits
 * explicitly authorized transactional DML after making a consistent SQL backup.
 * A DDL operation in a signed migration is permitted only under a restricted,
 * checked schema plan. MySQL DDL implicitly commits, so it requires a verified
 * full database backup and post-failure RESTORE; partial recovery retains maintenance.
 * DML executes inside a separate transaction after all DDL operations.
 * Fail-closed behavior and a full sealed DB/files backup are preferable to pretending
 * that a non-transactional migration can be rolled back. If a crash or restoration
 * fails, an UPDATING lock is retained for administrator-assisted recovery.
 *
 * FUTURE RELEASE FORMAT (release-manifest.json):
 *  {"format":"kmm-signed-release-v1","version":"1.2.0","schema_version":41,
 *   "deployment_allowed":true,"files":{"VERSION":"<sha256>",...},
 *   "migrations":[{"path":"database/migrations/releases/1.2.0.sql",
 *                  "sha256":"<sha256>","type":"transactional_dml"}]}
 * The detached signature signs EXACT release-manifest.json bytes. Its files map
 * must cover EVERY ZIP member except release-manifest.json, including documents.
 * This tool never reruns database/initial_company_data.sql or initial_research_r06.sql.
 *
 * License: CC BY-SA 4.0 (for original updater implementation).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
umask(0077);
ini_set('display_errors', 'stderr');

final class KmmCliUpdateException extends RuntimeException {}
final class KmmCliUpdater
{
    private const MAX_ZIP_BYTES = 262144000;
    private const MAX_UNPACKED = 262144000;
    private const MAX_ONE_FILE = 52428800;
    private const MAX_FILES = 2000;
    private const MAX_MANIFEST = 524288;
    private const SQL_LIMIT = 1048576;

    private string $root;
    private array $opts;
    private array $manifest = [];
    /** @var array<string,string> */
    private array $newHashes = [];
    private array $oldHashes = [];
    private array $actions = ['add'=>[], 'update'=>[], 'delete'=>[], 'keep'=>[]];
    private array $sql = [];
    private array $schemaSql = [];
    private int $oldSchema = 0;
    private bool $schemaChanged = false;
    private array $applicationsBefore = [];
    private ?array $oldCatalogue = null;
    private ?array $newCatalogue = null;
    private string $version = '';
    private string $previous = '';
    private int $schema = 0;
    private ?ZipArchive $zip = null;
    private ?PDO $pdo = null;
    private string $key = '';
    private array $installLock = [];
    private array $updateLock = [];
    private string $backupRoot = '';
    private string $dbDump = '';
    private bool $dbCommitted = false;
    private bool $dbCommitAttempted = false;
    private bool $dbTransaction = false;
    private bool $lockChanged = false;
    private bool $filesChanged = false;
    private array $writtenPaths = [];
    private array $oldModes = [];
    private string $lastStage = 'start';

    public function __construct(array $argv)
    {
        $this->root = __DIR__;
        $this->opts = $this->parse($argv);
    }
    private static function refuse(string $message): never { throw new KmmCliUpdateException($message); }
    private static function say(string $line): void { fwrite(STDOUT, $line . PHP_EOL); }
    private function parse(array $argv): array
    {
        $o = ['plan'=>false, 'apply'=>false, 'confirm'=>false, 'confirm-deletes'=>false,
              'help'=>false, 'selftest'=>false, 'db-compare'=>false, 'confirm-unmanaged-docs'=>false,'operator-apply'=>false,'offline-confirm'=>false];
        foreach (array_slice($argv, 1) as $arg) {
            if (!str_starts_with($arg, '--')) self::refuse('CLI options only; use --help');
            $parts = explode('=', substr($arg, 2), 2);
            $name = $parts[0];
            if (!in_array($name, ['zip','sha256','signature','pubkey','upgrade','plan','apply','confirm','confirm-deletes','confirm-unmanaged-docs','db-compare','help','selftest','operator-apply','offline-confirm'], true)) {
                self::refuse('Unknown option: ' . $name);
            }
            if (array_key_exists($name, $o) && !is_bool($o[$name])) self::refuse('Duplicate option: ' . $name);
            if (in_array($name, ['plan','apply','confirm','confirm-deletes','confirm-unmanaged-docs','db-compare','help','selftest','operator-apply','offline-confirm'], true)) {
                if (count($parts) !== 1 || $o[$name] === true) self::refuse('Invalid flag: ' . $name);
                $o[$name] = true;
            } else {
                if (count($parts) !== 2 || $parts[1] === '' || isset($o[$name])) self::refuse('Missing/duplicate option: ' . $name);
                $o[$name] = $parts[1];
            }
        }
        if(isset($o['upgrade'])){
            if(isset($o['zip']) || $o['plan'] || $o['apply'] || isset($o['signature']) || isset($o['pubkey']))self::refuse('--upgrade cannot be mixed with manual archive/signature/apply flags');
            $name=$o['upgrade'];$o['zip']=$name;$o['apply']=true;$o['confirm']=true;
            $o['signature']=$name.'.sig';
            $o['pubkey']='storage/private/release_public_key.hex';
        }
        if($o['operator-apply'] && (!$o['apply'] || !$o['offline-confirm'] || !isset($o['sha256']) || isset($o['upgrade']) || isset($o['signature']) || isset($o['pubkey'])))self::refuse('Local operator apply requires --apply --confirm --operator-apply --offline-confirm and trusted SHA256; no signed flags');
        if ($o['apply'] && $o['plan']) self::refuse('Choose either --plan or --apply');
        return $o;
    }
    public function run(): int
    {
        if ($this->opts['help']) { self::say($this->usage()); return 0; }
        if ($this->opts['selftest']) return $this->selftest();
        if (!$this->opts['plan'] && !$this->opts['apply']) self::refuse('Select --plan or --apply');
        if (!isset($this->opts['zip']) || (!isset($this->opts['sha256']) && !isset($this->opts['upgrade']))) self::refuse('ZIP and trusted SHA256 are required (or signed --upgrade=ZIP)');
        if (!extension_loaded('zip')) self::refuse('PHP ZipArchive extension is required');
        $zipPath = $this->rootZip((string)$this->opts['zip']);
        $checksum = isset($this->opts['sha256'])?strtolower((string)$this->opts['sha256']):null;
        if($checksum!==null){
            if (!preg_match('/\A[a-f0-9]{64}\z/', $checksum)) self::refuse('SHA256 must have 64 hexadecimal characters');
            if (!hash_equals($checksum, (string)hash_file('sha256', $zipPath))) self::refuse('Archive SHA256 mismatch');
        }
        $this->lastStage = 'archive_validation';
        $this->readArchive($zipPath);
        $this->validateExisting(false);
        $this->buildPlan();
        $this->printPlan();
        if ($this->opts['db-compare']) { $this->validateExisting(true); $this->printDatabaseComparison(); }
        if ($this->opts['plan']) { self::say('PLAN ONLY: no change to filesystem or database.'); return 0; }
        if (!$this->opts['confirm']) self::refuse('Apply requires --apply --confirm');
        if ($this->actions['delete'] !== [] && !$this->opts['confirm-deletes'] && !isset($this->opts['upgrade'])) {
            self::refuse('Deletion planned; pass --confirm-deletes only after reviewing --plan');
        }
        if(isset($this->opts['upgrade'])){
            $this->verifySignature($zipPath);
            // A single invocation is permitted; destructive files require a real interactive approval.
            if($this->actions['delete']!==[] || $this->schemaSql!==[]){
                if(!defined('STDIN') || !function_exists('stream_isatty') || !stream_isatty(STDIN))self::refuse('Destructive change requires interactive TTY approval');
                self::say('ATTENTION: this release changes/deletes managed files or changes the DB schema. Type exactly: '.$this->version);
                $answer=fgets(STDIN);
                if(!is_string($answer) || trim($answer)!==$this->version)self::refuse('Interactive approval not granted');
            }
        }
        if($this->opts['operator-apply']){
            if(($this->manifest['operator_apply_allowed']??false)!==true || $this->version!=='2.6.1' || $this->previous!=='2.6.0' || $this->schema!==41 || $this->oldSchema!==41 || $this->sql!==[] || $this->schemaSql!==[] || ($this->manifest['variant']??'')!=='Private_Deployment')self::refuse('Local operator path restricted to v2.6.1 private same-schema package; no migration SQL');
            self::say('LOCAL OPERATOR AUTHORIZATION: SHA256 checked; offline/drained service confirmed by operator; not a signed or externally tested release.');
        }else{
            if (($this->manifest['deployment_allowed'] ?? null) !== true) self::refuse('Release manifest does not authorize deployment');
            if (!isset($this->opts['signature'], $this->opts['pubkey'])) self::refuse('Detached Ed25519 signature and pinned public key are mandatory for apply');
            if(!isset($this->opts['upgrade']))$this->verifySignature($zipPath);
        }
        $lockPath=$this->root.'/storage/system/updater-cli.guard';
        if (is_link($lockPath)) self::refuse('Updater guard symlink forbidden');
        $guard=fopen($lockPath,'c');
        if (!is_resource($guard) || !flock($guard,LOCK_EX|LOCK_NB)) self::refuse('Another update is already running');
        try {
            $this->validateExisting(true);
            $this->actions=['add'=>[],'update'=>[],'delete'=>[],'keep'=>[]];$this->buildPlan();
            if($this->actions['delete']!==[]&&!$this->opts['confirm-deletes']&&!isset($this->opts['upgrade']))self::refuse('Deletion requires explicit approval');
            $this->ensureRequirements();
            $this->apply();
        } finally {flock($guard,LOCK_UN);fclose($guard);}
        self::say('SUCCESS: release ' . $this->version . ' is active. Files/DB updated; sealed backup retained.');
        return 0;
    }
    private function usage(): string
    {
        return "KMM CLI Updater (place update.php in the parent directory of public/)\n"
         . "  php update.php --upgrade=SIGNED_RELEASE.zip  # pinned key + ZIP.sig must already exist\n"
         . "  php update.php --zip=RELEASE.zip --sha256=TRUSTED_SHA256 --plan [--db-compare]\n"
         . "  php update.php --zip=RELEASE.zip --sha256=TRUSTED_SHA256 --signature=RELEASE.sig --pubkey=storage/private/release_public_key.hex --apply --confirm [--confirm-deletes] [--confirm-unmanaged-docs]\n"
         . "  php update.php --zip=PRIVATE_2.6.1.zip --sha256=TRUSTED_SHA256 --apply --confirm --confirm-deletes --operator-apply --offline-confirm\n"
         . "  php update.php --selftest\n"
         . "Note: signed channel requires deployment authorization. v2.6.1 private same-schema ZIP also supports explicit local operator approval of trusted SHA256 after service drain; no external-test claim. Failed/ambiguous commit keeps maintenance.\n";
    }
    private function rootZip(string $name): string
    {
        if ($name !== basename($name) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\.zip\z/i', $name)) {
            self::refuse('ZIP must be a basename inside the site root');
        }
        $p = $this->root . DIRECTORY_SEPARATOR . $name;
        if (!is_file($p) || is_link($p) || realpath($p) !== $p) self::refuse('ZIP not present as a regular file in the site root');
        $size = filesize($p);
        if ($size === false || $size < 100 || $size > self::MAX_ZIP_BYTES) self::refuse('ZIP size outside safety limits');
        return $p;
    }
    private static function safePath(string $name): bool
    {
        if ($name === '' || strlen($name) > 320 || $name[0] === '/' || str_contains($name, "\\") || str_contains($name, "\0") || str_contains($name, '//')) return false;
        foreach (explode('/', $name) as $part) {
            if ($part === '' || $part === '.' || $part === '..' || str_contains($part, ':') || preg_match('/[\x00-\x1f]/', $part)) return false;
        }
        if ($name === 'public/.user.ini' || $name === '.env' || str_starts_with($name, 'storage/') || str_starts_with($name, '.git/') || ($name !== '.env.example' && preg_match('/(^|\/)\.env(\.|$)/', $name))) return false;
        if (str_starts_with($name, 'resources/private/') && $name !== 'resources/private/kimi-letter-20261123.md' && !str_starts_with($name, 'resources/private/waterfall/')) return false;
        $roots = ['app/','public/','database/','resources/','tools/','docs/','deploy/','waterfall/'];
        $singles = ['update.php','VERSION','README.md','INSTALL.md','INSTALL_NEW.md','SECURITY.md','CHANGELOG.md','LICENSE','VERSIONING.md','composer.json','.gitignore','.env.example','release-manifest.json','robots.txt'];
        return in_array($name, $singles, true) || str_starts_with($name, 'waterfall/') || array_any($roots, static fn($p) => str_starts_with($name,$p));
    }
    private static function neverDelete(string $name): bool
    {
        if (!self::safePath($name)) return true;
        return $name === 'update.php' || $name === 'release-manifest.json' || str_starts_with($name,'database/initial_') ||
            str_starts_with($name, 'docs/research_snapshot_d1.0.4_R06/') || str_starts_with($name, 'resources/private/') ||
            str_starts_with($name, 'database/migrations/');
    }
    private function readArchive(string $file): void
    {
        $z = new ZipArchive();
        if ($z->open($file, ZipArchive::RDONLY) !== true) self::refuse('ZIP open failed');
        if ($z->numFiles < 2 || $z->numFiles > self::MAX_FILES) self::refuse('ZIP entry limit exceeded');
        $seen=[]; $bytes=0; $actual=[];
        for ($i=0;$i<$z->numFiles;$i++) {
            $stat=$z->statIndex($i); if (!is_array($stat)) self::refuse('ZIP stat failed');
            $name=(string)$stat['name']; $isDir=str_ends_with($name,'/');
            $path=$isDir ? substr($name,0,-1) : $name;
            if (!self::safePath($path) && !($isDir && (self::safePath($path.'/placeholder.txt') || in_array($path,['resources/private','resources/private/waterfall'],true)))) self::refuse('Unsafe ZIP path: ' . $name);
            if (isset($seen[$path])) self::refuse('Duplicate ZIP path: ' . $name);
            $seen[$path]=true;
            $opsys=0; $external=0;
            if (!$z->getExternalAttributesIndex($i,$opsys,$external)) self::refuse('ZIP entry attributes unavailable');
            $mode=($external >> 16) & 0170000;
            if ($mode !== 0 && $mode !== ($isDir ? 0040000 : 0100000)) self::refuse('ZIP symlink/device forbidden: ' . $name);
            if ($isDir) continue;
            $size=(int)$stat['size']; $bytes += $size;
            if ($size < 0 || $size > self::MAX_ONE_FILE || $bytes > self::MAX_UNPACKED) self::refuse('ZIP expansion too large');
            $actual[$name]=true;
        }
        if (!isset($actual['release-manifest.json'], $actual['VERSION'])) self::refuse('ZIP manifest/VERSION missing');
        $manifestBytes=$z->getFromName('release-manifest.json');
        if (!is_string($manifestBytes) || strlen($manifestBytes)>self::MAX_MANIFEST) self::refuse('Invalid manifest');
        $m=json_decode($manifestBytes,true,256,JSON_THROW_ON_ERROR);
        if (!is_array($m) || ($m['format']??null)!=='kmm-signed-release-v1' || !is_array($m['files']??null) || !is_string($m['version']??null) || !is_int($m['schema_version']??null)) {
            self::refuse('Invalid release metadata');
        }
        if (!preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\z/', $m['version'])) self::refuse('Invalid release version');
        $this->version=$m['version']; $this->schema=$m['schema_version'];
        $versionContent=$z->getFromName('VERSION');
        if (!is_string($versionContent) || trim($versionContent)!==$this->version) self::refuse('ZIP VERSION does not match manifest');
        $expected=$m['files'];
        foreach ($expected as $n=>$sha) {
            if (!is_string($n) || !self::safePath($n) || $n==='release-manifest.json' || !isset($actual[$n]) || !is_string($sha) || !preg_match('/\A[a-f0-9]{64}\z/', $sha)) {
                self::refuse('Manifest path/hash invalid: ' . (string)$n);
            }
        }
        foreach ($actual as $n=>$_) {
            if ($n === 'release-manifest.json') continue;
            $stream=$z->getStream($n); if (!is_resource($stream)) self::refuse('Cannot read ZIP member: '.$n);
            $ctx=hash_init('sha256');
            while (!feof($stream)) {
                $part=fread($stream, 65536); if ($part===false) {fclose($stream);self::refuse('ZIP member read error: '.$n);}
                if ($part==='') { if (!feof($stream)) {fclose($stream);self::refuse('ZIP member stalled');} break; }
                hash_update($ctx,$part);
            }
            fclose($stream);
            $got=hash_final($ctx);
            if (isset($expected[$n]) && !hash_equals($expected[$n],$got)) self::refuse('ZIP member hash mismatch: '.$n);
            $this->newHashes[$n]=$got;
        }
        $extra=array_diff(array_keys($this->newHashes),array_keys($expected));
        if ($extra!==[]) self::say('NOTICE: ' . count($extra) . ' ZIP files are not listed in manifest (apply will refuse).');
        $this->zip=$z; $this->manifest=$m;
        $this->validateMigrationList();
    }
    private function validateMigrationList(): void
    {
        $list=$this->manifest['migrations']??[];
        if (!is_array($list) || !array_is_list($list) || count($list)>24) self::refuse('Invalid SQL migrations list');
        $seen=[];
        foreach ($list as $entry) {
            if (!is_array($entry) || !in_array($entry['type']??null,['transactional_dml','schema_ddl'],true)) self::refuse('Unsupported SQL migration type');
            $path=$entry['path']??null; $sha=$entry['sha256']??null;
            if (!is_string($path) || !preg_match('~\Adatabase/migrations/releases/[A-Za-z0-9._-]+\.sql\z~',$path) || !is_string($sha) || !isset($this->newHashes[$path]) || !hash_equals($this->newHashes[$path],$sha)) {
                self::refuse('SQL update must be inside the verified archive and match its hash');
            }
            if (isset($seen[$path])) self::refuse('Duplicate SQL migration');
            $seen[$path]=true;
            $raw=$this->zip?->getFromName($path);
            if (!is_string($raw) || strlen($raw)>self::SQL_LIMIT) self::refuse('SQL migration oversized/unreadable');
            if($entry['type']==='schema_ddl'){
                foreach(self::parseSchemaSql($raw) as $sql)$this->schemaSql[]=['file'=>$path]+$sql;
            }else{
                foreach(self::parseTransactionalSql($raw) as $sql)$this->sql[]=['file'=>$path,'statement'=>$sql];
            }
        }
    }
    /** Deliberately rejects comments, DDL, stored procedures, implicit commits and multi-statements. */
    public static function parseTransactionalSql(string $raw): array
    {
        if (str_contains($raw, "\0") || preg_match('//u', $raw) !== 1) self::refuse('Invalid SQL encoding');
        if (preg_match('~--|/\*|\*/|#|\\|\\||\b(?:CREATE|ALTER|DROP|TRUNCATE|RENAME|GRANT|REVOKE|COMMIT|ROLLBACK|BEGIN|START|LOCK|UNLOCK|CALL|EXECUTE|PREPARE|DEALLOCATE|LOAD|OUTFILE|INFILE|DELIMITER|USE|HANDLER|FLUSH|ANALYZE|OPTIMIZE|REPAIR)\b~i', $raw)) {
            self::refuse('SQL contains unsupported statement/comment/operator');
        }
        $out=[];$buffer='';$quote='';$escape=false;$length=strlen($raw);
        for($i=0;$i<$length;$i++){
            $c=$raw[$i];
            if ($quote!=='') {
                $buffer.=$c;
                if ($escape) {$escape=false;continue;}
                if ($c==='\\') {$escape=true;continue;}
                if ($c===$quote) {
                    if ($i+1<$length && $raw[$i+1]===$quote) {$buffer.=$raw[++$i];}
                    else $quote='';
                }
                continue;
            }
            if ($c==='\''||$c==='"'||$c==='`') {$quote=$c;$buffer.=$c;continue;}
            if ($c===';') { $s=trim($buffer);$buffer='';if($s!=='')$out[]=self::assertDml($s);continue; }
            $buffer.=$c;
        }
        if ($quote!=='') self::refuse('Unterminated SQL string');
        $s=trim($buffer); if ($s!=='') $out[]=self::assertDml($s);
        if (count($out)>256) self::refuse('Too many SQL statements');
        return $out;
    }
    /** Restrictive schema migration grammar; no stored routines, cross-database targets, or hidden SQL. */
    public static function parseSchemaSql(string $raw):array {
        if(str_contains($raw,"\0") || preg_match('//u',$raw)!==1 ||
           preg_match('~--|/\*|\*/|#|\\|\\|;\s*;|\b(?:FOREIGN_KEY_CHECKS|GRANT|REVOKE|TRUNCATE|RENAME|DEFINER|DELIMITER|TRIGGER|PROCEDURE|FUNCTION|EVENT|DATABASE|SCHEMA|USE|LOAD|OUTFILE|INFILE|LOCK|UNLOCK|COMMIT|ROLLBACK|BEGIN|START)\b~i',$raw))self::refuse('Unsafe schema migration content');
        $segments=[];$buf='';$quote='';$escape=false;
        for($i=0,$n=strlen($raw);$i<$n;$i++){
            $c=$raw[$i];
            if($quote!==''){
                $buf.=$c;
                if($escape){$escape=false;continue;}
                if($c==='\\'){$escape=true;continue;}
                if($c===$quote){if($i+1<$n&&$raw[$i+1]===$quote)$buf.=$raw[++$i];else $quote='';}
                continue;
            }
            if($c==='\''||$c==='"'||$c==='`'){$quote=$c;$buf.=$c;continue;}
            if($c===';'){if(trim($buf)!=='')$segments[]=trim($buf);$buf='';continue;}
            $buf.=$c;
        }
        if($quote!=='')self::refuse('Unterminated schema SQL literal');
        if(trim($buf)!=='')$segments[]=trim($buf);
        if(!$segments || count($segments)>24)self::refuse('Schema migration statement count invalid');
        $result=[];
        foreach($segments as $sql){
            $flat=preg_replace('/\s+/u',' ',$sql);
            $verb='';$table='';
            if(preg_match('/\ACREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([A-Za-z][A-Za-z0-9_]{0,63})`?\s*\(/i',$flat,$m)){
                $verb='create';$table=$m[1];
                if(!preg_match('/\bENGINE\s*=\s*InnoDB\b/i',$flat))self::refuse('New tables must explicitly use InnoDB');
            }elseif(preg_match('/\AALTER\s+TABLE\s+`?([A-Za-z][A-Za-z0-9_]{0,63})`?\s+ADD\s+(?:COLUMN\s+)?`?[A-Za-z][A-Za-z0-9_]{0,63}`?\s+/i',$flat,$m)){
                $verb='add_column';$table=$m[1];
            }elseif(preg_match('/\AALTER\s+TABLE\s+`?([A-Za-z][A-Za-z0-9_]{0,63})`?\s+(?:MODIFY|DROP)\s+(?:COLUMN\s+)?`?[A-Za-z][A-Za-z0-9_]{0,63}`?(?:\s+|$)/i',$flat,$m)){
                $verb='destructive_alter';$table=$m[1];
            }elseif(preg_match('/\ADROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?`?([A-Za-z][A-Za-z0-9_]{0,63})`?\s*\z/i',$flat,$m)){
                $verb='drop';$table=$m[1];
            }else self::refuse('Schema migration operation is not on the approved CREATE/ALTER/DROP TABLE list');
            if(!preg_match('/\A[A-Za-z][A-Za-z0-9_]{0,63}\z/D',$table))self::refuse('Invalid table identifier');
            if(preg_match('/\b(?:DROP\s+DATABASE|REFERENCES\s+`?[^`\s()]+\.|INSERT\s+INTO|DELETE\s+FROM|UPDATE\s+|SELECT\s+|AS\s+SELECT|LIKE\s+|DATA\s+DIRECTORY|INDEX\s+DIRECTORY)\b/i',$flat))self::refuse('Unsupported schema action');
            // ALTER ADD/MODIFY/DROP may contain only ONE column operation. The
            // extra comma action, including MODIFY ... , DROP ..., is forbidden.
            if($verb==='add_column'||$verb==='destructive_alter'){
                if(preg_match('/\b(?:ADD|MODIFY|DROP|CHANGE|RENAME|ALGORITHM|LOCK)\b/i',substr($flat,strlen($m[0]))))
                    self::refuse('Compound column alterations are not supported');
            }
            $result[]=['statement'=>$sql,'operation'=>$verb,'table'=>$table];
        }
        return $result;
    }
    /** Database preflight for explicit migration. Destructive schema changes only on EMPTY unreferenced tables. */
    private function checkSchemaOperations():void {
        if(!$this->schemaSql)return;
        if($this->schema<=$this->oldSchema)self::refuse('Schema DDL requires an incremented schema_version');
        $protected=['installation_state','users','user_roles','roles','application_tracks','application_events','application_audit',
            'messages','message_recipients','audit_logs','auth_tokens','user_profiles','seed_entity_states','seed_entity_overlays','system_settings'];
        foreach($this->schemaSql as $step){
            $table=$step['table'];$op=$step['operation'];
            $stmt=$this->pdo?->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $stmt?->execute([$table]);$engine=$stmt?->fetchColumn();
            if($op==='create'){
                if($engine!==false)self::refuse('Schema CREATE target already exists: '.$table);
                continue;
            }
            if($engine===false||strcasecmp((string)$engine,'InnoDB')!==0)self::refuse('Schema modification target missing or not InnoDB: '.$table);
            if(in_array($op,['drop','destructive_alter'],true)){
                if(in_array($table,$protected,true))self::refuse('Protected table cannot be destructively altered: '.$table);
                $rows=(int)$this->pdo?->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
                if($rows!==0)self::refuse('Destructive schema migration requires an empty table: '.$table);
                $fk=$this->pdo?->prepare('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME=?');
                $fk?->execute([$table]);
                if((int)$fk?->fetchColumn()!==0)self::refuse('Referenced table cannot be removed/altered: '.$table);
            }
        }
    }
    /**
     * Restrict automatic DML to single-table row operations. A signed package is
     * necessary, but signing alone must not authorize JOIN-based writes to user data.
     * UPDATE/DELETE without WHERE and INSERT ... SELECT are deliberately unsupported.
     */
    private static function assertDml(string $sql): string
    {
        $flat=trim(preg_replace('/\s+/u',' ',$sql)??$sql);
        if (preg_match('/\b(?:SELECT|JOIN|USING|UNION|WITH|RECURSIVE|INFORMATION_SCHEMA|PERFORMANCE_SCHEMA|LOAD_FILE|OUTFILE|INFILE|SLEEP|BENCHMARK|FOREIGN_KEY_CHECKS|SQL_SAFE_UPDATES|TRIGGER|PROCEDURE|FUNCTION)\b/i',$flat)
            || str_contains($flat,'@')) self::refuse('Complex or cross-table DML is not supported');
        if (preg_match('/\AINSERT\s+INTO\s+`?([A-Za-z][A-Za-z0-9_]{0,63})`?\s*(?:\([^;]*?\)\s*)?VALUES\s*\(/i',$flat,$m)) {
            $op='insert';
        } elseif (preg_match('/\AUPDATE\s+`?([A-Za-z][A-Za-z0-9_]{0,63})`?\s+SET\s+.+\s+WHERE\s+.+\z/is',$flat,$m)) {
            $op='update';
        } elseif (preg_match('/\ADELETE\s+FROM\s+`?([A-Za-z][A-Za-z0-9_]{0,63})`?\s+WHERE\s+.+\z/is',$flat,$m)) {
            $op='delete';
        } else self::refuse('Only single-table INSERT VALUES, UPDATE ... WHERE or DELETE ... WHERE are allowed');
        $table=strtolower($m[1]);
        $permitted=['companies','jobs','brands','sources','company_locations','job_locations','company_sources','business_change_events','project_seed_records'];
        if(!in_array($table,$permitted,true) && !str_starts_with($table,'research_'))
            self::refuse('User-owned/security table is excluded from release DML');
        if($op==='delete' && in_array($table,['companies','jobs'],true))
            self::refuse('Hard deletion of companies/jobs is forbidden: archive instead');
        if($op==='insert' && preg_match('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\s+(.+)\z/is',$flat,$onDuplicate)){
            if(preg_match('/(?:\A|,)\s*`?(?:id|company_id|job_id|seed_key)`?\s*=/i',$onDuplicate[1]))
                self::refuse('Immutable identity may not be changed by INSERT duplicate handling');
        }
        if($op==='update') {
            // Stable object identities/ownership may not be reassigned by a migration.
            $set=trim((string)preg_replace('/\AUPDATE\s+`?[A-Za-z][A-Za-z0-9_]{0,63}`?\s+SET\s+/i','',$flat));
            $set=preg_split('/\s+WHERE\s+/i',$set,2)[0]??'';
            if(preg_match('/(?:\A|,)\s*`?(?:id|company_id|job_id|seed_key)`?\s*=/i',$set))
                self::refuse('Immutable identity may not be changed by automatic migration');
        }
        return $sql;
    }
    private function verifySignature(string $zipPath): void
    {
        if (!extension_loaded('sodium')) self::refuse('Sodium extension required');
        $sigName=(string)$this->opts['signature'];
        if ($sigName!==basename($sigName) || !preg_match('/\A[A-Za-z0-9._-]+\.sig\z/i',$sigName)) self::refuse('Signature filename invalid');
        $signaturePath=$this->root.'/'.$sigName;
        $keyRel=(string)$this->opts['pubkey'];
        if (!preg_match('~\Astorage/private/[A-Za-z0-9_.-]+\z~',$keyRel)) self::refuse('Public key must be pinned under storage/private');
        $pubPath=$this->root.'/'.$keyRel;
        if (!is_file($signaturePath) || is_link($signaturePath) || !is_file($pubPath) || is_link($pubPath)) self::refuse('Signature/public key missing');
        $sig=file_get_contents($signaturePath);$key=file_get_contents($pubPath);
        if (!is_string($sig) || !is_string($key)) self::refuse('Signature/public key unreadable');
        if (strlen(trim($sig))===128 && ctype_xdigit(trim($sig))) $sig=hex2bin(trim($sig));
        if (strlen(trim($key))===64 && ctype_xdigit(trim($key))) $key=hex2bin(trim($key));
        if (!is_string($key)||strlen($key)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || !is_string($sig)||strlen($sig)!==SODIUM_CRYPTO_SIGN_BYTES) self::refuse('Invalid Ed25519 signature/key length');
        $manifest=$this->zip?->getFromName('release-manifest.json');
        if (!is_string($manifest) || !sodium_crypto_sign_verify_detached($sig,$manifest,$key)) self::refuse('Release signature validation failed');
        if (array_diff(array_keys($this->newHashes),array_keys($this->manifest['files']),['release-manifest.json'])!==[]) {
            self::refuse('All ZIP files must be covered by signed release-manifest files');
        }
        if (($this->manifest['signature_status']??'UNSIGNED')==='UNSIGNED') self::refuse('Manifest is explicitly marked unsigned');
        self::say('Signature verified against pinned local Ed25519 public key.');
    }
    private function existingLockFile(string $name):string
    {
        if (!in_array($name,['install','update'],true)) self::refuse('Unknown lock file');
        $current=$this->root.'/'.$name.'.lock';
        if (is_file($current)) return $current;
        return $this->root.'/storage/system/'.$name.'.lock';
    }
    private function readLock(string $name): array
    {
        $p=$this->existingLockFile($name);
        $bytes=@file_get_contents($p);
        if (!is_string($bytes) || strlen($bytes)>65536) self::refuse('Installation lock missing/too large');
        $obj=json_decode($bytes,true,64,JSON_THROW_ON_ERROR);
        if (!is_array($obj) || !is_array($obj['data']??null) || !is_string($obj['mac']??null)) self::refuse('Invalid installation lock');
        $canon=json_encode($obj['data'],JSON_THROW_ON_ERROR);
        if (!hash_equals(hash_hmac('sha256',$canon,$this->key),$obj['mac'])) self::refuse('Installation lock integrity failure');
        return $obj['data'];
    }
    private function validateExisting(bool $withDatabase): void
    {
        if (!is_dir($this->root.'/public') || !is_dir($this->root.'/app') || !is_file($this->root.'/VERSION')) self::refuse('Updater must be located in the installed site root');
        if (file_exists($this->root.'/storage/system/install.incomplete')) self::refuse('Partial installation detected: do not update; use recovery procedure');
        $this->key=(string)@file_get_contents($this->root.'/storage/private/app.key');
        if (strlen($this->key)!==32) self::refuse('Installation key missing or invalid');
        $this->installLock=$this->readLock('install');
        $this->updateLock=$this->readLock('update');
        if (($this->installLock['phase']??null)!=='INSTALLED' || ($this->updateLock['phase']??null)!=='READY') self::refuse('Installation is not in READY state');
        foreach (['install_id','schema','version'] as $f) {
            if (($this->installLock[$f]??null)!==($this->updateLock[$f]??null)) self::refuse('Install/update locks disagree');
        }
        $this->previous=(string)($this->installLock['version']??'');
        $this->oldSchema=(int)($this->installLock['schema']??0);
        $oldVersion=trim((string)file_get_contents($this->root.'/VERSION'));
        if ($oldVersion!==$this->previous || version_compare($this->version,$this->previous,'<=') || !is_int($this->installLock['schema']??null) || $this->schema<$this->oldSchema || ($this->schema!==$this->oldSchema && $this->schemaSql===[])) {
            self::refuse('Version ordering or schema compatibility failure (no downgrade/reinstall/schema DDL)');
        }
        $oldPath=$this->root.'/release-manifest.json';
        $old=json_decode((string)@file_get_contents($oldPath),true,256,JSON_THROW_ON_ERROR);
        if (!is_array($old) || ($old['version']??null)!==$this->previous || ($old['schema_version']??null)!==$this->oldSchema || !is_array($old['files']??null)) self::refuse('Installed manifest mismatch');
        $this->oldHashes=[];
        foreach ($old['files'] as $path=>$h) {
            if (!is_string($path) || !self::safePath($path) || !is_string($h) || !preg_match('/\A[a-f0-9]{64}\z/',$h)) self::refuse('Invalid installed manifest entry');
            $this->oldHashes[$path]=$h;
        }
        if (!$withDatabase) return;
        if (!extension_loaded('pdo_mysql')) self::refuse('PDO MySQL extension is required');
        if (is_link($this->root.'/storage/private/database.json')) self::refuse('Database config is linked');
        $cfg=json_decode((string)@file_get_contents($this->root.'/storage/private/database.json'),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($cfg)) self::refuse('Invalid DB config');
        $host=$cfg['host']??null; $db=$cfg['database']??null; $port=$cfg['port']??3306;
        if (!is_string($host) || !preg_match('/\A[A-Za-z0-9_.:-]{1,253}\z/',$host) || !is_string($db) || !preg_match('/\A[A-Za-z0-9_]{1,64}\z/',$db) || !is_numeric($port) || (int)$port<1 || (int)$port>65535) self::refuse('Invalid database connection metadata');
        $dsn='mysql:host='.$host.';port='.(int)$port.';dbname='.$db.';charset=utf8mb4';
        $this->pdo=new PDO($dsn,(string)($cfg['username']??''),(string)($cfg['password']??''),[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_TIMEOUT=>5,
        ]);
        $q=$this->pdo->query('SELECT install_id, schema_version, status, release_version FROM installation_state WHERE id=1')->fetch();
        if (!is_array($q) || $q['install_id']!==($this->installLock['install_id']??null) || (int)$q['schema_version']!==$this->oldSchema || $q['status']!=='installed' || $q['release_version']!==$this->previous) self::refuse('DB installation_state differs from signed installation locks');
        $dev=(int)$this->pdo->query("SELECT COUNT(*) FROM users u JOIN user_roles r ON r.user_id=u.id WHERE u.status='active' AND r.role_code='DEVELOPER'")->fetchColumn();
        if ($dev<1) self::refuse('No active DEVELOPER account in DB');
    }
    /** Store the verified latest research master without erasing R06 canonical tables or any user edits.
     * This is one transactional upsert with the release version update, not a destructive DB rebuild.
     */
    private function syncSignedResearchSnapshot():void
    {
        $spec=$this->manifest['catalogue_sync']??null;
        if($spec===null)return;
        if(!is_array($spec)||!is_string($spec['path']??null)||!is_string($spec['sha256']??null)
            ||!is_int($spec['companies']??null)||!is_int($spec['jobs']??null))self::refuse('Invalid research sync specification');
        $path=$spec['path'];$sha=$spec['sha256'];
        if(!preg_match('~\Aresources/research/r07-current/COMPANY_JOB_RELATIONS_d1\.0\.4_R07_BATCH[0-9]+_[0-9]+\.json\z~',$path)
            ||!isset($this->newHashes[$path])||!hash_equals($this->newHashes[$path],$sha))self::refuse('Research master is not signed and pinned');
        $raw=$this->zip?->getFromName($path);
        if(!is_string($raw)||strlen($raw)>5000000||!hash_equals(hash('sha256',$raw),$sha))self::refuse('Research master integrity failed');
        $data=json_decode($raw,true,128,JSON_THROW_ON_ERROR);
        if(!is_array($data)||count($data['companies']??[])!==$spec['companies']||count($data['jobs']??[])!==$spec['jobs'])
            self::refuse('Research count mismatch');
        $companies=[];$jobs=[];
        foreach($data['companies'] as $c){$id=$c['company_id']??null;
            if(!is_string($id)||!preg_match('/\ACO-[0-9]{4}\z/D',$id)||isset($companies[$id]))self::refuse('Duplicate/malformed company research ID');
            $companies[$id]=true;
        }
        foreach($data['jobs'] as $j){$id=$j['job_id']??null;$company=$j['company_id']??null;
            if(!is_string($id)||!preg_match('/\AJOB-[0-9]{4}\z/D',$id)||isset($jobs[$id])||!isset($companies[$company]))self::refuse('Duplicate/malformed job research ID');
            $jobs[$id]=true;
        }
        $this->pdo?->prepare("INSERT INTO system_settings(setting_key,value_json,updated_at) VALUES (?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=UTC_TIMESTAMP()")
            ->execute(['research.catalogue.current_snapshot', $raw]);
        $this->pdo?->prepare("INSERT INTO system_settings(setting_key,value_json,updated_at) VALUES (?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=UTC_TIMESTAMP()")
            ->execute(['research.catalogue.current_sha256',json_encode($sha,JSON_THROW_ON_ERROR)]);
        self::say('Research DB snapshot updated safely: '.count($companies).' companies, '.count($jobs).' jobs. User-owned canonical records not erased.');
    }
    /** Read-only count and structure comparison; never mistakes a research snapshot for installed database rows. */
    private function printDatabaseComparison():void
    {
        $db=$this->pdo;
        if (!$db instanceof PDO) self::refuse('Database not available for comparison');
        $result=['installed_release'=>$this->previous,'target_release'=>$this->version,'schema'=>$this->schema,
                 'schema_replacement'=>'BLOCKED_WITHOUT_VERIFIED_SHADOW_DB_MIGRATION',
                 'protected_data_policy'=>'KEEP accounts, proposals, messages, applications, activity, overlays, audit and uploaded files; never TRUNCATE production DB'];
        $tables=['users','companies','jobs','messages','message_recipients','edit_proposals','application_tracks','audit_logs','seed_entity_states'];
        foreach ($tables as $name) {
            $check=$db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $check->execute([$name]);
            if ((int)$check->fetchColumn()!==1) { $result['tables'][$name]=['present'=>false];continue; }
            $count=(int)$db->query('SELECT COUNT(*) FROM `'.$name.'`')->fetchColumn();
            $result['tables'][$name]=['present'=>true,'rows'=>$count];
        }
        $research=null;
        foreach(array_keys($this->newHashes) as $path) {
            if (preg_match('~\Aresources/research/r07-current/COMPANY_JOB_RELATIONS_d1\.0\.4_R07_BATCH[0-9]+_[0-9]+\.json\z~',$path)) {
                $research=$this->zip?->getFromName($path);break;
            }
        }
        if(is_string($research)){
            $master=json_decode($research,true,128,JSON_THROW_ON_ERROR);
            $result['source_catalogue']=['companies'=>count($master['companies']??[]),'jobs'=>count($master['jobs']??[]),
                                         'note'=>'R07 catalogue is read-only research data; not equivalent to installed R06 canonical rows'];
        }
        self::say('DB COMPARISON (READ ONLY): '.json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }
    private function buildPlan(): void
    {
        $this->newHashes['release-manifest.json']=hash('sha256',(string)$this->zip?->getFromName('release-manifest.json'));
        foreach ($this->newHashes as $path=>$digest) {
            // update.php is itself a managed site-root file; staged replacement is atomic.
            $where=$this->root.'/'.$path;
            $this->checkTarget($path);
            if (is_file($where) && !is_link($where) && hash_equals((string)hash_file('sha256',$where),$digest)) $this->actions['keep'][]=$path;
            elseif (is_file($where)) $this->actions['update'][]=$path;
            else $this->actions['add'][]=$path;
        }
        foreach ($this->oldHashes as $path=>$sha) {
            if (!isset($this->newHashes[$path]) && !self::neverDelete($path) && is_file($this->root.'/'.$path)) {
                $this->checkTarget($path);
                $this->actions['delete'][]=$path;
            }
        }
        sort($this->actions['add']);sort($this->actions['update']);sort($this->actions['delete']);
    }
    private function printPlan(): void
    {
        $unmanagedDocs=array_values(array_filter($this->actions['update'], fn($p)=>!isset($this->oldHashes[$p]) && self::untrackedDocumentation($p)));
        $info=['current'=>$this->previous,'target'=>$this->version,'schema'=>$this->schema,'files'=>array_map('count',$this->actions),
               'untracked_document_updates_requiring_confirmation'=>$unmanagedDocs,
               'sql_statements'=>count($this->sql),'schema_statements'=>count($this->schemaSql), 'deployment_allowed'=>$this->manifest['deployment_allowed']??false,
               'operator_apply_allowed'=>$this->manifest['operator_apply_allowed']??false, 'manifest_coverage'=>count($this->newHashes)-1===count($this->manifest['files'])];
        self::say(json_encode($info,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        foreach (['add','update','delete'] as $kind) {
            foreach ($this->actions[$kind] as $path) self::say(strtoupper($kind).' '.$path);
        }
    }
    private function checkTarget(string $path): void
    {
        if (!self::safePath($path)) self::refuse('Forbidden target path: '.$path);
        $pieces=explode('/',$path);$at=$this->root;
        foreach ($pieces as $part) {
            $at.='/'.$part;
            if (is_link($at)) self::refuse('Symlink found in target path: '.$path);
        }
        if (file_exists($at) && !is_file($at)) self::refuse('Target is not a regular file: '.$path);
    }
    private static function untrackedDocumentation(string $path): bool
    {
        return in_array($path,['LICENSE','VERSIONING.md','.gitignore','.env.example'],true) || str_starts_with($path,'waterfall/');
    }
    private function ensureRequirements(): void
    {
        if($this->opts['operator-apply']){
            if(!extension_loaded('mbstring'))self::refuse('mbstring is required for R07 synchronization');
            foreach(['storage','storage/private','storage/system','storage/private/app.key','storage/private/database.json','install.lock','update.lock'] as $p)if(is_link($this->root.'/'.$p))self::refuse('Linked private path forbidden: '.$p);
            if(function_exists('posix_geteuid') && fileowner($this->root.'/update.lock')!==posix_geteuid())self::refuse('Run as the existing lock owner / site PHP account, not root');
            if($this->binary('tar')===null)self::refuse('tar required for full protected site snapshot');
            $bad=$this->pdo?->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'")->fetchColumn();
            if((int)$bad!==0)self::refuse('Non-InnoDB tables prevent transactional update');
            $triggers=$this->pdo?->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()")->fetchColumn();
            if((int)$triggers!==0)self::refuse('Database triggers require separate review before local apply');
            foreach(['install','update'] as $name)if($this->existingLockFile($name)!==$this->root.'/'.$name.'.lock')self::refuse('Local apply requires root install/update locks');
        }
        if (!function_exists('proc_open') || !is_callable('proc_open')) self::refuse('Process launch is unavailable');
        foreach (['mysqldump','mysql'] as $binary) {
            if ($this->binary($binary)===null) self::refuse($binary.' is required for sealed DB backup/recovery');
        }
        if (!is_writable($this->root) || !is_writable($this->root.'/storage/system')) self::refuse('Site/system directory not writable');
        foreach (array_merge($this->actions['update'],$this->actions['delete']) as $path) {
            if (isset($this->oldHashes[$path]) && !hash_equals($this->oldHashes[$path],(string)hash_file('sha256',$this->root.'/'.$path))) self::refuse('Local managed file changed unexpectedly: '.$path);
            if (!isset($this->oldHashes[$path]) && $path!=='release-manifest.json') {
                if (!self::untrackedDocumentation($path) || !$this->opts['confirm-unmanaged-docs']) self::refuse('Unmanaged file would be overwritten: '.$path.' (document-only override: --confirm-unmanaged-docs)');
            }
        }
        foreach ($this->actions['keep'] as $path) {
            if (isset($this->oldHashes[$path]) && !hash_equals($this->oldHashes[$path],(string)hash_file('sha256',$this->root.'/'.$path))) self::refuse('Managed file unexpectedly changed: '.$path);
        }
        foreach ($this->actions['add'] as $path) {
            if (isset($this->oldHashes[$path])) self::refuse('Previously managed file missing: '.$path);
        }
        $missing=array_diff(array_keys($this->newHashes),array_keys($this->manifest['files']),['release-manifest.json']);
        if ($missing!==[]) self::refuse('Signed manifest does not cover ZIP files');
        if (is_link($this->root.'/storage') || is_link($this->root.'/storage/backups')) self::refuse('Linked storage/backups forbidden');
        $backups=$this->root.'/storage/backups';
        if (is_dir($backups) && ((fileperms($backups)&0077)!==0)) self::refuse('Private backup directory is accessible to other users');
    }
    private function binary(string $name): ?string
    {
        foreach (explode(PATH_SEPARATOR,(string)getenv('PATH')) as $dir) {
            if ($dir==='')continue;$candidate=rtrim($dir,'/').'/'.$name;
            if(!str_starts_with($dir,'/'))continue; $real=realpath($candidate);if(is_string($real)&&is_file($real)&&is_executable($real))return $real;
        }
        return null;
    }
    private function saveLock(string $name,array $record): void
    {
        $body=json_encode($record,JSON_THROW_ON_ERROR);
        $outer=json_encode(['data'=>$record,'mac'=>hash_hmac('sha256',$body,$this->key)],JSON_THROW_ON_ERROR);
        self::atomic($this->root.'/'.$name.'.lock',$outer,0600);
    }
    private static function atomic(string $file,string $bytes,int $mode): void
    {
        $temp=$file.'.kmm.'.bin2hex(random_bytes(7));
        if (file_put_contents($temp,$bytes,LOCK_EX)!==strlen($bytes)) self::refuse('Atomic file write failed');
        chmod($temp,$mode);
        if (!rename($temp,$file)) { @unlink($temp); self::refuse('Atomic file replace failed'); }
    }
    private function privateDataDigest():array {
        $tables=['users','user_roles','user_profiles','user_guide_steps','user_saved_filters','user_favorite_companies','user_company_notes','messages','message_recipients','edit_proposals','application_tracks','application_audit','audit_logs','companies','jobs','system_settings','application_events'];
        $result=[];
        foreach($tables as $table){
            $columns=$this->pdo->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
            $pk=[];foreach($columns as $column)if($column['Key']==='PRI')$pk[]='`'.str_replace('`','``',$column['Field']).'`';
            if(!$pk)self::refuse('Protected table lacks primary key: '.$table);
            $q=$this->pdo->query('SELECT * FROM `'.$table.'` ORDER BY '.implode(',',$pk));$ctx=hash_init('sha256');$count=0;
            while($row=$q->fetch(PDO::FETCH_ASSOC)){hash_update($ctx,serialize($row)."\n");$count++;}
            $q->closeCursor();$result[$table]=['rows'=>$count,'sha256'=>hash_final($ctx)];
        }
        return $result;
    }
    private function apply(): void
    {
        $this->lastStage='begin_backup';
        $base=$this->root.'/storage/backups';
        if (!is_dir($base) && !mkdir($base,0700,true)) self::refuse('Cannot create protected backup directory');
        $this->backupRoot=$base.'/php-update-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(8));
        if (!mkdir($this->backupRoot,0700)) self::refuse('Cannot reserve update backup');
        $oldInstall=(string)file_get_contents($this->existingLockFile('install'));
        $oldUpdate=(string)file_get_contents($this->existingLockFile('update'));
        file_put_contents($this->backupRoot.'/install.lock.original',$oldInstall,LOCK_EX);
        file_put_contents($this->backupRoot.'/update.lock.original',$oldUpdate,LOCK_EX);
        chmod($this->backupRoot.'/install.lock.original',0600); chmod($this->backupRoot.'/update.lock.original',0600);
        try {
            if($this->opts['operator-apply']){
                $this->lastStage='full_site_backup';
                $tar=$this->backupRoot.'/site.before.tar.gz';
                $this->subprocess([$this->binary('tar'),'--exclude=./storage/backups','--exclude=./'.basename((string)$this->opts['zip']),'-czf',$tar,'-C',$this->root,'.'],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['pipe','w']]);
                chmod($tar,0600);
                $this->subprocess([$this->binary('tar'),'-tzf',$tar],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['pipe','w']]);
                self::atomic($this->backupRoot.'/site.before.sha256',hash_file('sha256',$tar)."\n",0600);
            }
            // Maintenance barrier BEFORE snapshot: all web writes must stop.
            $this->saveLock('update',array_replace($this->updateLock,['phase'=>'UPDATING','started_utc'=>gmdate('c')]));
            $this->lockChanged=true;
            $this->lastStage='db_backup';
            $this->dbDump=$this->backupRoot.'/database.before.sql';
            $this->dumpDb();
            $dbSha=(string)hash_file('sha256',$this->dbDump);
            $this->saveLock('update',array_replace($this->updateLock,['phase'=>'UPDATING','backup_sha256'=>$dbSha,
              'backup_basename'=>basename($this->backupRoot),'started_utc'=>gmdate('c')]));
            $this->lastStage='preflight_schema';
            $this->checkSchemaOperations();
            // Capture the previous approved research JSON before replacing site files.
            foreach(glob($this->root.'/resources/research/r07-current/COMPANY_JOB_RELATIONS_*.json')?:[] as $candidate){
                if(!is_file($candidate)||is_link($candidate))continue;
                $oldData=json_decode((string)file_get_contents($candidate),true,128);
                if(is_array($oldData)){$this->oldCatalogue=$oldData;break;}
            }
            $spec=$this->manifest['catalogue_sync']??null;
            if(is_array($spec)&&is_string($spec['path']??null)){
                $newData=$this->zip?->getFromName($spec['path']);
                if(is_string($newData)){$value=json_decode($newData,true,128);if(is_array($value))$this->newCatalogue=$value;}
            }
            $this->lastStage='stage_and_file_backup';
            $this->stageAndCopyOriginals();
            $this->lastStage='lint_staged_php';
            $this->lintStagedPhp();
            $this->lastStage='apply_files';
            $this->applyFiles();
            $this->lastStage='verify_deployed_files';
            $this->verifyAppliedFiles();
            $this->lastStage='apply_schema';
            foreach($this->schemaSql as $step){
                // Even a failed DDL may have committed: activate snapshot restoration first.
                $this->schemaChanged=true;
                try{$this->pdo?->exec($step['statement']);}
                catch(Throwable $e){throw new KmmCliUpdateException('Schema migration failed at '.$step['file'].' '.$step['operation'].' '.$step['table'],0,$e);}
            }
            $this->lastStage='apply_sql';
            $this->pdo?->beginTransaction();$this->dbTransaction=true;
            // All user application IDs and selection states are captured under row locks.
            require_once $this->root.'/app/autoload.php';
            $this->applicationsBefore=\KMM\Core\ApplicationUpdateNotice::capture($this->pdo);
            $privateBefore=$this->opts['operator-apply']?$this->privateDataDigest():null;
            // Chart-only release: exact same research snapshot and zero migrations.
            // No catalogue sync, weekly journal or application notifications on this patch.
            if($this->version!=='2.6.1'||$this->previous!=='2.6.0'||$this->sql!==[]||$this->schemaSql!==[]||!is_array($this->oldCatalogue)||!is_array($this->newCatalogue)||$this->oldCatalogue!==$this->newCatalogue)self::refuse('Chart-only patch requires unchanged Batch66 catalogue and no migrations');
            self::say('Chart-only patch: catalogue, weekly-report state and application notifications unchanged.');
            $q=$this->pdo?->prepare('UPDATE installation_state SET release_version=?,schema_version=? WHERE id=1 AND install_id=? AND schema_version=? AND release_version=? AND status=?');
            $q?->execute([$this->version,$this->schema,$this->installLock['install_id'],$this->oldSchema,$this->previous,'installed']);
            if ($q?->rowCount() !== 1) self::refuse('DB release_version update did not affect exactly one installed row');
            if($privateBefore!==null){
                $privateAfter=$this->privateDataDigest();
                if($privateAfter!==$privateBefore)self::refuse('Protected user table contents changed; update rolled back');
                self::atomic($this->backupRoot.'/protected-data-verification.json',json_encode(['result'=>'PASS','tables'=>$privateAfter],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),0600);
                self::say('Protected user data verified: '.count($privateAfter).' tables unchanged.');
            }
            $this->lastStage='commit_database';
            // Ambiguous COMMIT cannot be called rolled-back without restoring the sealed SQL backup.
            $this->dbCommitAttempted=true;
            if (!$this->pdo?->commit()) self::refuse('DB transaction commit failed');
            $this->dbTransaction=false;$this->dbCommitted=true;
            $this->lastStage='finalize_locks';
            $install=$this->installLock;$install['version']=$this->version;$install['schema']=$this->schema;
            $update=$this->updateLock;$update['version']=$this->version;$update['schema']=$this->schema;
            $this->saveLock('install',$install);
            $this->lastStage='final_verify';
            if ($this->readLock('install')['version']!==$this->version) self::refuse('Final install lock verification failed');
            $q=$this->pdo?->query('SELECT release_version FROM installation_state WHERE id=1');
            if ($q?->fetchColumn()!==$this->version) self::refuse('Final DB release version mismatch');
            $this->saveLock('update',$update);
            if($this->readLock('update')['version']!==$this->version)self::refuse('Final update lock verification failed');
            $this->saveReceipt('completed', 'files_and_transactional_sql_committed');
        } catch (Throwable $e) {
            $failedAt=$this->lastStage;
            $errors=[];
            // In case READY had just been published, close the gate before rollback.
            if ($this->lockChanged) {
                try {$this->saveLock('update',array_replace($this->updateLock,['phase'=>'UPDATING','rollback_pending'=>true]));}
                catch(Throwable $re){$errors[]='Failed to re-enable maintenance: '.$re->getMessage();}
            }
            if($this->dbTransaction && !$this->pdo?->inTransaction())$errors[]='Expected DB transaction is not active; manual verification required';
            if ($this->dbTransaction && $this->pdo?->inTransaction()) {
                try {$this->pdo->rollBack();$this->dbTransaction=false;}catch(Throwable $re){$errors[]='PDO rollback: '.$re->getMessage();}
            }
            try {if ($this->filesChanged) $this->rollbackFiles();}catch(Throwable $re){$errors[]='File rollback: '.$re->getMessage();}
            if($this->opts['operator-apply'] && ($this->dbCommitAttempted || $this->schemaChanged)){
                $errors[]='Commit attempted or schema changed: do not automatically replace production DB; verify database.before.sql and restore under continued maintenance if required';
            }elseif ($this->dbCommitAttempted || $this->schemaChanged) {
                try {
                    $this->restoreDb();
                    // Verify restored installation tuple before unlocking the old site.
                    $check=$this->pdo?->query('SELECT install_id,schema_version,release_version,status FROM installation_state WHERE id=1')->fetch(PDO::FETCH_ASSOC);
                    if(!is_array($check)||$check['install_id']!==$this->installLock['install_id']||
                       (int)$check['schema_version']!==$this->oldSchema||$check['release_version']!==$this->previous||$check['status']!=='installed')
                        throw new KmmCliUpdateException('Restored installation_state is inconsistent');
                }catch(Throwable $re){$errors[]='DB snapshot restore: '.$re->getMessage();}
            }
            if ($errors===[] && $this->lockChanged) {
                try {
                    self::atomic($this->root.'/install.lock',$oldInstall,0600);
                    self::atomic($this->root.'/update.lock',$oldUpdate,0600);
                    $this->lockChanged=false;
                } catch(Throwable $re) {$errors[]='Lock rollback: '.$re->getMessage();}
            }
            $this->saveReceipt($errors===[]?'rolled_back':'RECOVERY_REQUIRED', 'failed_stage='.$failedAt.'; cause='.get_class($e).'; rollback='.implode(' | ',$errors));
            $summary=$errors===[]?'automatic rollback completed':'INCOMPLETE rollback: site remains in recovery/maintenance; administrator required';
            throw new KmmCliUpdateException('Update failed at '.$failedAt.': '.$e->getMessage().' | '.$summary,0,$e);
        }
    }
    private function stageAndCopyOriginals(): void
    {
        $paths=array_merge($this->actions['add'],$this->actions['update']);
        foreach ($paths as $path) {
            $this->checkTarget($path);
            $dst=$this->backupRoot.'/staged/'.$path;
            if (!is_dir(dirname($dst)) && !mkdir(dirname($dst),0700,true) && !is_dir(dirname($dst))) self::refuse('Staging directory creation failed');
            $bytes=$path==='release-manifest.json'?$this->zip?->getFromName($path):null;
            $s=$this->zip?->getStream($path);
            if (!is_resource($s)) self::refuse('Cannot stage archive file: '.$path);
            $d=fopen($dst,'xb');if(!is_resource($d))self::refuse('Staging target unavailable');
            $ctx=hash_init('sha256');
            while(!feof($s)) {
                $buf=fread($s,65536);if($buf===false)self::refuse('Staged ZIP member read failed');
                if($buf==='') {if(!feof($s))self::refuse('Staged ZIP member stalled');break;}
                hash_update($ctx,$buf);if(fwrite($d,$buf)!==strlen($buf))self::refuse('Staging write failed');
            }
            fclose($s);fflush($d);fclose($d);
            if(!hash_equals($this->newHashes[$path],hash_final($ctx)))self::refuse('Staged file hash mismatch');
            chmod($dst,0600);
        }
        foreach (array_merge($this->actions['update'],$this->actions['delete']) as $path) {
            $p=$this->root.'/'.$path; $copy=$this->backupRoot.'/before/'.$path;
            if (!is_dir(dirname($copy)) && !mkdir(dirname($copy),0700,true) && !is_dir(dirname($copy))) self::refuse('Backup directory creation failed');
            $this->oldModes[$path]=(fileperms($p) & 0777);
            if (!copy($p,$copy)) self::refuse('Cannot back up existing managed file: '.$path);
            if (!hash_equals((string)hash_file('sha256',$p),(string)hash_file('sha256',$copy))) self::refuse('Original file backup mismatch: '.$path);
            chmod($copy,0600);
        }
        $plan=['previous'=>$this->previous,'target'=>$this->version,'schema'=>$this->schema,
               'add'=>$this->actions['add'],'update'=>$this->actions['update'],'delete'=>$this->actions['delete'],
               'migrations'=>count($this->sql),'schema_operations'=>array_map(static fn($v)=>['operation'=>$v['operation'],'table'=>$v['table']],$this->schemaSql),'original_modes'=>$this->oldModes];
        self::atomic($this->backupRoot.'/plan.json',json_encode($plan,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),0600);
    }
    private function lintStagedPhp(): void
    {
        foreach (array_merge($this->actions['add'],$this->actions['update']) as $path) {
            if (!str_ends_with(strtolower($path),'.php')) continue;
            $src=$this->backupRoot.'/staged/'.$path;
            $this->subprocess([PHP_BINARY,'-l',$src],[0=>['file','/dev/null','r'],1=>['file','/dev/null','w'],2=>['pipe','w']]);
        }
    }
    private function verifyAppliedFiles(): void
    {
        foreach (array_merge($this->actions['add'],$this->actions['update']) as $path) {
            $p=$this->root.'/'.$path;
            if (!is_file($p) || !hash_equals($this->newHashes[$path],(string)hash_file('sha256',$p))) self::refuse('Installed file digest mismatch: '.$path);
        }
        foreach ($this->actions['delete'] as $path) {
            if (file_exists($this->root.'/'.$path)) self::refuse('Deleted file still exists: '.$path);
        }
    }
    private function applyFiles(): void
    {
        $changes=array_merge($this->actions['add'],$this->actions['update']);
        // Manifest and VERSION last, only after application files are in place.
        usort($changes,static fn($a,$b)=>((int)in_array($a,['VERSION','release-manifest.json'],true))<=>((int)in_array($b,['VERSION','release-manifest.json'],true)));
        foreach ($changes as $path) {
            $dest=$this->root.'/'.$path;
            $dir=dirname($dest);
            if (!is_dir($dir) && !mkdir($dir,0755,true) && !is_dir($dir)) self::refuse('Application target directory unavailable: '.$path);
            $this->checkTarget($path);
            $src=$this->backupRoot.'/staged/'.$path;
            if (!rename($src,$dest)) self::refuse('File replacement failed: '.$path);
            $this->filesChanged=true; $this->writtenPaths[]=$path;
            chmod($dest,$this->oldModes[$path]??(str_starts_with($path,'resources/private/') ? 0600 : 0644));
        }
        foreach ($this->actions['delete'] as $path) {
            $this->checkTarget($path);
            if (!unlink($this->root.'/'.$path)) self::refuse('Managed file deletion failed: '.$path);
            $this->filesChanged=true;$this->writtenPaths[]=$path;
        }
    }
    private function rollbackFiles(): void
    {
        foreach (array_reverse($this->writtenPaths) as $path) {
            $original=$this->backupRoot.'/before/'.$path;$current=$this->root.'/'.$path;
            $this->checkTarget($path);
            if (is_file($original)) {
                $tmp=$current.'.rollback.'.bin2hex(random_bytes(5));
                if (!copy($original,$tmp)) self::refuse('Cannot restore backed-up file: '.$path);
                chmod($tmp,$this->oldModes[$path]??0600);
                if (!rename($tmp,$current)) self::refuse('Cannot atomically restore backed-up file: '.$path);
            } elseif (in_array($path,$this->actions['add'],true)) {
                if (is_file($current) && !unlink($current)) self::refuse('Cannot remove new file during rollback: '.$path);
            } else self::refuse('Rollback original missing: '.$path);
        }
    }
    private function mysqlClientConfig(): string
    {
        $cfg=json_decode((string)file_get_contents($this->root.'/storage/private/database.json'),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($cfg)) self::refuse('Invalid DB config');
        foreach (['host','database','username','password'] as $field) {
            if (!is_string($cfg[$field]??null) || preg_match('/[\r\n\x00]/',$cfg[$field])) self::refuse('Invalid DB client option');
        }
        $quote=static fn($s)=>'"'.str_replace(['\\','"'],['\\\\','\\"'],$s).'"';
        $body="[client]\nhost=".$quote($cfg['host'])."\nport=".(int)($cfg['port']??3306)."\nuser=".$quote($cfg['username'])."\npassword=".$quote($cfg['password'])."\n";
        $temp=$this->backupRoot.'/client-'.bin2hex(random_bytes(6)).'.cnf';
        if (file_put_contents($temp,$body,LOCK_EX)!==strlen($body)) self::refuse('Cannot create temporary database client config');
        chmod($temp,0600);
        return $temp;
    }
    private function subprocess(array $argv,array $descriptors): void
    {
        $pipes=[];$p=proc_open($argv,$descriptors,$pipes,$this->root,['PATH'=>(string)getenv('PATH')]);
        if (!is_resource($p)) self::refuse('DB backup/recovery process could not start');
        $err='';
        if (isset($pipes[2])) {while(!feof($pipes[2])) {$chunk=fread($pipes[2],4096);if($chunk===false)break;if(strlen($err)<4096)$err.=substr($chunk,0,4096-strlen($err));}fclose($pipes[2]);}
        if (isset($pipes[1])) fclose($pipes[1]);
        $rc=proc_close($p);
        // Never echo database client stderr: it can contain sensitive server paths.
        if ($rc!==0) self::refuse('Database client failed with exit status '.$rc.' (details remain in server-local diagnostics)');
    }
    private function dumpDb(): void
    {
        $cnf=$this->mysqlClientConfig();
        try {
            $cfg=json_decode((string)file_get_contents($this->root.'/storage/private/database.json'),true,32,JSON_THROW_ON_ERROR);
            $cmd=[$this->binary('mysqldump'), '--defaults-extra-file='.$cnf,'--single-transaction','--quick','--hex-blob','--routines','--triggers','--events','--add-drop-table','--no-tablespaces',...(!str_contains(strtolower(basename((string)$this->binary('mysqldump'))),'mariadb')?['--set-gtid-purged=OFF']:[]),$cfg['database']];
            $this->subprocess($cmd,[0=>['file','/dev/null','r'],1=>['file',$this->dbDump,'w'],2=>['pipe','w']]);
            if (!is_file($this->dbDump) || filesize($this->dbDump)<100) self::refuse('Database backup absent/empty');
            chmod($this->dbDump,0600);
        } finally { @unlink($cnf); }
    }
    private function restoreDb(): void
    {
        if (!is_file($this->dbDump) || filesize($this->dbDump)<100) self::refuse('DB restoration snapshot unavailable');
        $cnf=$this->mysqlClientConfig();
        try {
            $cfg=json_decode((string)file_get_contents($this->root.'/storage/private/database.json'),true,32,JSON_THROW_ON_ERROR);
            $cmd=[$this->binary('mysql'),'--defaults-extra-file='.$cnf,'--binary-mode=1',$cfg['database']];
            $this->subprocess($cmd,[0=>['file',$this->dbDump,'r'],1=>['file','/dev/null','w'],2=>['pipe','w']]);
        } finally { @unlink($cnf); }
    }
    private function saveReceipt(string $status,string $reason): void
    {
        if ($this->backupRoot==='' || !is_dir($this->backupRoot)) return;
        $record=['status'=>$status,'time_utc'=>gmdate('c'),'from'=>$this->previous,'to'=>$this->version,
                 'stage'=>$this->lastStage,'message'=>$reason,'backup_database_sha256'=>is_file($this->dbDump)?hash_file('sha256',$this->dbDump):null];
        try {self::atomic($this->backupRoot.'/receipt.json',json_encode($record,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),0600);}
        catch(Throwable) { /* receipt failure must not mask the original failure */ }
    }
    private function selftest(): int
    {
        $cases=[
          'INSERT INTO companies(id) VALUES (1);' => true,
          "UPDATE companies SET legal_name='New' WHERE id='abc';"=>true,
          'DELETE FROM sources WHERE id=1;'=>true,
          'UPDATE companies SET legal_name=1;'=>false,
          'DELETE FROM sources;'=>false,
          'REPLACE INTO companies(id) VALUES (1);'=>false,
          'INSERT INTO companies SELECT * FROM users;'=>false,
          "UPDATE companies JOIN users ON users.id=1 SET users.status='disabled' WHERE companies.id=1;"=>false,
          "INSERT INTO companies(id) VALUES (1) ON DUPLICATE KEY UPDATE id=2;"=>false,
          "UPDATE jobs SET company_id='bad' WHERE id='abc';"=>false,
          "UPDATE system_settings SET value_json='false' WHERE setting_key='auth.self_registration_enabled';"=>false,
          'CREATE TABLE t(id INT);'=>false,
          'ALTER TABLE t ADD COLUMN a INT;'=>false,
          'DROP TABLE t;'=>false,
          'SET FOREIGN_KEY_CHECKS=0;'=>false,
          'SELECT * FROM users;'=>false,
          'INSERT INTO t VALUES (1); COMMIT;'=>false,
          'UPDATE t SET a=1 -- hidden'=>false,
        ];$ok=0;
        foreach ($cases as $query=>$valid) {
            try {self::parseTransactionalSql($query);$got=true;}catch(KmmCliUpdateException){$got=false;}
            if ($got!==$valid) self::refuse('SQL selftest failed for '.substr($query,0,60).' expected='.($valid?'true':'false').' got='.($got?'true':'false'));$ok++;
        }
        foreach(['CREATE TABLE release_new(id INT PRIMARY KEY) ENGINE=InnoDB;'=>true,
                 'ALTER TABLE release_new ADD COLUMN c INT NULL;'=>true,
                 'DROP TABLE release_new;'=>true,
                 'TRUNCATE TABLE users;'=>false,
                 'DROP DATABASE hello;'=>false,
                 'ALTER TABLE users RENAME TO old_users;'=>false,
                 'CREATE TABLE exfil(id INT) ENGINE=InnoDB AS SELECT id FROM users;'=>false,
                 'ALTER TABLE t ADD COLUMN x INT, DROP COLUMN name;'=>false] as $sql=>$valid){
            try{self::parseSchemaSql($sql);$got=true;}catch(KmmCliUpdateException){$got=false;}
            if($got!==$valid)self::refuse('Schema parser selftest failed');$ok++;
        }
        foreach (['app/Core/SiteState.php','public/index.php','docs/USER_GUIDE.md','.env.example','public/favicon.ico'] as $n) {
            if (!self::safePath($n)) self::refuse('Path selftest failed');$ok++;
        }
        foreach (['../storage/private/app.key','public/.user.ini','.env','public/.env.example','storage/private/database.json','public/../../etc/passwd','public/evil\\name'] as $n) {
            if (self::safePath($n)) self::refuse('Protected path selftest failed');$ok++;
        }
        self::say('SELFTEST PASS: '.$ok.' safety/SQL cases. (No ZIP, DB or filesystem changes.)');
        return 0;
    }
}

try {
    exit((new KmmCliUpdater($argv))->run());
} catch (Throwable $e) {
    fwrite(STDERR, 'UPDATE REFUSED/FAILED ['.get_class($e).']: '.$e->getMessage().PHP_EOL);
    exit(1);
}
