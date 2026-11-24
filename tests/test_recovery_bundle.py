import hashlib,json,pathlib,shutil,subprocess,tempfile,unittest,zipfile,fcntl
ROOT=pathlib.Path(__file__).resolve().parents[1]
class BundleTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.base=pathlib.Path(self.tmp.name);self.root=self.base/'app';self.root.mkdir();shutil.copytree(ROOT/'tools',self.root/'tools');self.bundle=self.base/'bundle';self.store=self.root/'storage'
  data={'VERSION':b'1.0.0','README.md':b'# synthetic\n'};z=self.base/'source.zip'
  with zipfile.ZipFile(z,'w') as f:
   for k,b in data.items():f.writestr(k,b)
   f.writestr('release-manifest.json',json.dumps({'version':'1.0.0','variant':'GitHub_Source','deployment_allowed':False,'files':{k:hashlib.sha256(b).hexdigest() for k,b in data.items()}}))
  subprocess.run(['python3',str(self.root/'tools/publish.py'),str(z),'--sha256',hashlib.sha256(z.read_bytes()).hexdigest(),'--approve-public'],check=True,capture_output=True)
 def cli(self,action,*args,ok=True):
  r=subprocess.run(['python3',str(self.root/'tools/mysql_recovery_bundle.py'),action,'--bundle',str(self.bundle),*map(str,args)],capture_output=True,text=True);self.assertEqual(r.returncode==0,ok,r.stderr);return json.loads(r.stdout) if ok else None
 def test_roundtrip(self):
  before=(self.store/'catalog.json').read_bytes();r=self.cli('create');h=r['manifest_sha256'];self.assertEqual(self.cli('verify','--sha256',h),r);out=self.base/'restore';self.assertEqual(self.cli('restore','--sha256',h,'--dest',out),r);self.assertEqual((out/'storage/catalog.json').read_bytes(),before);self.assertTrue((out/'storage/system/publish.mutex').is_file());self.assertEqual((self.store/'catalog.json').read_bytes(),before);self.cli('restore','--sha256',h,'--dest',out,ok=False);self.cli('create',ok=False)
 def test_tamper(self):
  h=self.cli('create')['manifest_sha256'];self.cli('verify','--sha256','0'*64,ok=False);(self.bundle/'plan.json').write_text('{}');self.cli('verify','--sha256',h,ok=False);self.cli('restore','--sha256',h,'--dest',self.base/'bad',ok=False);self.assertFalse((self.base/'bad').exists())
 def test_pending_and_lock(self):
  journal=self.store/'system/transaction.json';journal.write_text('{}');self.cli('create',ok=False);journal.unlink()
  with (self.store/'system/publish.mutex').open('r') as f:
   fcntl.flock(f,fcntl.LOCK_EX);self.cli('create',ok=False)
  self.assertFalse(self.bundle.exists())
if __name__=='__main__':unittest.main(verbosity=2)
