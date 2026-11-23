<?php
declare(strict_types=1);
/** Read-only: verify archived research source bytes and print a review-only JSON. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\ResearchDeltaReview;
try {
    if(count($argv)!==4)throw new InvalidArgumentException('Usage: php tools/seeds/review-research-delta.php MANIFEST.json UPDATES.json REPORT.md');
    $sources=[];
    foreach(array_slice($argv,1) as $path){
        if(!is_file($path)||is_link($path)||filesize($path)===false||filesize($path)>2*1024*1024)
            throw new InvalidArgumentException('Regular research source file required');
        $raw=file_get_contents($path);
        if(!is_string($raw))throw new RuntimeException('Could not read research input');
        $sources[]=$raw;
    }
    $result=ResearchDeltaReview::review(...$sources);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,'Research review denied, no DB update: '.get_class($e).': '.$e->getMessage()."\n");exit(1);}
