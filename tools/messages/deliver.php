<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit(1);}
require dirname(__DIR__,2).'/app/autoload.php';
try{
    $db=\KMM\Core\Database::connected();
    $batch=(int)($argv[1]??100);
    $r=\KMM\Core\MessageBroadcast::deliver($db,$batch);
    echo json_encode($r,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,"Delivery worker failed (".get_class($e).")\n");exit(1);}
