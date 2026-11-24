import hashlib,json,pathlib,shutil,subprocess,tempfile,unittest,zipfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
class RebuildTests(unittest.TestCase):
 def test_rebuild_preserves_snapshots_and_backup(self):
  with tempfile.TemporaryDirectory() as t:
   b=pathlib.Path(t);app=b/'app';app.mkdir();shutil.copytree(ROOT/'tools',app/'tools');store=app/'storage'
   def cli(script,*args,ok=True):
    r=subprocess.run(['python3',str(app/'tools'/script),*map(str,args)],capture_output=True,text=True);self.assertEqual(r.returncode==0,ok,r.stderr);return r.stdout
   for i in range(1,4):
    v=f'1.0.{i}';data={'VERSION':v.encode(),'README.md':f'line {i}\n'.encode()};z=b/(v+'.zip');m={'version':v,'variant':'GitHub_Source','deployment_allowed':False,'files':{k:hashlib.sha256(x).hexdigest() for k,x in data.items()}}
    with zipfile.ZipFile(z,'w') as f:
     for k,x in data.items():f.writestr(k,x)
     f.writestr('release-manifest.json',json.dumps(m))
    cli('publish.py',z,'--sha256',hashlib.sha256(z.read_bytes()).hexdigest(),'--approve-public')
   # Simulate a pair omitted in an older release by removing the precomputed derived data only in the fixture.
   shutil.rmtree(store/'snapshots/v1.0.3/comparisons/v1.0.1')
   # Remove the omitted pair from the fixture's trusted inventory too.
   cp=store/'catalog.json';c=json.loads(cp.read_text());row=c['versions'][0];row['comparison_hashes']={k:v for k,v in row['comparison_hashes'].items() if not k.startswith('comparisons/v1.0.1/')};raw=json.dumps(c,ensure_ascii=False,separators=(',',':')).encode();cp.write_bytes(raw);(store/'snapshots/v1.0.3/meta.json').write_text(json.dumps(row,ensure_ascii=False,separators=(',',':')));(store/'system/update.lock').write_text(json.dumps({'state':'READY','latest':c['latest'],'catalog_sha256':hashlib.sha256(raw).hexdigest()}))
   def hashes():return {str(x.relative_to(store)):hashlib.sha256(x.read_bytes()).hexdigest() for x in (store/'snapshots').rglob('*') if x.is_file()}
   before=hashes();cat=(store/'catalog.json').read_bytes();args=['--base','v1.0.1','--target','v1.0.3']
   cli('rebuild_comparison.py',*args,ok=False);cli('rebuild_comparison.py','--base','v1.0.3','--target','v1.0.1','--confirm',ok=False)
   result=json.loads(cli('rebuild_comparison.py',*args,'--confirm'));self.assertGreater(result['changed_files'],0);self.assertEqual(hashes(),before);self.assertEqual((store/'catalog.json').read_bytes(),cat)
   dest=store/'derived_comparisons/v1.0.3/v1.0.1';self.assertTrue((dest/'comparison-manifest.json').is_file());rows=json.loads((dest/'changes.json').read_text());self.assertTrue(any(c['path']=='README.md' and c['add']==c['del']==1 for c in rows))
   cli('rebuild_comparison.py',*args,'--confirm',ok=False);cli('storage_admin.py','audit');self.assertGreater(json.loads(cli('storage_admin.py','capacity'))['derived_comparison_bytes'],0);cli('storage_admin.py','backup','--dest',b/'backup');cli('storage_admin.py','restore','--source',b/'backup','--dest',b/'restored');self.assertEqual((b/'restored/derived_comparisons/v1.0.3/v1.0.1/changes.json').read_bytes(),(dest/'changes.json').read_bytes())
   result=subprocess.run(['php','-r','require $argv[1];$r=new Repository($argv[2]);echo json_encode($r->comparison("v1.0.3","v1.0.1","v1.0.2"));',str(ROOT/'app/Repository.php'),str(store)],capture_output=True,text=True,check=True);self.assertEqual(json.loads(result.stdout),rows)
   (dest/'changes.json').write_text('[]');cli('storage_admin.py','audit',ok=False)
   result=subprocess.run(['php','-r','require $argv[1];$r=new Repository($argv[2]);$r->comparison("v1.0.3","v1.0.1","v1.0.2");',str(ROOT/'app/Repository.php'),str(store)],capture_output=True);self.assertNotEqual(result.returncode,0)
if __name__=='__main__':unittest.main()
