<?php
declare(strict_types=1);
/** Read-only check. No connection to DB; never outputs credentials. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
if(array_slice($argv,1)!==['--json']){
    fwrite(STDERR,"Usage: php tools/install/check-install-package.php --json\n");exit(2);
}
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\{InstallPackageIntegrity,SiteState,Database,ResearchR07CanonicalSync};
$root=SiteState::root();
$writableParent=static function(string $path):bool {
    while(!is_dir($path) && dirname($path)!==$path) $path=dirname($path);
    return is_dir($path) && is_writable($path);
};
$result=InstallPackageIntegrity::inspect($root);
$flags=[
    'incomplete_install'=>(file_exists(SiteState::initialIntentPath())||is_link(SiteState::initialIntentPath())),
    'database_config'=>(file_exists(Database::configPath())||is_link(Database::configPath())),
    'install_lock'=>(file_exists(SiteState::existingLockPath('install'))||is_link(SiteState::existingLockPath('install'))),
    'update_lock'=>(file_exists(SiteState::existingLockPath('update'))||is_link(SiteState::existingLockPath('update'))),
    'app_key'=>(file_exists($root.'/storage/private/app.key')||is_link($root.'/storage/private/app.key')),
];
try{ResearchR07CanonicalSync::inspectSource();$batch79=true;}catch(\Throwable){$batch79=false;}
$phase=SiteState::installationPhase($flags['database_config'],$flags['install_lock'],$flags['update_lock'],$flags['incomplete_install']);
if($phase==='new'&&$flags['app_key'])$phase='recovery';
$report=[
    'mode'=>'READ_ONLY',
    'installation_allowed'=>$result['ok']&&$batch79&&$phase==='new'&&version_compare(PHP_VERSION,'8.4','>=')&&extension_loaded('pdo_mysql')&&extension_loaded('mbstring')&&defined('PASSWORD_ARGON2ID'),
    'initial_install_status'=>'IMPLEMENTED_PENDING_REAL_ACCEPTANCE',
    'batch79_source_integrity'=>$batch79,
    'database_emptiness'=>'NOT_CHECKED',
    'release_version'=>trim((string)file_get_contents($root.'/VERSION')),
    'package'=>$result,
    'environment'=>[
        'php_version'=>PHP_VERSION,
        'pdo_mysql'=>extension_loaded('pdo_mysql'),
        'argon2id'=>defined('PASSWORD_ARGON2ID'),
        'zlib'=>function_exists('gzcompress'),
        'cli_storage_system_parent_writable'=>$writableParent($root.'/storage/system'),
        'cli_storage_private_parent_writable'=>$writableParent($root.'/storage/private'),
    ],
    'install_presence'=>$flags,
    'site_phase'=>$phase,
    'database_accessed'=>false,
    'state_modified'=>false,
    'permission_note'=>'CLI user permissions may differ from the PHP-FPM/webserver worker; check both.',
    'secrets_printed'=>false,
];
echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit($result['ok']&&$batch79?0:3);
