<?php
declare(strict_types=1);
/** Private, deterministic site staging from a released research dataset. No DB actions. */
if (PHP_SAPI!=='cli') {http_response_code(403);exit(1);}
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\ResearchAcceptedFeed;
try {
    if (count($argv)!==4) throw new InvalidArgumentException('Usage: php export-accepted-research-feed.php MANIFEST.json UPDATES.json REPORT.md');
    $data=[];
    foreach(array_slice($argv,1) as $file){
        if(!is_file($file)||is_link($file)||filesize($file)===false||filesize($file)>2*1024*1024)
            throw new DomainException('Expected a bounded regular research source');
        $bytes=file_get_contents($file);
        if(!is_string($bytes))throw new RuntimeException('Could not read research file');
        $data[]=$bytes;
    }
    echo json_encode(ResearchAcceptedFeed::make(...$data),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
} catch(Throwable $e) {
    fwrite(STDERR,'Research release intake refused; no DB writes: '.get_class($e).' '.$e->getMessage()."\n");exit(1);
}
