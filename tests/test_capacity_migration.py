import hashlib,json,pathlib,shutil,subprocess,tempfile,unittest,zipfile,sys
ROOT=pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import publish
class CapacityMigrationTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.addCleanup(self.tmp.cleanup);self.base=pathlib.Path(self.tmp.name);self.root=self.base/'app';self.root.mkdir();shutil.copytree(ROOT/'tools',self.root/'tools');self.store=self.root/'storage'
 def cli(self,*args,ok=True):
  r=subprocess.run(list(map(str,args)),capture_output=True,text=True);self.assertEqual(r.returncode==0,ok,r.stderr);return r
 def register(self,i,*opts,ok=True):
  v=f'1.0.{i}';data={'VERSION':v.encode(),'README.md':f'line {i}\n'.encode(),'LICENSE':b'license'};z=self.base/(v+'.zip')
  m={'version':v,'variant':'GitHub_Source','deployment_allowed':False,'build_date':'2026-11-24','files':{k:hashlib.sha256(b).hexdigest() for k,b in data.items()}}
  with zipfile.ZipFile(z,'w') as f:
   for k,b in data.items():f.writestr(k,b)
   f.writestr('release-manifest.json',json.dumps(m))
  return self.cli('python3',self.root/'tools/publish.py',z,'--sha256',hashlib.sha256(z.read_bytes()).hexdigest(),'--approve-public',*opts,ok=ok)
 def test_more_than_21_versions(self):
  for i in range(1,22):self.register(i)
  self.register(22,'--compare-base','v1.0.1'); snap=self.store/'snapshots/v1.0.22';self.assertEqual(len(list((snap/'comparisons').iterdir())),19);self.assertTrue((snap/'comparisons/v1.0.1/changes.json').is_file());self.assertFalse((snap/'comparisons/v1.0.2/changes.json').exists())
  self.register(23,'--compare-base','v1.0.999',ok=False);self.assertEqual(json.loads((self.store/'catalog.json').read_text())['latest'],'v1.0.22')
 def test_diff_budget(self):
  self.assertTrue(publish.diff(b'a\n'*3000,b'b\n'*3000)['limited']);self.assertTrue(publish.diff(b'a'*9000,b'b')['limited'])
  a=self.base/'old';(a/'files').mkdir(parents=True);(a/'files/a').write_text('a');b=b'b';old={'a':{'sha256':publish.sha(b'a'),'bytes':1,'lines':1}};new={'a':{'sha256':publish.sha(b),'bytes':1,'lines':1}};d=self.base/'diff';rows=publish.build_comparison(a,old,new,{'a':b},d,[0,0]);self.assertIsNone(rows[0]['add']);self.assertTrue(json.loads(next((d/'diffs').iterdir()).read_text())['limited'])
 def test_mysql_plan(self):
  self.register(1);self.register(2);out=self.base/'plan.json';res=self.cli('python3',self.root/'tools/mysql_plan.py','--output',out);digest=json.loads(res.stdout)['sha256'];plan=json.loads(out.read_text());self.assertEqual(plan['summary'],{'versions':2,'source_files':8,'latest':'v1.0.2'});self.assertEqual(next(f for f in plan['releases'][1]['files'] if f['path']=='LICENSE')['last_changed'],'v1.0.1')
  args=['php',ROOT/'tools/mysql_migrate.php','--plan='+str(out),'--sha256='+digest];self.assertIn('Offline plan validation PASS',self.cli(*args).stdout);self.cli(*args,'--apply',ok=False);self.cli('php',ROOT/'tools/mysql_migrate.php','--plan='+str(out),'--sha256='+'0'*64,ok=False);self.cli('python3',self.root/'tools/mysql_plan.py','--output',out,ok=False)
  plan['releases'][1]['files'][0]['last_changed']='v1.0.999';out.write_text(json.dumps(plan));self.cli('php',ROOT/'tools/mysql_migrate.php','--plan='+str(out),'--sha256='+hashlib.sha256(out.read_bytes()).hexdigest(),ok=False)
if __name__=='__main__':unittest.main()
