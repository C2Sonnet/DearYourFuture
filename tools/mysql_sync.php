<?php
declare(strict_types=1);
// Isolated append-only synchronization rehearsal. Never switches the Web reader.
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
// Both plans must have been independently audited with mysql_plan.py.
function delta(array $old,array $new):array {
 $n=count($old['releases']);demand(count($new['releases']) >= $n,'release removal refused');
 for($i=0;$i<$n;$i++)demand(canonical($old['releases'][$i])===canonical($new['releases'][$i]),'existing release changed');
 $added=array_slice($new['releases'],$n);
 demand(($added===[])===($old['catalog_sha256']===$new['catalog_sha256']),'catalog hash/release sequence mismatch');
 return $added;
}
function readback(PDO $pdo,array $p):array {
 $actual=$pdo->query('SELECT r.id,r.tag,p.tag AS parent_tag,r.source_sha256,r.upstream_commit,r.upstream_parent,r.published_at,r.metadata FROM releases r LEFT JOIN releases p ON r.parent_id=p.id ORDER BY r.id')->fetchAll(PDO::FETCH_ASSOC);
 demand(count($actual)===count($p['releases']),'release count mismatch');$ids=[];$total=0;
 $q=$pdo->prepare('SELECT f.path,LOWER(HEX(f.path_sha256)) AS path_hash,f.sha256,f.byte_size,r.tag AS last_changed FROM release_files f JOIN releases r ON f.last_changed_release_id=r.id WHERE f.release_id=?');
 foreach($p['releases'] as $i=>$r){$m=$r['metadata'];$a=$actual[$i];$tag=$m['tag'];$when=(new DateTimeImmutable($m['imported']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
  demand($a['tag']===$tag&&$a['parent_tag']===$m['parent']&&$a['source_sha256']===$m['digest']&&$a['upstream_commit']===$m['upstream_commit']&&$a['upstream_parent']===$m['upstream_parent']&&$a['published_at']===$when&&canonical(json_decode($a['metadata'],true,512,JSON_THROW_ON_ERROR))===canonical($m),'release readback mismatch');
  $ids[$tag]=$a['id'];$q->execute([$a['id']]);$read=$q->fetchAll(PDO::FETCH_ASSOC);$by=[];foreach($read as $f)$by[$f['path']]=$f;
  demand(count($read)===count($r['files'])&&count($by)===count($r['files']),'file count mismatch');
  foreach($r['files'] as $f){$a=$by[$f['path']]??null;demand($a!==null&&$a['path_hash']===hash('sha256',$f['path'])&&$a['sha256']===$f['sha256']&&(int)$a['byte_size']===$f['bytes']&&$a['last_changed']===$f['last_changed'],'file readback mismatch');$total++;}
 }
 demand((int)$pdo->query('SELECT COUNT(*) FROM release_files')->fetchColumn()===$total,'unexpected file rows');
 $s=$pdo->query('SELECT singleton,latest_release_id,generation,catalog_sha256,state FROM publication_state')->fetchAll(PDO::FETCH_ASSOC);
 demand(count($s)===1&&(int)$s[0]['singleton']===1&&(string)$s[0]['latest_release_id']===(string)$ids[$p['latest']]&&$s[0]['catalog_sha256']===$p['catalog_sha256']&&$s[0]['state']==='READY'&&(int)$s[0]['generation']>=1,'publication state mismatch');
 return [$ids,$s[0]];
}
function insertAdded(PDO $pdo,array $added,array $ids):array {
 $ins=$pdo->prepare('INSERT INTO releases(tag,parent_id,source_sha256,upstream_commit,upstream_parent,published_at,metadata) VALUES(?,?,?,?,?,?,?)');
 $file=$pdo->prepare('INSERT INTO release_files(release_id,path_sha256,path,sha256,byte_size,last_changed_release_id) VALUES(?,UNHEX(?),?,?,?,?)');
 foreach($added as $r){$m=$r['metadata'];$tag=$m['tag'];$when=(new DateTimeImmutable($m['imported']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');$ins->execute([$tag,$m['parent']===null?null:$ids[$m['parent']],$m['digest'],$m['upstream_commit'],$m['upstream_parent'],$when,canonical($m)]);$ids[$tag]=$pdo->lastInsertId();foreach($r['files'] as $f)$file->execute([$ids[$tag],hash('sha256',$f['path']),$f['path'],$f['sha256'],$f['bytes'],$ids[$f['last_changed']]]);}
 return $ids;
}
function checkStorage(string $root,array $p):void {
 demand(!is_link($root.'/catalog.json')&&!is_link($root.'/system/update.lock')&&!is_link($root.'/system/install.lock'),'unsafe storage metadata');
 demand(!file_exists($root.'/system/transaction.json')&&!is_link($root.'/system/transaction.json'),'pending filesystem update');
 demand(hash_file('sha256',$root.'/catalog.json')===$p['catalog_sha256'],'current catalog differs from target plan');
 $ready=json_decode(file_get_contents($root.'/system/update.lock'),true,512,JSON_THROW_ON_ERROR);
 demand(canonical($ready)===canonical(['state'=>'READY','latest'=>$p['latest'],'catalog_sha256'=>$p['catalog_sha256']]),'filesystem not READY');
 demand(json_decode(file_get_contents($root.'/system/install.lock'),true,512,JSON_THROW_ON_ERROR)===['schema'=>1],'filesystem install state mismatch');
}
try {
 $o=getopt('',['previous:','previous-sha256:','target:','target-sha256:','storage:','schema:','apply','confirm-isolated-db','test-fault:']);
 foreach(['previous','previous-sha256','target','target-sha256'] as $k)demand(isset($o[$k])&&is_string($o[$k]),'required: previous/previous-sha256/target/target-sha256');
 demand(!isset($o['test-fault'])||(isset($o['apply'],$o['confirm-isolated-db'])&&in_array($o['test-fault'],['before-commit','after-commit'],true)),'fault injection requires explicit isolated apply');
 $old=loadPlan($o['previous'],$o['previous-sha256']);$new=loadPlan($o['target'],$o['target-sha256']);$added=delta($old,$new);
 $report=['previous'=>$old['latest'],'target'=>$new['latest'],'added_releases'=>array_map(fn($r)=>$r['metadata']['tag'],$added),'added_files'=>array_sum(array_map(fn($r)=>count($r['files']),$added))];
 if(!isset($o['apply'])){
  $checked=false;
  if(isset($o['storage'])){demand(is_string($o['storage']),'invalid storage');$root=realpath($o['storage']);demand($root!==false&&is_dir($root)&&!is_link($o['storage'])&&is_dir($root.'/system')&&!is_link($root.'/system'),'invalid storage');$path=$root.'/system/publish.mutex';demand(is_file($path)&&!is_link($path),'existing publication mutex required');$mutex=fopen($path,'r');demand($mutex!==false&&flock($mutex,LOCK_SH|LOCK_NB),'filesystem lock unavailable');try{checkStorage($root,$new);$checked=true;}finally{flock($mutex,LOCK_UN);fclose($mutex);}}
  echo json_encode($report+['mode'=>'offline','database_accessed'=>false,'storage_catalog_checked'=>$checked],JSON_THROW_ON_ERROR).PHP_EOL;exit;}
 demand(isset($o['confirm-isolated-db']),'explicit isolated DB confirmation required');
 demand(in_array('mysql',PDO::getAvailableDrivers(),true),'PDO MySQL unavailable');
 demand(isset($o['storage'],$o['schema'])&&is_string($o['storage'])&&is_string($o['schema']),'storage and schema required');
 $root=realpath($o['storage']);demand($root!==false&&is_dir($root)&&!is_link($o['storage'])&&is_dir($root.'/system')&&!is_link($root.'/system'),'invalid storage');
 $mutexPath=$root.'/system/publish.mutex';demand(is_file($mutexPath)&&!is_link($mutexPath),'existing publication mutex required');
 $mutex=fopen($mutexPath,'r+');demand($mutex!==false&&flock($mutex,LOCK_EX|LOCK_NB),'filesystem lock unavailable');
 try {
  checkStorage($root,$new);
  $db=getenv('CP_MYSQL_DATABASE')?:'';$host=getenv('CP_MYSQL_HOST')?:'127.0.0.1';$port=getenv('CP_MYSQL_PORT')?:'3306';$user=getenv('CP_MYSQL_USER')?:'';$password=getenv('CP_MYSQL_PASSWORD');
  demand(preg_match('/^codepublication_[a-z0-9_]{1,40}$/D',$db)===1&&in_array($host,['127.0.0.1','localhost','::1'],true)&&ctype_digit($port)&&(int)$port>0&&(int)$port<=65535&&$user!==''&&$password!==false,'dedicated local database configuration required');
  demand(!isset($o['test-fault'])||str_starts_with($db,'codepublication_test_'),'fault injection requires test database');
   $pdo=new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
  demand($pdo->query('SELECT DATABASE()')->fetchColumn()===$db,'database mismatch');
  $lock=$pdo->prepare('SELECT GET_LOCK(?,0)');$lock->execute(['cp-migration:'.$db]);demand((int)$lock->fetchColumn()===1,'database lock unavailable');
  try {
   // Only the known, already-migrated five-table schema is accepted. No DDL or deletion.
   demand(is_file($o['schema'])&&!is_link($o['schema']),'schema unavailable');$schemaHash=hash_file('sha256',$o['schema']);demand($schemaHash==='bfda63615a0661dd009d6c9c5441a0c6cb22feaadb301b739a84f27c1c625bc9','unrecognized schema file');
   $tables=$pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM);$names=array_column($tables,0);sort($names);demand($names===['publication_events','publication_state','release_files','releases','schema_migrations']&&count(array_filter($tables,fn($t)=>$t[1]==='BASE TABLE'))===5,'unexpected database schema');
   $engines=$pdo->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()")->fetchAll(PDO::FETCH_COLUMN);demand(count($engines)===5&&count(array_filter($engines,fn($e)=>strtoupper((string)$e)==='INNODB'))===5,'transactional tables required');
   $pdo->beginTransaction();
   $schema=$pdo->query('SELECT version,checksum FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC);demand(count($schema)===1&&(int)$schema[0]['version']===1&&$schema[0]['checksum']===$schemaHash,'schema checksum mismatch');
   $state=$pdo->query('SELECT catalog_sha256 FROM publication_state WHERE singleton=1 FOR UPDATE')->fetchColumn();
   if($state===$new['catalog_sha256']){readback($pdo,$new);$pdo->rollBack();echo json_encode($report+['mode'=>'already-synchronized','database_accessed'=>true],JSON_THROW_ON_ERROR).PHP_EOL;}else{
   demand($state===$old['catalog_sha256'],'database does not match previous plan');
   [$ids,$prior]=readback($pdo,$old);$ids=insertAdded($pdo,$added,$ids);
   $update=$pdo->prepare("UPDATE publication_state SET latest_release_id=?,generation=generation+1,catalog_sha256=? WHERE singleton=1 AND generation=? AND catalog_sha256=? AND state='READY'");$update->execute([$ids[$new['latest']],$new['catalog_sha256'],$prior['generation'],$old['catalog_sha256']]);demand($update->rowCount()===1,'state changed concurrently');
   $pdo->prepare("INSERT INTO publication_events(occurred_at,release_id,event_type,details) VALUES(UTC_TIMESTAMP(6),?,'ISOLATED_SYNC',?)")->execute([$ids[$new['latest']],canonical($report)]);
   [, $final]=readback($pdo,$new);demand((int)$final['generation']===(int)$prior['generation']+1,'generation mismatch');checkStorage($root,$new);if(($o['test-fault']??null)==='before-commit')throw new RuntimeException('injected before commit');$pdo->commit();if(($o['test-fault']??null)==='after-commit')throw new RuntimeException('injected after commit');
   echo json_encode($report+['mode'=>'isolated-sync','database_accessed'=>true],JSON_THROW_ON_ERROR).PHP_EOL;}
  }finally{if($pdo->inTransaction())$pdo->rollBack();$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['cp-migration:'.$db]);}
 }finally{flock($mutex,LOCK_UN);fclose($mutex);}
}catch(Throwable $e){fwrite(STDERR,($e instanceof PDOException?'Database operation failed; inspect isolated DB privately.':$e->getMessage()).PHP_EOL);exit(1);}
