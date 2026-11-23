<?php
declare(strict_types=1);
/** Generates an entirely PRIVATE technical mapping plan; never connects to DB. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\ResearchTechnicalMapping;
if(count($argv)!==3&&count($argv)!==4){fwrite(STDERR,"Usage: php tools/seeds/plan-accepted-research-mapping.php ACCEPTED_FEED.json CATALOGUE.json [BINDINGS.json]\n");exit(2);}
try{
    $load=static function(string $path,int $maxBytes):array {
        if(!is_file($path)||is_link($path)||filesize($path)>$maxBytes||filesize($path)<2)throw new DomainException('Missing or oversized input');
        $data=json_decode((string)file_get_contents($path),true,128,JSON_THROW_ON_ERROR);
        if(!is_array($data))throw new DomainException('Expected JSON object');return $data;
    };
    $feed=$load($argv[1],262144);
    $catalogue=$load($argv[2],131072);
    $bindings=isset($argv[3])?$load($argv[3],32768):[];
    $result=ResearchTechnicalMapping::plan($feed,$catalogue,$bindings);
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){fwrite(STDERR,"Private technical plan rejected (no DB write): ".get_class($e)."\n");exit(1);}
