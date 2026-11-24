<?php
declare(strict_types=1);
// External isolated verification only: actual reader queries, no writes or mode switch.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../app/Repository.php';
try{
 $o=getopt('',['storage:','confirm-isolated-reader']);$db=getenv('CP_READER_DATABASE')?:'';
 if(!isset($o['confirm-isolated-reader'],$o['storage'])||!is_string($o['storage'])||!preg_match('/^codepublication_test_[a-z0-9_]{1,30}$/D',$db))throw new RuntimeException('explicit test reader confirmation required');
 $root=realpath($o['storage']);if($root===false||is_link($o['storage'])||!is_dir($root))throw new RuntimeException('invalid storage');
 $r=new Repository($root,'mysql');$cat=$r->catalog();$count=0;
 foreach($cat['versions'] as $row){$tag=$row['tag'];$f=$r->files($tag);foreach(array_keys($f) as $path){$r->file($tag,$path,$f);$count++;}$r->archive($tag,'zip',$row);$r->archive($tag,'tar.gz',$row);$r->changes($tag);}
 echo json_encode(['result'=>'PASS','versions'=>count($cat['versions']),'files'=>$count,'latest'=>$cat['latest'],'release_gate'=>'HOLD'],JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){fwrite(STDERR,'Isolated reader verification refused or failed; inspect privately.'.PHP_EOL);exit(1);}
