<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\ResearchExternalLinks;
if(PHP_SAPI!=='cli'||count($argv)!==3){fwrite(STDERR,"Usage: php plan-research-external-links.php DATA_MANIFEST_d1.0.2.json JOB_LINK_CATALOG_d1.0.2.json\n");exit(2);}
try{
    $m=file_get_contents($argv[1]);$c=file_get_contents($argv[2]);
    if(!is_string($m)||!is_string($c))throw new RuntimeException('Source files unavailable');
    echo json_encode(ResearchExternalLinks::parse($m,$c),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)."\n";
}catch(Throwable $e){fwrite(STDERR,"Invalid pinned release: ".get_class($e)."\n");exit(1);}
