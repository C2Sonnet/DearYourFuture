"""Offline projection checks + HTTP fail-closed; no real DB connections."""
import hashlib,json,os,pathlib,shutil,socket,subprocess,tempfile,time,unittest,urllib.request,urllib.error,zipfile,fcntl
ROOT=pathlib.Path(__file__).resolve().parents[1]
class ReaderTests(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.tmp=tempfile.TemporaryDirectory();cls.root=pathlib.Path(cls.tmp.name)
  for n in ('app','config','public','tools'):shutil.copytree(ROOT/n,cls.root/n)
  for i in range(1,3):
   v=f'1.0.{i}';data={'VERSION':v.encode(),'README.md':f'# Test {i}\n'.encode(),'LICENSE':b'license','SECURITY.md':b'security'};z=cls.root/(v+'.zip')
   with zipfile.ZipFile(z,'w') as f:
    for k,b in data.items():f.writestr(k,b)
    f.writestr('release-manifest.json',json.dumps({'version':v,'variant':'GitHub_Source','deployment_allowed':False,'files':{k:hashlib.sha256(b).hexdigest() for k,b in data.items()}}))
   subprocess.run(['python3',str(cls.root/'tools/publish.py'),str(z),'--sha256',hashlib.sha256(z.read_bytes()).hexdigest(),'--approve-public'],check=True,capture_output=True)
  cls.fixture=cls.root/'fixture.php';cls.fixture.write_text('''<?php
require __DIR__.'/app/Repository.php';
$root=__DIR__.'/storage';$cat=json_decode(file_get_contents($root.'/catalog.json'),true);$sha=hash_file('sha256',$root.'/catalog.json');$rows=[];$files=[];$ids=[];
foreach(array_reverse($cat['versions']) as $i=>$m){$tag=$m['tag'];$ids[$tag]=(string)($i+1);$rows[]=['id'=>$ids[$tag],'tag'=>$tag,'parent_tag'=>$m['parent'],'source_sha256'=>$m['digest'],'upstream_commit'=>$m['upstream_commit'],'upstream_parent'=>$m['upstream_parent'],'published_at'=>(new DateTimeImmutable($m['imported']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),'metadata'=>json_encode(MysqlReadModel::normalize($m))];foreach(json_decode(file_get_contents($root.'/snapshots/'.$tag.'/files.json'),true) as $path=>$f)$files[$tag][]=['path'=>$path,'path_hash'=>hash('sha256',$path),'sha256'=>$f['sha256'],'byte_size'=>(string)$f['bytes'],'last_changed'=>$f['last_changed']];}
$schema=[['version'=>1,'checksum'=>'bfda63615a0661dd009d6c9c5441a0c6cb22feaadb301b739a84f27c1c625bc9']];$state=[['singleton'=>1,'generation'=>2,'catalog_sha256'=>$sha,'state'=>'READY','latest_release_id'=>$ids[$cat['latest']]]];
switch($argv[1]){case 'state':$state[0]['state']='RECOVERY_REQUIRED';break;case 'stale':$state[0]['catalog_sha256']=str_repeat('0',64);break;case 'latest':$state[0]['latest_release_id']='1';break;case 'schema':$schema[0]['checksum']='wrong';break;case 'metadata':$m=json_decode($rows[0]['metadata'],true);$m['message']='changed';$rows[0]['metadata']=json_encode($m);break;case 'parent':$rows[1]['parent_tag']=null;break;case 'hash':$files[$cat['latest']][0]['sha256']=str_repeat('0',64);break;case 'pathhash':$files[$cat['latest']][0]['path_hash']=str_repeat('0',64);break;case 'size':$files[$cat['latest']][0]['byte_size']='999';break;case 'missing':array_pop($files[$cat['latest']]);break;case 'last':$files[$cat['latest']][0]['last_changed']='v9.0.0';break;}
class Fixture implements CatalogReadModel{public function __construct(private array $schema,private array $state,private array $rows,private array $files){}public function catalog(array $disk,string $digest):array{return MysqlReadModel::validateCatalog($disk,$digest,$this->schema,$this->state,$this->rows)[0];}public function files(string $tag,array $disk):array{return MysqlReadModel::validateFiles($tag,$disk,$this->files[$tag]);}}
try{$r=new Repository($root,'mysql',new Fixture($schema,$state,$rows,$files));$c=$r->catalog();$f=$r->files($c['latest']);$path=$r->file($c['latest'],'README.md',$f);echo json_encode(['latest'=>$c['latest'],'text'=>file_get_contents($path),'count'=>count($f)]);}catch(Throwable $e){fwrite(STDERR,$e->getMessage());exit(1);}
''')
 @classmethod
 def tearDownClass(cls):cls.tmp.cleanup()
 def run_case(self,case,ok=False):
  r=subprocess.run(['php',str(self.fixture),case],capture_output=True,text=True);self.assertEqual(r.returncode==0,ok,r.stderr);return r
 def test_projection(self):self.assertEqual(json.loads(self.run_case('ok',True).stdout)['text'],'# Test 2\n')
 def test_catalog_mismatch(self):
  for case in ('state','stale','latest','schema','metadata','parent'):
   with self.subTest(case=case):self.assertIn('projection mismatch',self.run_case(case).stderr)
 def test_files_mismatch(self):
  for case in ('hash','pathhash','size','missing','last'):
   with self.subTest(case=case):self.assertIn('projection mismatch',self.run_case(case).stderr)
 def test_locked(self):
  with (self.root/'storage/system/publish.mutex').open('r') as f:
   fcntl.flock(f,fcntl.LOCK_EX);self.assertIn('publication busy',self.run_case('ok').stderr)
 def test_pending(self):
  p=self.root/'storage/system/transaction.json';p.write_text('{}')
  try:self.run_case('ok')
  finally:p.unlink()
 def test_http_fail_closed(self):
  for mode,status in [('files',200),('mysql',503),('invalid',503)]:
   with self.subTest(mode=mode):
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    env=os.environ.copy();env.update(CP_REPOSITORY_MODE=mode,CP_READER_DATABASE='invalid',CP_READER_PASSWORD='sentinel-test-secret')
    p=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(self.root/'public')],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    try:
     time.sleep(.35)
     for route in ('','?page=download&type=zip','?page=file&path=README.md'):
      try:r=urllib.request.urlopen(f'http://127.0.0.1:{port}/'+route,timeout=5)
      except urllib.error.HTTPError as e:r=e
      with r:self.assertEqual(r.status,status);body=r.read();self.assertNotIn(b'sentinel-test-secret',body);self.assertNotIn(str(self.root).encode(),body)
      if status==503:self.assertNotIn(b'# Test 2',body)
    finally:p.terminate();p.wait(timeout=5)
if __name__=='__main__':unittest.main(verbosity=2)
