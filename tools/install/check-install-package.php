<?php
declare(strict_types=1);
/** Read-only check. No connection to DB; never outputs credentials. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
if(array_slice($argv,1)!==['--json']){
    fwrite(STDERR,"Usage: php tools/install/check-install-package.php --json\n");exit(2);
}
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\{InstallPackageIntegrity,SiteState,Database};
$root=SiteState::root();
$writableParent=static function(string $path):bool {
    while(!is_dir($path) && dirname($path)!==$path) $path=dirname($path);
    return is_dir($path) && is_writable($path);
};
$result=InstallPackageIntegrity::inspect($root);
$flags=[
    'incomplete_install'=>is_file(SiteState::initialIntentPath()),
    'database_config'=>is_file(Database::configPath()),
    'install_lock'=>is_file(SiteState::lockPath('install')),
    'update_lock'=>is_file(SiteState::lockPath('update')),
    'app_key'=>is_file($root.'/storage/private/app.key'),
];
$report=[
    'mode'=>'READ_ONLY',
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
    'site_phase'=>SiteState::installationPhase($flags['database_config'],$flags['install_lock'],$flags['update_lock'],$flags['incomplete_install']),
    'database_accessed'=>false,
    'state_modified'=>false,
    'permission_note'=>'CLI user permissions may differ from the PHP-FPM/webserver worker; check both.',
    'secrets_printed'=>false,
];
echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
exit($result['ok']?0:3);
