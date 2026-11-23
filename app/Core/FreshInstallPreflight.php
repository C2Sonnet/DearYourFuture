<?php
// Read-only guard: refuses databases already containing any visible base table or view.
declare(strict_types=1);
namespace KMM\Core;
use PDO;
final class FreshInstallPreflight {
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
