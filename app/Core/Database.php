<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
final class Database {
    private static ?PDO $instance=null;
    public static function configPath(): string {return dirname(__DIR__,2).'/storage/private/database.json';}
    public static function connected(): PDO {
        if(self::$instance!==null)return self::$instance;
        $json=@file_get_contents(self::configPath());
        if(!is_string($json))throw new \RuntimeException('Database not configured');
        $config=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($config)) throw new \RuntimeException('Invalid DB configuration');
        return self::$instance=self::open($config);
    }
    /** @param array{host:string,port:int,database:string,username:string,password:string} $config */
    public static function open(array $config): PDO {
        if (!extension_loaded('pdo_mysql'))throw new \RuntimeException('pdo_mysql driver required');
        $host=$config['host']??''; $db=$config['database']??'';
        $port=(int)($config['port']??3306);
        if(!is_string($host)||!preg_match('/\A[a-zA-Z0-9_.:-]{1,253}\z/',$host)||!is_string($db)||!preg_match('/\A[a-zA-Z0-9_]{1,64}\z/',$db)||$port<1||$port>65535){throw new \InvalidArgumentException('Invalid DB settings');}
        $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',$host,$port,$db);
        return new PDO($dsn,(string)($config['username']??''),(string)($config['password']??''),[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES=>false,
            PDO::ATTR_TIMEOUT=>5,
        ]);
    }
    public static function settings(PDO $db,string $key): mixed {
        $stmt=$db->prepare('SELECT value_json FROM system_settings WHERE setting_key=?');$stmt->execute([$key]);
        $value=$stmt->fetchColumn();if(!is_string($value))throw new \RuntimeException('Missing security setting: '.$key);
        return json_decode($value,true,512,JSON_THROW_ON_ERROR);
    }
    public static function setSetting(PDO $db,string $key,mixed $value): void {
        $stmt=$db->prepare('UPDATE system_settings SET value_json=?,updated_at=UTC_TIMESTAMP() WHERE setting_key=?');
        $stmt->execute([json_encode($value,JSON_THROW_ON_ERROR),$key]);
        if($stmt->rowCount()===0){$check=$db->prepare('SELECT 1 FROM system_settings WHERE setting_key=?');$check->execute([$key]);if($check->fetchColumn()===false)throw new \RuntimeException('Setting missing');}
    }
    public static function writeConfig(array $config): void {
        if(!is_file(SiteState::initialIntentPath())||file_exists(self::configPath())||is_link(self::configPath()))throw new \RuntimeException("Fresh database configuration requires install intent and absent configuration");
        $path=self::configPath(); $dir=dirname($path);
        if(!is_dir($dir) && !mkdir($dir,0700,true)&&!is_dir($dir))throw new \RuntimeException('Storage unavailable');
        $tmp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
        try { if(file_put_contents($tmp,json_encode($config,JSON_THROW_ON_ERROR),LOCK_EX)===false)throw new \RuntimeException('Cannot write DB config');chmod($tmp,0600);if(!rename($tmp,$path))throw new \RuntimeException('Cannot commit DB config');}finally{if(is_file($tmp))unlink($tmp);}
    }
}
