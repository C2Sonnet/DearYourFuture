<?php
declare(strict_types=1);
/** Read-only reviewer planning. No database, account tokens or email is touched. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\SeedCompositeReview;
try {
    if(count($argv)!==5)throw new InvalidArgumentException('Usage: php tools/seeds/reviewed-reimport-plan.php feed.json COMPANY_BATCH_ID ENTITY_BATCH_ID CASE_TICKET');
    $path=$argv[1];
    if(!is_file($path)||is_link($path)||filesize($path)===false||filesize($path)>2*1024*1024)
        throw new InvalidArgumentException('A small, regular local feed JSON is required');
    $raw=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
    if(!is_array($raw))throw new InvalidArgumentException('Object feed required');
    $plan=SeedCompositeReview::plan($raw,$argv[2],$argv[3],$argv[4]);
    $normalized=$plan['feed'];
    echo json_encode([
        'mode'=>'READ_ONLY_NO_DB',
        'reimport_event_key'=>$normalized['event_key'],
        'review_ticket'=>$plan['review_ticket'],
        'company_review_batch_id'=>$plan['company_batch_id'],
        'entity_review_batch_id'=>$plan['entity_batch_id'],
        'company_items'=>count($normalized['company_batch']['companies']),
        'entity_items'=>count($normalized['entity_batch']['records']),
        'db_approval_verified'=>false,
        'notifications_queued'=>false,
        'safe_to_apply'=>false,
        'next_step'=>'Authorized operator must separately confirm resolved batch states and execute the audited server-side applyAfterReview transaction; not exposed as CLI here.',
    ],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,'No import was performed: '.get_class($e).': '.$e->getMessage()."\n");exit(1);}
