<?php
// Read-only guard: refuses databases already containing any visible base table or view.
declare(strict_types=1);
namespace KMM\Core;
use PDO;
final class FreshInstallPreflight {
    /** Serialize cooperating installers sharing the same MySQL database. */
    public static function acquireDatabaseLock(PDO $db):bool {
        return (int)$db->query("SELECT GET_LOCK(CONCAT('kmm_install:',LEFT(SHA2(DATABASE(),256),48)),0)")->fetchColumn()===1;
    }
    public static function releaseDatabaseLock(PDO $db):void {
        $db->query("SELECT RELEASE_LOCK(CONCAT('kmm_install:',LEFT(SHA2(DATABASE(),256),48)))");
    }
    public static function checkPacket(PDO $db):void {
        $size=$db->query('SELECT @@max_allowed_packet')->fetchColumn();
        if(!(is_int($size)||(is_string($size)&&ctype_digit($size)))||(int)$size<16777216)throw new \RuntimeException('max_allowed_packet must be >= 16 MiB');
    }
    public static function countDatabaseObjects(PDO $db): int {
        $statement=$db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
        if($statement===false)throw new \RuntimeException('Unable to verify database emptiness');
        $value=$statement->fetchColumn();
        if(!is_int($value) && !(is_string($value) && preg_match('/\A[0-9]+\z/D',$value))) {
            throw new \RuntimeException('Unexpected database preflight response');
        }
        $count=(int)$value;
        if($count<0)throw new \RuntimeException('Unexpected negative object count');
        return $count;
    }
}
