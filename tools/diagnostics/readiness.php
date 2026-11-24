<?php
declare(strict_types=1);
/** Read-only static/operator preflight. No DB connection, writes, or hidden data. */
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\OperationalPreflight;
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
$items=OperationalPreflight::collect();$totals=OperationalPreflight::totals($items);
if(in_array('--json',$argv,true)){
    echo json_encode(['kind'=>'read_only_preflight','schema'=>1,'no_database_access'=>true,
        'external_batch_performed'=>false,'production_accepted'=>false,'totals'=>$totals,'checks'=>$items],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}else{
    echo "OFFLINE / READ-ONLY PREFLIGHT: internal {$totals['PASS']} PASS, {$totals['BLOCK']} BLOCK; {$totals['DEFERRED']} external checks DEFERRED\n";
    foreach($items as $row)echo $row['status'].' '.$row['code']."\n";
    echo "NOT PRODUCTION-ACCEPTED. No MySQL, SMTP or live browser tests were run.\n";
}
