<?php
/** Local operator CLI: DB URL, config reference inventory, private audit and rollback data. */
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
umask(0077);
require dirname(__DIR__,2).'/app/autoload.php';
use KMM\Core\Database;
use KMM\Core\SiteState;
function domainUrl(string $url):bool {return preg_match('~\Ahttps://(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,63}\z~D',$url)===1 && strlen($url)<=253;}
try{
 $opts=getopt('',['url:','plan','apply','confirm','files-reviewed']);
 $url=$opts['url']??null;if(!is_string($url)||!domainUrl($url)||isset($opts['plan'],$opts['apply']))throw new RuntimeException('Usage: php tools/system/change-domain.php --url=https://new.example --plan OR --apply --confirm --files-reviewed');
 if(SiteState::state()!=='ready')throw new RuntimeException('Installation is not READY');
 $root=SiteState::root();$db=Database::connected();$old=Database::settings($db,'site.url');if(!is_string($old)||!domainUrl($old))throw new RuntimeException('Existing URL format requires manual review');
 $host=parse_url($old,PHP_URL_HOST);$matches=[];
 $files=array_merge(['.env','public/.user.ini','public/.htaccess'],glob($root.'/deploy/*.conf')?:[],glob($root.'/deploy/*.example')?:[]);
 foreach($files as $f){$p=str_starts_with($f,$root.'/')?$f:$root.'/'.$f;if(!is_file($p)||is_link($p)||filesize($p)>1048576)continue;$raw=file_get_contents($p);if(is_string($raw)&&stripos($raw,(string)$host)!==false)$matches[]=substr($p,strlen($root)+1);}
 echo json_encode(['old_url'=>$old,'new_url'=>$url,'file_references_to_review'=>$matches,'external_settings'=>'DNS, TLS, vhost, proxy, cookie and API referrer restrictions must be checked separately'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
 if(!isset($opts['apply'])){echo "PLAN ONLY: no changes.\n";exit(0);}
 if(!isset($opts['confirm'],$opts['files-reviewed']))throw new RuntimeException('Confirm configuration review and backups before apply');
 if($matches)throw new RuntimeException('Old host still occurs in selected local config files; edit and recheck them first');
 if($old===$url){echo "Already configured.\n";exit(0);}
 $dir=$root.'/storage/backups';if(is_link($dir)||(!is_dir($dir)&&!mkdir($dir,0700,true))||(fileperms($dir)&0077))throw new RuntimeException('Private backup directory unavailable');
 $backup=$dir.'/domain-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(6)).'.json';$record=json_encode(['previous_url'=>$old,'new_url'=>$url,'utc'=>gmdate('c')],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
 if(file_put_contents($backup,$record,LOCK_EX)!==strlen($record)||!chmod($backup,0600))throw new RuntimeException('Backup creation failed');
 $db->beginTransaction();$q=$db->prepare('SELECT value_json FROM system_settings WHERE setting_key=? FOR UPDATE');$q->execute(['site.url']);if(json_decode((string)$q->fetchColumn(),true)!==$old)throw new RuntimeException('Site URL changed during review');
 Database::setSetting($db,'site.url',$url);$db->prepare('INSERT INTO audit_logs(actor_id,event_type,target_type,target_key,details_json) VALUES(NULL,?,?,?,?)')->execute(['site.url.changed','setting','site.url',$record]);$db->commit();echo "URL updated. Recheck new HTTPS and email links.\n";
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();fwrite(STDERR,'DOMAIN CHANGE REFUSED: '.($e instanceof RuntimeException && !($e instanceof PDOException)?$e->getMessage():get_class($e)).PHP_EOL);exit(1);}
