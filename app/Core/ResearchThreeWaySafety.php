<?php
declare(strict_types=1);
namespace KMM\Core;

/** Read-only safety classification for independently acquired, *complete* 3-way snapshots.
 * Never turns a research name suggestion into an enrolled company or permits applying changes.
 * The caller must still authenticate, bind an independently verified DB ID and lock/recheck
 * real rows before using ANY underlying seed functionality. Only field names leave this method.
 */
final class ResearchThreeWaySafety {
    public static function inspect(string $kind,array $baseline,array $incoming,array $userOverlay,
                                   array $live,int $expectedRevision,int $actualRevision):array {
        if($expectedRevision<0||$actualRevision<0||$actualRevision>2147483646)
            throw new \DomainException('Invalid database revision');
        $type=$kind==='companies'?'companies':SeedEntityData::type($kind);
        $fields=$type==='companies'?SeedData::FIELDS:SeedEntityData::FIELDS[$type];
        if($type==='companies'){
            $base=SeedData::snapshot($baseline);$inc=SeedData::snapshot($incoming);
            $current=SeedData::snapshot($live);
            foreach(array_keys($userOverlay) as $key)if(!in_array($key,$fields,true))
                throw new \DomainException('Unexpected personal overlay field');
        }else{
            $base=SeedEntityData::snapshot($type,$baseline);
            $inc=SeedEntityData::snapshot($type,$incoming);
            $current=SeedEntityData::snapshot($type,$live);
        }
        if($expectedRevision!==$actualRevision)return self::result('stale_revision',[],[]);
        try {
            $plan=$type==='companies'?SeedData::plan($base,$inc,$userOverlay,$current)
                :SeedEntityData::plan($type,$base,$inc,$userOverlay,$current);
        }catch(\RuntimeException $e){
            // Untracked live change always blocks; never leak private effective values.
            return self::result('untracked_live_edit',[],[]);
        }
        $changed=array_keys($plan['changed']);$conflicts=array_keys($plan['conflicts']);
        foreach(array_merge($changed,$conflicts) as $field)if(!in_array($field,$fields,true))
            throw new \DomainException('Unexpected planned field');
        sort($changed);sort($conflicts);
        return self::result($conflicts!==[]?'overlay_conflicts':($changed!==[]?'candidate_changes':'unchanged'),$changed,$conflicts);
    }
    private static function result(string $status,array $fields,array $conflicts):array {
        return ['status'=>$status,'candidate_field_keys'=>$fields,'conflict_field_keys'=>$conflicts,
            'requires_independent_company_identity'=>true,'requires_live_db_lock_and_recheck'=>true,
            'may_approve'=>false,'may_apply'=>false,'may_publish'=>false,'may_notify'=>false];
    }
}
