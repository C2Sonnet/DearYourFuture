"""Offline rehearsal using actual v0.11.0 publisher and plan generator. No DB access."""
import hashlib, json, pathlib, shutil, subprocess, tempfile, unittest, zipfile, os, fcntl
ROOT=pathlib.Path(os.environ.get('CP_BASE',pathlib.Path(__file__).resolve().parents[1]))
SYNC=ROOT/'tools/mysql_sync.php'

def sha(path):return hashlib.sha256(path.read_bytes()).hexdigest()
class SyncTests(unittest.TestCase):
 @classmethod
 def setUpClass(cls):
  cls.tmp=tempfile.TemporaryDirectory();cls.base=pathlib.Path(cls.tmp.name);cls.root=cls.base/'app';cls.root.mkdir();shutil.copytree(ROOT/'tools',cls.root/'tools');cls.store=cls.root/'storage';cls.plans=[]
  for i in range(1,4):
   v=f'1.0.{i}';data={'VERSION':v.encode(),'README.md':f'line {i}\n'.encode(),'LICENSE':b'license'};z=cls.base/(v+'.zip')
   m={'version':v,'variant':'GitHub_Source','deployment_allowed':False,'build_date':'2026-11-24','files':{k:hashlib.sha256(b).hexdigest() for k,b in data.items()}}
   with zipfile.ZipFile(z,'w') as f:
    for k,b in data.items():f.writestr(k,b)
    f.writestr('release-manifest.json',json.dumps(m))
   subprocess.run(['python3',str(cls.root/'tools/publish.py'),str(z),'--sha256',sha(z),'--approve-public'],check=True,capture_output=True)
   out=cls.base/f'plan{i}.json';subprocess.run(['python3',str(cls.root/'tools/mysql_plan.py'),'--output',str(out)],check=True,capture_output=True);cls.plans.append(out)
 @classmethod
 def tearDownClass(cls):cls.tmp.cleanup()
 def run_sync(self,a,b,*extra,ok=True,hash_override=None):
  cmd=['php',str(SYNC),'--previous='+str(a),'--previous-sha256='+sha(a),'--target='+str(b),'--target-sha256='+(hash_override or sha(b)),*extra]
  r=subprocess.run(cmd,text=True,capture_output=True);self.assertEqual(r.returncode==0,ok,r.stderr);return json.loads(r.stdout) if ok else r.stderr
 def test_append(self):
  r=self.run_sync(self.plans[0],self.plans[2],'--storage='+str(self.store));self.assertEqual(r['added_releases'],['v1.0.2','v1.0.3']);self.assertEqual(r['added_files'],8);self.assertTrue(r['storage_catalog_checked']);self.assertFalse(r['database_accessed'])
 def test_noop(self):self.assertEqual(self.run_sync(self.plans[2],self.plans[2])['added_releases'],[])
 def test_reverse(self):self.assertIn('removal',self.run_sync(self.plans[2],self.plans[0],ok=False))
 def test_hash(self):self.assertIn('SHA256 mismatch',self.run_sync(self.plans[0],self.plans[2],ok=False,hash_override='0'*64))
 def test_changed_history(self):
  p=json.loads(self.plans[2].read_text());p['releases'][0]['metadata']['message']='altered';bad=self.base/'changed.json';bad.write_text(json.dumps(p));self.assertIn('existing release changed',self.run_sync(self.plans[0],bad,ok=False))
 def test_stale_catalog(self):self.assertIn('current catalog differs',self.run_sync(self.plans[0],self.plans[1],'--storage='+str(self.store),ok=False))
 def test_pending_transaction(self):
  f=self.store/'system/transaction.json';f.write_text('{}')
  try:self.assertIn('pending filesystem update',self.run_sync(self.plans[0],self.plans[2],'--storage='+str(self.store),ok=False))
  finally:f.unlink()
 def test_locked(self):
  with (self.store/'system/publish.mutex').open('r') as f:
   fcntl.flock(f,fcntl.LOCK_EX);self.assertIn('lock unavailable',self.run_sync(self.plans[0],self.plans[2],'--storage='+str(self.store),ok=False))
 def test_unapproved(self):self.assertIn('explicit isolated DB confirmation',self.run_sync(self.plans[0],self.plans[2],'--apply',ok=False))
 def test_missing_driver(self):
  if 'mysql' in subprocess.check_output(['php','-r','echo implode(",",PDO::getAvailableDrivers());'],text=True):self.skipTest('PDO MySQL available; do not attempt real connection')
  self.assertIn('PDO MySQL unavailable',self.run_sync(self.plans[0],self.plans[2],'--apply','--confirm-isolated-db',ok=False))
if __name__=='__main__':unittest.main(verbosity=2)
