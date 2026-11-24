<?php
declare(strict_types=1);
// Isolated migration rehearsal only. Never changes the live catalog or switches the Web reader.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function demand(bool $ok,string $reason):void {if(!$ok)throw new RuntimeException($reason);}
function canonical(mixed $v):string {
 if(is_array($v)){if(!array_is_list($v))ksort($v);foreach($v as &$x)$x=json_decode(canonical($x),true,512,JSON_THROW_ON_ERROR);unset($x);}
 return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}
function loadPlan(string $path,string $hash):array {
 demand(preg_match('/^[a-f0-9]{64}$/D',$hash)===1,'expected SHA256 required');
 demand(is_file($path)&&!is_link($path)&&filesize($path)<=100_000_000,'plan unavailable or too large');
 $raw=file_get_contents($path);demand($raw!==false&&hash_equals($hash,hash('sha256',$raw)),'plan SHA256 mismatch');
 $p=json_decode($raw,true,512,JSON_THROW_ON_ERROR);demand(($p['format']??null)===1&&preg_match('/^[a-f0-9]{64}$/D',$p['catalog_sha256']??'')===1,'invalid plan');
 $known=[];$prev=null;$n=0;
 foreach($p['releases'] as $r){$m=$r['metadata'];$tag=$m['tag'];demand(preg_match('/^v\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?$/D',$tag)===1&&strlen($tag)<=128&&!isset($known[$tag])&&$m['parent']===$prev&&preg_match('/^[a-f0-9]{64}$/D',$m['digest'])===1,'invalid release sequence');
  $files=[];foreach($r['files'] as $f){$path=$f['path'];$last=$f['last_changed'];demand(is_string($path)&&$path!==''&&strlen($path)<=65535&&!isset($files[$path])&&preg_match('/^[a-f0-9]{64}$/D',$f['sha256'])===1&&is_int($f['bytes'])&&$f['bytes']>=0,'invalid file');demand($last===$tag||isset($known[$last][$path])&&$known[$last][$path]===$f['sha256'],'invalid last_changed');$files[$path]=$f['sha256'];$n++;}
  demand(count($files)===$m['files'],'file count mismatch');$known[$tag]=$files;$prev=$tag;
 }
 demand($prev!==null&&$p['latest']===$prev&&$p['summary']['latest']===$prev&&count($known)===$p['summary']['versions']&&$n===$p['summary']['source_files'],'summary mismatch');return $p;
}
try {
 $o=getopt('',['plan:','sha256:','apply','confirm-isolated-db']);demand(isset($o['plan'],$o['sha256'])&&is_string($o['plan'])&&is_string($o['sha256']),'usage: --plan=FILE --sha256=HASH [--apply --confirm-isolated-db]');
 $p=loadPlan($o['plan'],$o['sha256']);echo json_encode($p['summary'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
 if(!isset($o['apply'])){echo "Offline plan validation PASS; no database accessed\n";exit;}
 demand(isset($o['confirm-isolated-db']),'explicit isolated DB confirmation required');
 demand(in_array('mysql',PDO::getAvailableDrivers(),true),'PDO MySQL unavailable');
 $db=getenv('CP_MYSQL_DATABASE')?:'';$host=getenv('CP_MYSQL_HOST')?:'127.0.0.1';$port=getenv('CP_MYSQL_PORT')?:'3306';$user=getenv('CP_MYSQL_USER')?:'';$password=getenv('CP_MYSQL_PASSWORD');
 demand(preg_match('/^codepublication_[a-z0-9_]{1,40}$/D',$db)===1&&in_array($host,['127.0.0.1','localhost','::1'],true)&&ctype_digit($port)&&(int)$port>0&&(int)$port<=65535&&$user!==''&&$password!==false,'dedicated local database configuration required');
 $pdo=new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
 demand($pdo->query('SELECT DATABASE()')->fetchColumn()===$db,'database mismatch');
 $lock=$pdo->prepare('SELECT GET_LOCK(?, 0)');$lock->execute(['cp-migration:'.$db]);demand((int)$lock->fetchColumn()===1,'migration lock unavailable');
 try {
  demand(count($pdo->query('SHOW FULL TABLES')->fetchAll())===0,'database must be empty; no overwrite or automatic cleanup');
  $sql=file_get_contents(__DIR__.'/../database/migrations/001_catalog_design.sql');demand($sql!==false,'schema missing');
  // DDL is not transactional. Failure intentionally leaves the isolated DB for manual inspection.
  foreach(explode(';',preg_replace('/^--[^\n]*$/m','',$sql)) as $statement)if(trim($statement)!=='')$pdo->exec($statement);
  $pdo->beginTransaction();
  $ins=$pdo->prepare('INSERT INTO releases(tag,parent_id,source_sha256,upstream_commit,upstream_parent,published_at,metadata) VALUES(?,?,?,?,?,?,?)');
  $file=$pdo->prepare('INSERT INTO release_files(release_id,path_sha256,path,sha256,byte_size,last_changed_release_id) VALUES(?,UNHEX(?),?,?,?,?)');
  $ids=[];foreach($p['releases'] as $r){$m=$r['metadata'];$tag=$m['tag'];$when=(new DateTimeImmutable($m['imported']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');$ins->execute([$tag,$m['parent']===null?null:$ids[$m['parent']],$m['digest'],$m['upstream_commit'],$m['upstream_parent'],$when,canonical($m)]);$ids[$tag]=$pdo->lastInsertId();foreach($r['files'] as $f)$file->execute([$ids[$tag],hash('sha256',$f['path']),$f['path'],$f['sha256'],$f['bytes'],$ids[$f['last_changed']]]);}
  $st=$pdo->prepare("INSERT INTO publication_state VALUES(1,?,1,?,'READY')");$st->execute([$ids[$p['latest']],$p['catalog_sha256']]);
  $pdo->prepare('INSERT INTO schema_migrations VALUES(1,UTC_TIMESTAMP(6),?)')->execute([hash('sha256',$sql)]);
  $pdo->prepare("INSERT INTO publication_events(occurred_at,release_id,event_type,details) VALUES(UTC_TIMESTAMP(6),?,'ISOLATED_IMPORT',?)")->execute([$ids[$p['latest']],canonical($p['summary'])]);
  $actual=$pdo->query('SELECT r.tag, p.tag AS parent_tag,r.source_sha256,r.metadata FROM releases r LEFT JOIN releases p ON r.parent_id=p.id ORDER BY r.id')->fetchAll(PDO::FETCH_ASSOC);
  demand(count($actual)===count($p['releases']),'release readback count mismatch');
  $q=$pdo->prepare('SELECT f.path,LOWER(HEX(f.path_sha256)) AS path_hash,f.sha256,f.byte_size,r.tag AS last_changed FROM release_files f JOIN releases r ON f.last_changed_release_id=r.id WHERE f.release_id=?');
  foreach($p['releases'] as $i=>$r){$m=$r['metadata'];$a=$actual[$i];demand($a['tag']===$m['tag']&&$a['parent_tag']===$m['parent']&&$a['source_sha256']===$m['digest']&&canonical(json_decode($a['metadata'],true,512,JSON_THROW_ON_ERROR))===canonical($m),'release readback mismatch');$q->execute([$ids[$m['tag']]]);$read=$q->fetchAll(PDO::FETCH_ASSOC);$by=[];foreach($read as $f)$by[$f['path']]=$f;demand(count($by)===count($r['files']),'file readback count mismatch');foreach($r['files'] as $f){$a=$by[$f['path']]??null;demand($a!==null&&$a['path_hash']===hash('sha256',$f['path'])&&$a['sha256']===$f['sha256']&&(int)$a['byte_size']===$f['bytes']&&$a['last_changed']===$f['last_changed'],'file readback mismatch');}}
  $state=$pdo->query('SELECT latest_release_id,catalog_sha256,state FROM publication_state WHERE singleton=1')->fetch(PDO::FETCH_ASSOC);demand((string)$state['latest_release_id']===(string)$ids[$p['latest']]&&$state['catalog_sha256']===$p['catalog_sha256']&&$state['state']==='READY','state mismatch');$pdo->commit();echo "Isolated import/readback complete; Web reader unchanged\n";
 } finally {if($pdo->inTransaction())$pdo->rollBack();$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['cp-migration:'.$db]);}
} catch(Throwable $e){fwrite(STDERR,($e instanceof PDOException?'Database operation failed; inspect isolated DB privately.':$e->getMessage()).PHP_EOL);exit(1);}
