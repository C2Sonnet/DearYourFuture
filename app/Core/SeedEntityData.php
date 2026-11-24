<?php
declare(strict_types=1);
namespace KMM\Core;

/** Pure field-level merge for seeded brands, jobs and workplaces. No status inference. */
final class SeedEntityData {
    public const FIELDS = [
        'brands'=>['name'],
        'jobs'=>['title','category','graduate_year','recruitment_state','source_id','observed_on','deadline_on','note'],
        'company_locations'=>['name','kind','address','note'],
    ];
    public static function type(string $type):string {
        if(!isset(self::FIELDS[$type]))throw new \InvalidArgumentException('Unsupported seeded record type');
        return $type;
    }
    public static function snapshot(string $type,array $input):array {
        self::type($type);
        foreach(self::FIELDS[$type] as $field){
            if(!array_key_exists($field,$input))throw new \InvalidArgumentException('Missing seeded field: '.$field);
        }
        $validated=BusinessData::fields($type,$input);
        return array_intersect_key($validated,array_flip(self::FIELDS[$type]));
    }
    /** A nullable field is an explicit value, not a deletion. */
    public static function plan(string $type,array $base,array $incoming,array $overlay,array $current):array {
        $type=self::type($type);
        $base=self::snapshot($type,$base);
        $incoming=self::snapshot($type,$incoming);
        $current=self::snapshot($type,$current);
        foreach(array_keys($overlay) as $f)if(!in_array($f,self::FIELDS[$type],true))throw new \InvalidArgumentException('Unsupported overlay field');
        $effective=$current;$changed=[];$conflicts=[];
        foreach(self::FIELDS[$type] as $field){
            $old=$base[$field];$next=$incoming[$field];
            $edited=array_key_exists($field,$overlay);
            $user=$edited?$overlay[$field]:$old;
            // Normalize overlay scalar types with the same business validator.
            if($edited)$user=self::snapshot($type,array_merge($base,[$field=>$user]))[$field];
            if($current[$field]!==$user)throw new \RuntimeException('Untracked modification: '.$field);
            $originMoved=$next!==$old;$userMoved=$edited&&$user!==$old;
            if($originMoved&&$userMoved&&$next!==$user){
                $conflicts[$field]=['old'=>$old,'incoming'=>$next,'user'=>$user,'effective'=>$current[$field]];
            }else{
                $chosen=$userMoved?$user:$next;
                if($chosen!==$current[$field]){$effective[$field]=$chosen;$changed[$field]=$chosen;}
            }
        }
        return ['effective'=>$effective,'changed'=>$changed,'conflicts'=>$conflicts];
    }
    /** When a workplace's location identity changes, a previously verified pin expires. */
    public static function invalidateLocation(array $before,array $after):array {
        if(($before['address']??null)!==($after['address']??null) || ($before['kind']??null)!==($after['kind']??null)){
            $after['verification_state']=($before['verification_state']??'unconfirmed')==='private'?'private':'candidate';
            $after['latitude']=null;$after['longitude']=null;$after['verified_on']=null;
        }
        return $after;
    }
}
