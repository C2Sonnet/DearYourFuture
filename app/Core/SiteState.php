<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Install/update locks are integrity records, not an authorization shortcut. */
final class SiteState {
    public static function root(): string { return dirname(__DIR__,2); }
    /** User-facing version; the VERSION file keeps a stable machine-readable prerelease ID. */
    public static function displayVersion(): string {
        $version=trim((string)@file_get_contents(self::root().'/VERSION'));
        return str_replace('-beta',' beta',$version);
    }
    public static function lockPath(string $name): string {
        if(!in_array($name,['install','update'],true))throw new \InvalidArgumentException('Unknown lock');
        return self::root().'/'.$name.'.lock';
    }
    /** Legacy lock is read only during an explicitly supervised migration. Never silently delete it. */
    public static function existingLockPath(string $name):string {
        $current=self::lockPath($name);
        if(is_file($current))return $current;
        return self::root().'/storage/system/'.$name.'.lock';
    }
    public static function initialIntentPath(): string {return self::root().'/storage/system/install.incomplete';}
    /** A crash or network interruption during initial DDL must never reopen the wizard. */
    public static function installationPhase(bool $hasConfig,bool $hasInstallLock,bool $hasUpdateLock,bool $hasIntent):string {
        if($hasIntent)return 'recovery';
        if(!$hasConfig&&!$hasInstallLock&&!$hasUpdateLock)return 'new';
        if(!$hasConfig||!$hasInstallLock||!$hasUpdateLock)return 'recovery';
        return 'check';
    }
    public static function markInitialInstallStarted():void {
        if(self::state()!=='new')throw new \RuntimeException('Initial installation already started');
        self::writeAtomic(self::initialIntentPath(),json_encode(['started_utc'=>gmdate('c'),'state'=>'RECOVERY_IF_INTERRUPTED'],JSON_THROW_ON_ERROR));
    }
    public static function clearInitialInstallIntent():void {
        $path=self::initialIntentPath();
        if(is_file($path)&&!unlink($path))throw new \RuntimeException('Cannot finalize installer intent');
    }
    public static function state(): string {
        $i=is_file(self::existingLockPath('install'));$u=is_file(self::existingLockPath('update'));
        $cfg=is_file(Database::configPath());
        $phase=self::installationPhase($cfg,$i,$u,is_file(self::initialIntentPath()));
        if($phase!=='check')return $phase;
        try {
            $first=self::readLock('install');$second=self::readLock('update');
            $version=trim((string)@file_get_contents(self::root().'/VERSION'));
            if(($first['install_id']??null)!==($second['install_id']??null)||($first['schema']??null)!==Migration::SCHEMA||($second['schema']??null)!==Migration::SCHEMA||($second['phase']??null)!=='READY'||($first['version']??null)!==$version||($second['version']??null)!==$version)return 'recovery';
            $db=Database::connected();
            $q=$db->query("SELECT install_id,schema_version,status,release_version FROM installation_state WHERE id=1")->fetch(PDO::FETCH_ASSOC);
            if(!$q || $q['install_id']!==$first['install_id']||(int)$q['schema_version']!==Migration::SCHEMA||$q['status']!=='installed'||$q['release_version']!==$version)return 'recovery';
            $count=(int)$db->query("SELECT COUNT(*) FROM user_roles ur INNER JOIN users u ON u.id=ur.user_id WHERE ur.role_code='DEVELOPER' AND u.status='active'")->fetchColumn();
            return $count>0?'ready':'recovery';
        } catch(\Throwable) {return 'recovery';}
    }
    public static function readLock(string $name): array {
        $data=@file_get_contents(self::existingLockPath($name));$key=@file_get_contents(self::root().'/storage/private/app.key');
        if(!is_string($data)||!is_string($key)||strlen($key)!==32)throw new \RuntimeException('Invalid lock/key');
        $record=json_decode($data,true,512,JSON_THROW_ON_ERROR);
        $body=$record['data']??null;$mac=$record['mac']??null;
        if(!is_array($body)||!is_string($mac))throw new \RuntimeException('Invalid lock structure');
        $canonical=json_encode($body,JSON_THROW_ON_ERROR);
        if(!hash_equals(hash_hmac('sha256',$canonical,$key),$mac))throw new \RuntimeException('Lock integrity mismatch');
        return $body;
    }
    public static function createLocks(string $id): void {
        $private=self::root().'/storage/private';$system=self::root().'/storage/system';
        foreach([$private,$system] as $dir){if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('Cannot create storage');}
        $keyFile=$private.'/app.key';
        if(is_file($keyFile))throw new \RuntimeException('Existing installation key requires recovery');
        self::writeAtomic($keyFile,random_bytes(32));
        $meta=['install_id'=>$id,'schema'=>Migration::SCHEMA,'version'=>trim((string)file_get_contents(self::root().'/VERSION'))];
        self::writeLock('install',$meta+['phase'=>'INSTALLED']);
        self::writeLock('update',$meta+['phase'=>'READY']);
    }
    /** Only the offline CLI may finalize after the DB migration has committed. */
    public static function finalizeUpgrade(string $installId,int $oldSchema,string $oldRelease): void {
        $old=self::readLock('install');$maintenance=self::readLock('update');
        if(($old['install_id']??null)!==$installId||($old['schema']??null)!==$oldSchema||($old['version']??null)!==$oldRelease||
            ($maintenance['phase']??null)!=='UPDATING'||($maintenance['install_id']??null)!==$installId||
            ($maintenance['schema']??null)!==$oldSchema||($maintenance['version']??null)!==$oldRelease||
            empty($maintenance['backup_sha256']))throw new \RuntimeException('Maintenance state is invalid');
        $new=['install_id'=>$installId,'schema'=>Migration::SCHEMA,'version'=>trim((string)file_get_contents(self::root().'/VERSION'))];
        self::writeLock('install',$new+['phase'=>'INSTALLED']);
        self::writeLock('update',$new+['phase'=>'READY']);
    }
    private static function writeLock(string $name,array $info): void {
        $key=file_get_contents(self::root().'/storage/private/app.key');
        if(!is_string($key)||strlen($key)!==32)throw new \RuntimeException('Invalid key');
        $data=json_encode($info,JSON_THROW_ON_ERROR);
        $record=json_encode(['data'=>$info,'mac'=>hash_hmac('sha256',$data,$key)],JSON_THROW_ON_ERROR);
        self::writeAtomic(self::lockPath($name),$record);
    }
    public static function writeAtomic(string $path,string $data): void {
        $tmp=$path.'.'.bin2hex(random_bytes(8));
        try {
            if(file_put_contents($tmp,$data,LOCK_EX)!==strlen($data))throw new \RuntimeException('Cannot write state');
            chmod($tmp,0600);
            if(!rename($tmp,$path))throw new \RuntimeException('Cannot rename state');
        }finally{if(is_file($tmp))unlink($tmp);}
    }
}
