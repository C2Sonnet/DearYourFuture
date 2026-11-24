<?php
declare(strict_types=1);
/** Read-only database projection; SQL access and validation are separable for offline tests. */
interface CatalogReadModel {
 public function catalog(array $disk,string $digest):array;
 public function files(string $tag,array $disk):array;
}
final class MysqlReadModel implements CatalogReadModel {
 private const SCHEMA_SHA='bfda63615a0661dd009d6c9c5441a0c6cb22feaadb301b739a84f27c1c625bc9';
 private array $ids=[];
 public function __construct(private PDO $pdo){}
 public static function connect():self {
  if(!in_array('mysql',PDO::getAvailableDrivers(),true))throw new RuntimeException('MySQL reader unavailable');
  $db=getenv('CP_READER_DATABASE')?:'';$host=getenv('CP_READER_HOST')?:'127.0.0.1';$port=getenv('CP_READER_PORT')?:'3306';$user=getenv('CP_READER_USER')?:'';$pass=getenv('CP_READER_PASSWORD');
  if(!preg_match('/^codepublication_[a-z0-9_]{1,40}$/D',$db)||!in_array($host,['127.0.0.1','localhost','::1'],true)||!ctype_digit($port)||(int)$port<1||(int)$port>65535||$user===''||$pass===false)throw new RuntimeException('invalid dedicated reader configuration');
  $pdo=new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>3,PDO::ATTR_PERSISTENT=>false]);
  if($pdo->query('SELECT DATABASE()')->fetchColumn()!==$db)throw new RuntimeException('database mismatch');
  $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
  $pdo->exec('START TRANSACTION READ ONLY');
  return new self($pdo);
 }
 public static function normalize(mixed $v):mixed {if(is_array($v)){if(!array_is_list($v))ksort($v);foreach($v as &$x)$x=self::normalize($x);unset($x);}return $v;}
 private static function check(bool $ok):void {if(!$ok)throw new RuntimeException('database and storage projection mismatch');}
 public static function validateCatalog(array $disk,string $digest,array $schema,array $state,array $rows):array {
  self::check(count($schema)===1&&(string)$schema[0]['version']==='1'&&$schema[0]['checksum']===self::SCHEMA_SHA);
  self::check(count($state)===1&&(string)$state[0]['singleton']==='1'&&$state[0]['state']==='READY'&&preg_match('/^[1-9][0-9]*$/D',(string)$state[0]['generation'])===1&&hash_equals($digest,$state[0]['catalog_sha256']));
  $expected=array_reverse($disk['versions']);self::check(count($rows)===count($expected)&&count($rows)>0);$versions=[];$ids=[];$prior=null;
  foreach($rows as $i=>$row){$m=json_decode($row['metadata'],true,512,JSON_THROW_ON_ERROR);$tag=$row['tag'];$id=(string)$row['id'];
   self::check(is_array($m)&&preg_match('/^[1-9][0-9]*$/D',$id)===1&&!isset($ids[$tag])&&!in_array($id,$ids,true)&&$row['parent_tag']===$prior&&$m['tag']===$tag&&$m['parent']===$prior&&$m['digest']===$row['source_sha256']&&$m['upstream_commit']===$row['upstream_commit']&&$m['upstream_parent']===$row['upstream_parent']);
   $when=(new DateTimeImmutable($m['imported']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
   self::check($row['published_at']===$when&&self::normalize($m)===self::normalize($expected[$i])&&isset($m['files_sha256'],$m['tar_sha256'],$m['comparison_hashes']));
   $versions[]=$m;$ids[$tag]=$id;$prior=$tag;
  }
  self::check($prior===$disk['latest']&&(string)$state[0]['latest_release_id']===$ids[$prior]);
  return [['schema'=>1,'latest'=>$prior,'versions'=>array_reverse($versions)],$ids];
 }
 public static function validateFiles(string $tag,array $disk,array $rows):array {
  self::check(count($disk)===count($rows));$out=[];
  foreach($rows as $row){$path=$row['path'];self::check(is_string($path)&&isset($disk[$path])&&!isset($out[$path])&&$row['path_hash']===hash('sha256',$path));$f=$disk[$path];
   self::check($row['sha256']===$f['sha256']&&(string)$row['byte_size']===(string)$f['bytes']&&$row['last_changed']===($f['last_changed']??$tag));
   $out[$path]=array_replace($f,['sha256'=>$row['sha256'],'bytes'=>(int)$row['byte_size'],'last_changed'=>$row['last_changed']]);
  }
  // Preserve existing display order; data values above come from the verified database projection.
  $sorted=[];foreach($disk as $path=>$f)$sorted[$path]=$out[$path];return $sorted;
 }
 public function catalog(array $disk,string $digest):array {
  $schema=$this->pdo->query('SELECT version,checksum FROM schema_migrations')->fetchAll(PDO::FETCH_ASSOC);
  $state=$this->pdo->query('SELECT singleton,latest_release_id,generation,catalog_sha256,state FROM publication_state')->fetchAll(PDO::FETCH_ASSOC);
  $rows=$this->pdo->query('SELECT r.id,r.tag,p.tag AS parent_tag,r.source_sha256,r.upstream_commit,r.upstream_parent,r.published_at,r.metadata FROM releases r LEFT JOIN releases p ON r.parent_id=p.id ORDER BY r.id')->fetchAll(PDO::FETCH_ASSOC);
  [$cat,$this->ids]=self::validateCatalog($disk,$digest,$schema,$state,$rows);return $cat;
 }
 public function files(string $tag,array $disk):array {
  $id=$this->ids[$tag]??throw new RuntimeException('unknown database release');
  $q=$this->pdo->prepare('SELECT f.path,LOWER(HEX(f.path_sha256)) AS path_hash,f.sha256,f.byte_size,r.tag AS last_changed FROM release_files f JOIN releases r ON f.last_changed_release_id=r.id WHERE f.release_id=?');$q->execute([$id]);return self::validateFiles($tag,$disk,$q->fetchAll(PDO::FETCH_ASSOC));
 }
}
