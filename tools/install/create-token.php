<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit(1);}
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\{SiteState,Database};
if(SiteState::state()!=='new'){fwrite(STDERR,"Site already installed or recovery required.\n");exit(1);}
$dir=SiteState::root().'/storage/system';
if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)){fwrite(STDERR,"Cannot create secure storage\n");exit(1);}
$token=bin2hex(random_bytes(32));$expiry=time()+3600;
SiteState::writeAtomic($dir.'/setup-token.json',json_encode(['hash'=>hash('sha256',$token),'expires'=>$expiry],JSON_THROW_ON_ERROR));
echo "ONE-TIME SETUP TOKEN (expires in 60 min, keep private):\n".$token."\n";
