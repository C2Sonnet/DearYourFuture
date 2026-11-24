import hashlib,json,pathlib,shutil,subprocess,tempfile,unittest,zipfile,sys
ROOT=pathlib.Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
import publish as p
class LegacyRehearsalTests(unittest.TestCase):
 def test_multiversion_and_rejections(self):
  with tempfile.TemporaryDirectory() as td:
   b=pathlib.Path(td);old=b/'old';old.mkdir();shutil.copytree(ROOT/'tools',old/'tools')
   # Simulate pre-v0.7 metadata without file/tar/comparison hashes, retaining authentic bytes.
   ledger={}
   for i in range(1,4):
    v=f'1.0.{i}';z=b/(v+'.zip');data={'VERSION':v.encode(),'README.md':f'line {i}\n'.encode(),'LICENSE':b'fixture'}
    m={'version':v,'variant':'GitHub_Source','deployment_allowed':False,'build_date':'2026-11-24','files':{k:p.sha(x) for k,x in data.items()}}
    with zipfile.ZipFile(z,'w') as f:
     for k,x in data.items():f.writestr(k,x)
     f.writestr('release-manifest.json',json.dumps(m))
    ledger['v'+v]=p.filehash(z)
    subprocess.run(['python3',str(old/'tools/publish.py'),str(z),'--sha256',ledger['v'+v],'--approve-public'],check=True,capture_output=True)
   store=old/'storage';cat=p.read(store/'catalog.json')
   for r in cat['versions']:
    for k in ('files_sha256','tar_sha256','comparison_hashes'):r.pop(k,None)
    p.atomic(store/'snapshots'/r['tag']/'meta.json',r)
   p.atomic(store/'catalog.json',cat);p.atomic(store/'system/update.lock',p.ready(cat,(store/'catalog.json').read_bytes()))
   before=p.inventory(store);l=b/'ledger.json';p.atomic(l,{'schema':1,'archives':ledger});dest=b/'new'
   def call(digest,confirm=True):
    return subprocess.run(['python3',str(ROOT/'tools/rehearse_legacy.py'),'--legacy-storage',str(store),'--ledger',str(l),'--sha256',digest,'--dest',str(dest)]+(['--confirm-rehearsal'] if confirm else []),capture_output=True,text=True)
   self.assertNotEqual(call(p.filehash(l),False).returncode,0);self.assertNotEqual(call('0'*64).returncode,0);self.assertFalse(dest.exists())
   p.atomic(l,{'schema':1,'archives':{**ledger,'v1.0.1':'0'*64}});self.assertNotEqual(call(p.filehash(l)).returncode,0);self.assertFalse(dest.exists())
   p.atomic(l,{'schema':1,'archives':ledger});r=call(p.filehash(l));self.assertEqual(r.returncode,0,r.stderr)
   self.assertEqual(p.inventory(store),before);report=p.read(dest/'rehearsal-report.json');self.assertFalse(report['publication_authorized']);self.assertEqual(report['summary']['versions'],3)
   self.assertIn(['v1.0.1','v1.0.3'],report['comparison_pairs_preserved']);self.assertTrue(p.read(dest/'storage/catalog.json')['versions'][0]['comparison_hashes']);self.assertNotEqual(call(p.filehash(l)).returncode,0)
if __name__=='__main__':unittest.main()
