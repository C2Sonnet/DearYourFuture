<?php
declare(strict_types=1);
namespace KMM\Core;
/** Field-level three-way merge; NULL may be a real value, so require presence explicitly. */
final class Merge {
    /** @return array{effective:array,conflicts:array} */
    public static function resolve(array $old, array $incoming, array $user): array {
        $effective = $old;
        $conflicts=[];
        $keys=array_unique(array_merge(array_keys($old),array_keys($incoming),array_keys($user)));
        foreach ($keys as $key) {
            $oldExists=array_key_exists($key,$old);
            $nextExists=array_key_exists($key,$incoming);
            $editExists=array_key_exists($key,$user);
            $base=$old[$key]??null;
            $next=$incoming[$key]??null;
            $edit=$user[$key]??null;
            $changedOrigin=($nextExists!==$oldExists)||($nextExists && (!$oldExists || $next!==$base));
            $changedUser=$editExists && (!$oldExists || $edit!==$base);
            if ($changedOrigin && $changedUser && (!$nextExists || $next!==$edit)) {
                $conflicts[$key]=['old'=>$oldExists?$base:null,'incoming'=>$nextExists?$next:null,'user'=>$edit,'incomingDeleted'=>!$nextExists];
                if ($oldExists) { $effective[$key]=$base; } else { unset($effective[$key]); }
            } elseif ($changedUser) { $effective[$key]=$edit; }
            elseif ($changedOrigin) { if ($nextExists) { $effective[$key]=$next; } else { unset($effective[$key]); } }
        }
        return ['effective'=>$effective,'conflicts'=>$conflicts];
    }
}
