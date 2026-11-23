<?php
declare(strict_types=1);
namespace KMM\Core;

/** Side-effect-free, redacted environment inventory. No DB/network or secret reads. */
final class OperationalPreflight {
    /** The browser and CLI share this vocabulary. External gates can never become PASS here. */
    public static function assess(array $signal):array {
        $required=['webroot_isolated','source_present','php_target','pdo_mysql',
                   'openssl','sodium','argon2id','db_tools','trust_key','installation_state'];
        foreach($required as $key){
            if(!array_key_exists($key,$signal)||!is_bool($signal[$key]))throw new \InvalidArgumentException('Invalid preflight signals');
        }
        $definitions=[
            ['webroot_isolated','Web公開領域と機密保管の分離','internal'],
            ['source_present','実行ファイル・バージョン','internal'],
            ['php_target','運用PHP 8.5','external'],
            ['pdo_mysql','PDO MySQLドライバー','external'],
            ['openssl','OpenSSL拡張','external'],
            ['sodium','Sodium拡張','external'],
            ['argon2id','Argon2id','external'],
            ['db_tools','DBバックアップ/復元CLI','external'],
            ['trust_key','別経路で固定した正式公開検証鍵','external'],
            ['installation_state','設置/更新ロックとDBの実整合','external'],
        ];
        $rows=[];
        foreach($definitions as [$key,$label,$scope]){
            $status=$scope==='external'?'DEFERRED':($signal[$key]?'PASS':'BLOCK');
            // Even if a local prerequisite exists, external validation must still happen in the final batch.
            $rows[]=['code'=>$key,'label'=>$label,'scope'=>$scope,'status'=>$status,
                     'local_prerequisite_present'=>$signal[$key]];
        }
        return $rows;
    }
    private static function binaryPresent(string $name):bool {
        foreach(explode(PATH_SEPARATOR,(string)getenv('PATH')) as $dir){
            if($dir!==''&&is_file($dir.'/'.$name)&&is_executable($dir.'/'.$name))return true;
        }
        return false;
    }
    public static function collect():array {
        $root=SiteState::root();$public=realpath($root.'/public');$private=realpath($root.'/storage');
        $webrootSafe=is_string($public)&&is_string($private)&&!is_link($root.'/public')&&!is_link($root.'/storage')
            &&!str_starts_with($private,$public.DIRECTORY_SEPARATOR)
            &&!file_exists($root.'/public/storage')&&!file_exists($root.'/public/.env');
        $version=is_file($root.'/VERSION')?trim((string)file_get_contents($root.'/VERSION')):'';
        $sourcePresent=$version!==''&&is_file($root.'/public/index.php')&&is_file($root.'/app/autoload.php');
        $settings=[
            'webroot_isolated'=>$webrootSafe,
            'source_present'=>$sourcePresent,
            'php_target'=>version_compare(PHP_VERSION,'8.5','>='),
            'pdo_mysql'=>extension_loaded('pdo_mysql'),
            'openssl'=>extension_loaded('openssl'),
            'sodium'=>extension_loaded('sodium'),
            'argon2id'=>defined('PASSWORD_ARGON2ID'),
            'db_tools'=>self::binaryPresent('mysql')&&self::binaryPresent('mysqldump'),
            'trust_key'=>is_file($root.'/storage/private/release-verification.pem'),
            'installation_state'=>is_file(SiteState::lockPath('install'))&&is_file(SiteState::lockPath('update'))&&is_file(Database::configPath()),
        ];
        return self::assess($settings);
    }
    public static function totals(array $items):array {
        $out=['PASS'=>0,'BLOCK'=>0,'DEFERRED'=>0];
        foreach($items as $item){$status=$item['status']??'';if(!array_key_exists($status,$out))throw new \InvalidArgumentException('Unknown status');$out[$status]++;}
        return $out;
    }
}
