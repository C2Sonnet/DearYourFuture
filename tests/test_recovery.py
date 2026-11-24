import hashlib,json,pathlib,shutil,subprocess,tempfile,unittest,zipfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
class RecoveryTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.base=pathlib.Path(self.tmp.name);self.root=self.base/'app';self.root.mkdir();shutil.copytree(ROOT/'tools',self.root/'tools');self.store=self.root/'storage'
 def archive(self,v):
  z=self.base/(v+'.zip');data={'VERSION':v.encode(),'README.md':('test '+v).encode(),'CHANGELOG.md':b'# log\n## release\n- test'}
  m={'version':v,'variant':'GitHub_Source','deployment_allowed':False,'files':{k:hashlib.sha256(b).hexdigest() for k,b in data.items()}}
  with zipfile.ZipFile(z,'w') as f:
   for k,b in data.items():f.writestr(k,b)
   f.writestr('release-manifest.json',json.dumps(m))
  return [str(z),'--sha256',hashlib.sha256(z.read_bytes()).hexdigest(),'--approve-public']
 def cli(self,script,*args,ok=True):
  r=subprocess.run(['python3',str(self.root/'tools'/script),*map(str,args)],capture_output=True);self.assertEqual(r.returncode==0,ok,r.stderr.decode());return r
 def publish(self,*args,ok=True):return self.cli('publish.py',*args,ok=ok)
 def test_four_fault_points(self):
  for i,point in enumerate(['journal','snapshot','catalog','ready'],1):
   args=self.archive(f'0.0.{i}');self.publish(*args,'--test-fault',point,ok=False);self.assertTrue((self.store/'system/transaction.json').exists());self.publish(*args,ok=False);self.cli('storage_admin.py','audit',ok=False);self.publish('--recover');self.assertFalse((self.store/'system/transaction.json').exists());self.cli('storage_admin.py','audit');self.assertEqual(json.loads((self.store/'catalog.json').read_text())['latest'],f'v0.0.{i}')
 def test_tampered_stage(self):
  self.publish(*self.archive('0.0.1'),'--test-fault','journal',ok=False);stage=next((self.store/'snapshots').glob('.stage-*'));(stage/'files/README.md').write_text('modified');self.publish('--recover',ok=False);self.assertTrue((self.store/'system/transaction.json').exists())
 def test_backup_restore(self):
  self.publish(*self.archive('0.0.1'));dest=self.base/'backup';rest=self.base/'restored';self.cli('storage_admin.py','backup','--dest',dest);self.cli('storage_admin.py','verify','--source',dest);self.cli('storage_admin.py','restore','--source',dest,'--dest',rest);self.assertEqual((rest/'catalog.json').read_bytes(),(self.store/'catalog.json').read_bytes());self.cli('storage_admin.py','restore','--source',dest,'--dest',rest,ok=False);(dest/'storage/snapshots/v0.0.1/files/README.md').write_text('tampered');self.cli('storage_admin.py','verify','--source',dest,ok=False);self.cli('storage_admin.py','restore','--source',dest,'--dest',self.base/'bad',ok=False)
 def test_unexpected_catalog(self):
  self.publish(*self.archive('0.0.1'));self.publish(*self.archive('0.0.2'),'--test-fault','snapshot',ok=False);(self.store/'catalog.json').write_text('{}');self.publish('--recover',ok=False)
if __name__=='__main__':unittest.main()
