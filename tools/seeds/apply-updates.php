<?php
declare(strict_types=1);
/** Explicit operator-only CLI: dry-run default, never auto-publishes new companies. */
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\{Database,SeedRepository,SeedEntityRepository,SeedCompositeRepository,SiteState};
if(count($argv)<2||count($argv)>5){fwrite(STDERR,"Usage: php tools/seeds/apply-updates.php FILE.json [--apply --confirm | --enroll --apply --confirm]\n");exit(2);}
$file=(string)$argv[1];$args=array_slice($argv,2);
if($args!==[] && $args!==['--apply','--confirm'] && $args!==['--enroll','--apply','--confirm']){fwrite(STDERR,"For a write, specify BOTH --apply and --confirm in this order.\n");exit(2);}
if(!is_file($file)||filesize($file)===false||filesize($file)>1024*1024){fwrite(STDERR,"Readable JSON file, <=1MiB required\n");exit(2);}
try{
    $payload=json_decode((string)file_get_contents($file),true,256,JSON_THROW_ON_ERROR);
    if(!is_array($payload))throw new InvalidArgumentException('JSON object required');
    $isComposite=array_key_exists('company_batch',$payload)||array_key_exists('entity_batch',$payload);
    $isEntity=array_key_exists('records',$payload);
    if($isComposite && ($isEntity || array_key_exists('companies',$payload)))throw new InvalidArgumentException('Composite feed must use company_batch and entity_batch only');
    if($isEntity && array_key_exists('companies',$payload))throw new InvalidArgumentException('Do not mix company and entity batches; use separate event keys');
    if((!$isEntity || $isComposite) && $args===['--enroll','--apply','--confirm'])throw new InvalidArgumentException('Enrollment is for entity records only');
    $plan=$isComposite?SeedCompositeRepository::validate($payload):($isEntity?SeedEntityRepository::validate($payload):SeedRepository::validate($payload));
    $count=$isComposite?(count($plan['company_batch']['companies'])+count($plan['entity_batch']['records'])):count($isEntity?$plan['records']:$plan['companies']);
    $origin=$isComposite?$plan['company_batch']['source_document']:$plan['source_document'];
    echo 'VALIDATED: '.$count.($isComposite?' composite':($isEntity?' entity':' company')).' snapshots; origin='.$origin."\n";
    if($args===[]){echo "DRY RUN: no database accessed. Composite batches require both domains conflict-free; conflicts force complete rollback.\n";exit(0);}
    if(SiteState::state()!=='ready')throw new RuntimeException('Installation is not ready');
    $result=$isComposite?SeedCompositeRepository::apply(Database::connected(),$payload):($isEntity
       ?($args===['--enroll','--apply','--confirm']
         ?['status'=>'enrolled','records'=>SeedEntityRepository::enroll(Database::connected(),$payload)]
         :SeedEntityRepository::apply(Database::connected(),$payload))
       :SeedRepository::apply(Database::connected(),$payload));
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
    echo "Queued system notices are delivered by php tools/messages/deliver.php only after all conflicts resolve.\n";
}catch(Throwable $e){fwrite(STDERR,'Seed update not applied: '.get_class($e).': '.$e->getMessage()."\n");exit(1);}
