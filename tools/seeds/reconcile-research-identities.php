<?php
declare(strict_types=1);
/** CLI-only, no DB connect: research source integrity + seed catalogue identity suggestions. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\ResearchDeltaReview;
use KMM\Core\ResearchIdentityReconciliation;
try{
    if(count($argv)!==5)throw new InvalidArgumentException('Usage: php tools/seeds/reconcile-research-identities.php MANIFEST.json UPDATES.json REPORT.md SITE_SEEDS.json');
    $raw=[];
    foreach(array_slice($argv,1) as $path){
        if(!is_file($path)||is_link($path)||filesize($path)===false||filesize($path)>2*1024*1024)
            throw new InvalidArgumentException('A bounded, regular local input file is required');
        $bytes=file_get_contents($path);
        if(!is_string($bytes))throw new RuntimeException('Unable to read input');
        $raw[]=$bytes;
    }
    $review=ResearchDeltaReview::review($raw[0],$raw[1],$raw[2]);
    $catalogue=json_decode($raw[3],true,128,JSON_THROW_ON_ERROR);
    if(!is_array($catalogue))throw new DomainException('Seed catalogue must be an object');
    $out=ResearchIdentityReconciliation::plan($review,$catalogue);
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,'Read-only manual identity plan rejected; no database writes: '.get_class($e).': '.$e->getMessage()."\n");exit(1);}
