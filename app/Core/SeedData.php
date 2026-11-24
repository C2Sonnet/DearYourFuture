<?php
declare(strict_types=1);
namespace KMM\Core;
/** Deterministic, pure normalization and three-way plan. No publishing-state edits. */
final class SeedData {
    public const FIELDS=['display_name','legal_name','description','website_url'];
    public static function snapshot(array $input):array {
        // Every incoming snapshot is complete; a missing key must not silently clear data.
        foreach(self::FIELDS as $f){if(!array_key_exists($f,$input))throw new \InvalidArgumentException('Missing seed field: '.$f);}
        $v=BusinessData::fields('companies',array_merge($input,['visibility'=>'draft']));
        return array_intersect_key($v,array_flip(self::FIELDS));
    }
    public static function key(string $raw):string {
        if(!preg_match('/\A[a-z0-9][a-z0-9-]{2,63}\z/D',$raw))throw new \InvalidArgumentException('Invalid stable seed key');
        return $raw;
    }
    public static function version(string $raw):string {
        if(!preg_match('/\A[a-zA-Z0-9._-]{1,50}\z/D',$raw))throw new \InvalidArgumentException('Invalid seed version');
        return $raw;
    }
    public static function encode(mixed $value):string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    /** @return array{effective:array,conflicts:array,changed:array} */
    public static function plan(array $old,array $incoming,array $overlays,array $current):array {
        foreach([$old,$incoming,$current] as $arr){foreach(self::FIELDS as $f)if(!array_key_exists($f,$arr))throw new \InvalidArgumentException('Incomplete snapshot');}
        foreach(array_keys($overlays) as $key)if(!in_array($key,self::FIELDS,true))throw new \InvalidArgumentException('Unknown overlay field');
        $effective=$current;$conflicts=[];$changed=[];
        foreach(self::FIELDS as $field){
            $base=$old[$field];$next=$incoming[$field];$edited=array_key_exists($field,$overlays);
            $user=$edited?$overlays[$field]:$base;
            // An untracked DB modification must stop the entire import, never be overwritten.
            if($current[$field]!==$user)throw new \RuntimeException('Untracked live edit: '.$field);
            $projectChanged=$base!==$next;$userChanged=$edited && $base!==$user;
            if($projectChanged && $userChanged && $next!==$user){
                $conflicts[$field]=['old'=>$base,'incoming'=>$next,'user'=>$user,'effective'=>$current[$field]];
            }else{
                $selected=$userChanged?$user:$next;
                if($current[$field]!==$selected){$effective[$field]=$selected;$changed[$field]=$selected;}
            }
        }
        return ['effective'=>$effective,'conflicts'=>$conflicts,'changed'=>$changed];
    }
    public static function decision(mixed $choice):string {
        if(!is_string($choice)||!in_array($choice,['origin','user','manual','later'],true))throw new \InvalidArgumentException('Invalid resolution choice');
        return $choice;
    }
}
