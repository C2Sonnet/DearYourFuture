<?php
declare(strict_types=1);
namespace KMM\Core;
use PDO;
/** Coordinated company + entity update. All DML and outbox writes share one transaction.
 * This strict conflict-free mode rejects a batch requiring review; use the separate
 * company/entity approval queues to resolve conflicts before re-running the compound feed.
 * MySQL row-lock and implicit DDL behavior still need the final external test.
 */
final class SeedCompositeRepository {
    public static function validate(array $raw):array {
        $key=$raw['event_key']??null;
        if(!is_string($key)||!preg_match('/\A[a-zA-Z0-9:_-]{8,80}\z/D',$key))throw new \InvalidArgumentException('Invalid composite key');
        $company=$raw['company_batch']??null;$entities=$raw['entity_batch']??null;
        if(!is_array($company)||!is_array($entities))throw new \InvalidArgumentException('Both complete feeds required');
        // Domain-specific event keys do not collide in the shared seed_update_batches table.
        $suffix=substr(hash('sha256',$key),0,32);
        $company['event_key']='compound_company_'.$suffix;
        $entities['event_key']='compound_entity_'.$suffix;
        $a=SeedRepository::validate($company);
        $b=SeedEntityRepository::validate($entities);
        foreach(['version','source_document','source_checked_on'] as $field){
            if($a[$field]!==$b[$field])throw new \InvalidArgumentException('Composite provenance differs: '.$field);
        }
        return ['event_key'=>$key,'company_batch'=>$company,'entity_batch'=>$entities];
    }
    public static function apply(PDO $db,array $raw,?int $actor=null):array {
        if($db->inTransaction())throw new \RuntimeException('Composite import owns its complete transaction');
        $batch=self::validate($raw);
        if($actor!==null&&$actor<1)throw new \InvalidArgumentException('Invalid importer');
        $db->beginTransaction();
        try {$result=self::runBoth($db,$batch,$actor);$db->commit();return $result;}
        catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Human-reviewed restart, from TWO committed conflict-review batches. This
     * is separate from apply(): no external review is silently accepted. The
     * row locks, composite changes and audit share one transaction.
     */
    public static function applyAfterReview(PDO $db,array $feed,string $companyBatchId,
        string $entityBatchId,string $reviewTicket,int $actor):array {
        if($db->inTransaction())throw new \RuntimeException('Reviewed composite import owns its transaction');
        if($actor<1)throw new \InvalidArgumentException('A reviewer actor is required');
        $plan=SeedCompositeReview::plan($feed,$companyBatchId,$entityBatchId,$reviewTicket);
        $batch=self::validate($plan['feed']);
        $db->beginTransaction();
        try {
            SeedCompositeReview::requireReviewer($db,$actor);
            SeedCompositeReview::assertReviewed($db,$plan);
            $result=self::runBoth($db,$batch,$actor);
            if($result['status']==='complete'){
                $db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES (?,?,?,?,?)')
                    ->execute([$actor,'seed.composite.reviewed_reimport','seed',$batch['event_key'],
                        SeedData::encode(['review_ticket'=>$reviewTicket,'company_origin'=>$companyBatchId,'entity_origin'=>$entityBatchId])]);
            }
            $db->commit();return $result;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    private static function runBoth(PDO $db,array $batch,?int $actor):array {
        $a=SeedRepository::apply($db,$batch['company_batch'],$actor);
        $b=SeedEntityRepository::apply($db,$batch['entity_batch'],$actor);
        $states=[$a['status'],$b['status']];
        if($states===['duplicate','duplicate'])return ['status'=>'duplicate','company'=>$a,'entities'=>$b];
        // Mixed duplicate, pending review or other unknown states must leave no side effects.
        if($states!==['complete','complete'])throw new \RuntimeException('Composite feed requires conflict-free matching batches');
        return ['status'=>'complete','company'=>$a,'entities'=>$b];
    }
}
