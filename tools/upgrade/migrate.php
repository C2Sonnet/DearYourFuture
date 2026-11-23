<?php
declare(strict_types=1);
/** CLI-only migration entrypoint. A verified offline updater must first back up and enter maintenance. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\{Database,Migration,SchemaUpgrade,SiteState};
$args=array_slice($argv,1);
if($args!==[] && $args!==['--apply','--confirm']){fwrite(STDERR,"Usage: php tools/upgrade/migrate.php [--apply --confirm]\n");exit(2);}
try {
    SchemaUpgrade::verifyDeclarations();
    if (!extension_loaded('pdo_mysql'))throw new RuntimeException('pdo_mysql unavailable; only static SQL checks can run');
    if(!is_file(SiteState::lockPath('install'))||!is_file(SiteState::lockPath('update')))throw new RuntimeException('No installed instance');
    $old=SiteState::readLock('install');$lock=SiteState::readLock('update');
    if(!isset($old['install_id'],$old['schema'],$old['version']) ||
       ($lock['install_id']??null)!==$old['install_id'] || ($lock['schema']??null)!==$old['schema'] || ($lock['version']??null)!==$old['version'])throw new RuntimeException('Inconsistent maintenance metadata');
    if(!SchemaUpgrade::sourceSupported((int)$old['schema'],(string)$old['version']))throw new RuntimeException('Unsupported source release');
    $db=Database::connected();
    $plan=SchemaUpgrade::preflight($db);
    if ($plan['install_id']!==$old['install_id'] || $plan['from']!==(int)$old['schema'] || $plan['release_from']!==$old['version'])throw new RuntimeException('DB does not match installation lock');
    $message=json_encode(['from'=>$plan['from'],'to'=>$plan['to'],'create_tables'=>$plan['tables_to_add']],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    if ($args===[]){echo "DRY RUN: $message\n";exit(0);}
    if (($lock['phase']??null)!=='UPDATING')throw new RuntimeException('Verified updater maintenance lock is mandatory');
    $basename=(string)($lock['backup_basename']??'');
    if(!preg_match('/\Akmm_backup_[0-9]{8}_[a-f0-9]{16}\.tar\.gz\z/D',$basename))throw new RuntimeException('Backup record missing');
    $backup=SiteState::root().'/storage/backups/'.$basename;
    if(!is_file($backup)||!hash_equals((string)($lock['backup_sha256']??''),hash_file('sha256',$backup)))throw new RuntimeException('Backup checksum mismatch');
    $result=SchemaUpgrade::apply($db);
    SiteState::finalizeUpgrade((string)$old['install_id'],(int)$old['schema'],(string)$old['version']);
    if(SiteState::state()!=='ready')throw new RuntimeException('Upgrade committed, but final readiness check failed: operator recovery needed');
    $description=$result['from']===$result['to']?'RELEASE METADATA UPDATE':'SCHEMA UPGRADE';
    echo "$description SUCCESS: schema {$result['from']} → {$result['to']}; added ".count($result['tables_to_add'])." table(s)\n";
}catch(Throwable $e){fwrite(STDERR,"UPGRADE REFUSED / RECOVERY REQUIRED: ".get_class($e).": ".$e->getMessage()."\n");exit(1);}
