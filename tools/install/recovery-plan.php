<?php
declare(strict_types=1);
/** Operator-only READ-ONLY interrupted-install assessment. No DB connection, delete or retry. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\{Database,SiteState};
if(array_slice($argv,1)!==['--json']){
    fwrite(STDERR,"Usage: php tools/install/recovery-plan.php --json\nThis command inspects file presence only; it never repairs the site.\n");
    exit(2);
}
$root=SiteState::root();
$flags=[
    'install_intent'=>is_file(SiteState::initialIntentPath()),
    'database_config'=>is_file(Database::configPath()),
    'installation_lock'=>is_file(SiteState::lockPath('install')),
    'updater_lock'=>is_file(SiteState::lockPath('update')),
    'application_key'=>is_file($root.'/storage/private/app.key'),
];
$phase=SiteState::installationPhase($flags['database_config'],$flags['installation_lock'],$flags['updater_lock'],$flags['install_intent']);
$state=$phase==='new'?'NOT_STARTED':($phase==='recovery'?'MANUAL_RECOVERY_REQUIRED':'VERIFY_INSTALLED_WITH_DB_LATER');
$report=[
    'project'=>'キミの未来へ',
    'mode'=>'read_only_presence_check',
    'site_release'=>trim((string)file_get_contents($root.'/VERSION')),
    'phase'=>$state,
    'presence'=>$flags,
    'does_not_access_database'=>true,
    'does_not_print_secrets'=>true,
    'does_not_mutate_files'=>true,
    'next_actions'=>($state==='NOT_STARTED')?[
        'Run the installer only with HTTPS, a valid one-time setup token, and an empty dedicated database.',
    ]:[
        'Keep the site in maintenance/recovery; do not clear install.incomplete or lock files automatically.',
        'Before any manual repair, verify the target database, backup, intended site URL, and owner credentials out of band.',
        'Never drop existing tables or reset the DEVELOPER account merely to restart setup.',
        'Use the final external disposable MySQL environment to test the recovery/rollback procedure before production.',
    ],
];
echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
