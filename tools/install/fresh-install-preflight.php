<?php
// CLI-only, read-only preflight for a brand-new site root and (optionally) DB.
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\{SiteState,Database,FreshInstallPreflight,InstallPackageIntegrity,ResearchR07CanonicalSync};

$mode=$argv[1]??'--files';
if(!in_array($mode,['--files','--db-env'],true)||count($argv)>2){
    fwrite(STDERR,"Usage: php tools/install/fresh-install-preflight.php [--files|--db-env]\n");
    exit(2);
}
$root=SiteState::root();
$state=SiteState::state();
$paths=[
 'install_intent'=>SiteState::initialIntentPath(),
 'db_config'=>Database::configPath(),
 'app_key'=>$root.'/storage/private/app.key',
 'install_lock'=>SiteState::lockPath('install'),
 'update_lock'=>SiteState::lockPath('update')
];
echo "KMM fresh installation preflight | READ ONLY\n";
echo 'version='.trim((string)file_get_contents($root.'/VERSION'))."\n";
echo 'site_state='.$state."\n";
foreach($paths as $label=>$file)echo 'file.'.$label.'='.(is_file($file)?'PRESENT':'absent')."\n";
echo 'site_root_writable='.(is_writable($root)?'yes':'no')."\n";
echo 'php.version='.PHP_VERSION."\n";
echo 'php.pdo_mysql='.(extension_loaded('pdo_mysql')?'yes':'no')."\n";
echo 'php.mbstring='.(extension_loaded('mbstring')?'yes':'no')."\n";
echo 'php.argon2id='.(defined('PASSWORD_ARGON2ID')?'yes':'no')."\n";
$ok=$state==='new'&&is_writable($root)&&version_compare(PHP_VERSION,'8.4','>=')&&extension_loaded('pdo_mysql')&&extension_loaded('mbstring')&&defined('PASSWORD_ARGON2ID');
try{$pins=InstallPackageIntegrity::inspect();ResearchR07CanonicalSync::inspectSource();$sourceOk=$pins['ok'];}catch(\Throwable){$sourceOk=false;}
echo 'source.integrity='.($sourceOk?'PASS':'FAIL')."\n";$ok=$ok&&$sourceOk;
if($mode==='--db-env'){
    $env=[
      'host'=>getenv('KMM_PREFLIGHT_DB_HOST'),
      'port'=>getenv('KMM_PREFLIGHT_DB_PORT'),
      'database'=>getenv('KMM_PREFLIGHT_DB_NAME'),
      'username'=>getenv('KMM_PREFLIGHT_DB_USER'),
      'password'=>getenv('KMM_PREFLIGHT_DB_PASSWORD')
    ];
    if(!is_string($env['host'])||$env['host']===''||!is_string($env['database'])||$env['database']===''
       ||!is_string($env['username'])||$env['username']===''||!is_string($env['port'])
       ||!preg_match('/\A[0-9]{1,5}\z/D',$env['port'])||!is_string($env['password'])){
        fwrite(STDERR,"DB environment variables incomplete. Check INSTALL.md; never place passwords on a command line.\n");
        exit(2);
    }
    try {
        // No DDL, DML, secrets, table names or individual records are printed.
        $db=Database::open(['host'=>$env['host'],'port'=>(int)$env['port'],
          'database'=>$env['database'],'username'=>$env['username'],'password'=>$env['password']]);
        FreshInstallPreflight::checkPacket($db);
        $count=FreshInstallPreflight::countDatabaseObjects($db);
        echo 'db.connection=PASS'."\n";
        echo 'db.max_allowed_packet=PASS (>=16 MiB)' . "\n";
        echo 'db.object_count='.$count."\n";
        $ok=$ok&&$count===0;
    }catch(\Throwable $error){
        // Do not print PDOException::getMessage(), which can contain DB connection metadata.
        echo 'db.connection_or_check=FAIL type='.get_class($error)."\n";
        $ok=false;
    }
}else echo 'db.emptiness=NOT_CHECKED (web installer enforces an empty DB)'."\n";
echo 'result='.($ok?'PASS':'STOP')."\n";
exit($ok?0:3);
