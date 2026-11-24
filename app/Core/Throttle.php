<?php
declare(strict_types=1);
namespace KMM\Core;
final class Throttle {
    public static function allow(string $scope, int $limit=5, int $window=900): bool {
        $base=dirname(__DIR__,2).'/storage/private/throttle';
        if (!is_dir($base)&&!mkdir($base,0700,true)&&!is_dir($base)) {return false;}
        $path=$base.'/'.hash('sha256',$scope).'.json';
        $fp=fopen($path,'c+'); if ($fp===false)return false;
        chmod($path,0600);
        try {
            if(!flock($fp,LOCK_EX)) return false;
            $raw=stream_get_contents($fp);$item=is_string($raw)?json_decode($raw,true):null;
            $now=time();
            $start=is_array($item)?(int)($item['start']??0):0;
            $count=is_array($item)?(int)($item['count']??0):0;
            if($start<=$now-$window || $start>$now){$start=$now;$count=0;}
            if($count>=$limit)return false;
            $count++;rewind($fp);ftruncate($fp,0);
            fwrite($fp,json_encode(['start'=>$start,'count'=>$count],JSON_THROW_ON_ERROR));fflush($fp);
            return true;
        } finally { flock($fp,LOCK_UN);fclose($fp); }
    }
}
