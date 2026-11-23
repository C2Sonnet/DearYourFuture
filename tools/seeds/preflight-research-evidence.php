<?php
declare(strict_types=1);
/** Explicit operator-supplied research identity evidence: local read-only preflight. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\ResearchDeltaReview;
use KMM\Core\ResearchIdentityReconciliation;
use KMM\Core\ResearchIdentityEvidence;
try{
    if(count($argv)!==6)throw new InvalidArgumentException('Usage: php tools/seeds/preflight-research-evidence.php MANIFEST.json UPDATES.json REPORT.md SITE_SEEDS.json PROPOSALS.json');
    $raw=[];
    foreach(array_slice($argv,1) as $path){
        if(!is_file($path)||is_link($path)||filesize($path)===false||filesize($path)>2*1024*1024)
            throw new InvalidArgumentException('Bounded regular local input files only');
        $bytes=file_get_contents($path);
        if(!is_string($bytes))throw new RuntimeException('Failed to read evidence file');
        $raw[]=$bytes;
    }
    $review=ResearchDeltaReview::review($raw[0],$raw[1],$raw[2]);
    $catalogue=json_decode($raw[3],true,128,JSON_THROW_ON_ERROR);
    $submission=json_decode($raw[4],true,128,JSON_THROW_ON_ERROR);
    if(!is_array($catalogue)||!is_array($submission)||array_is_list($submission))throw new DomainException('Malformed JSON object');
    $plan=ResearchIdentityReconciliation::plan($review,$catalogue);
    $result=ResearchIdentityEvidence::preflight($plan,$catalogue,$submission);
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,'Research evidence preflight rejected; no DB changes: '.get_class($e).': '.$e->getMessage()."\n");exit(1);}
