import hashlib,json,pathlib,shutil,subprocess,tempfile,zipfile,unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
class ImportTests(unittest.TestCase):
 def test_import_update(self):
  with tempfile.TemporaryDirectory() as t:
   root=pathlib.Path(t);(root/'tools').mkdir();shutil.copy(ROOT/'tools/publish.py',root/'tools')
   def build(v,body,extra=None):
    data={'VERSION':v.encode(),'README.md':body.encode(),'CHANGELOG.md':b'# log\n## release\n- test'};data.update(extra or {})
    m={'version':v,'variant':'GitHub_Source','deployment_allowed':False,'build_date':'2026-11-24','files':{k:hashlib.sha256(b).hexdigest() for k,b in data.items()}}
    z=root/(v+'.zip');
    with zipfile.ZipFile(z,'w') as f:
     for k,b in data.items():f.writestr(k,b)
     f.writestr('release-manifest.json',json.dumps(m))
    return [str(root/'tools/publish.py'),str(z),'--sha256',hashlib.sha256(z.read_bytes()).hexdigest(),'--approve-public']
   a=build('0.0.1','one\ntwo');b=build('0.0.2','one\nthree');bad=build('0.0.3','bad',{'../evil':b'evil'})
   def run(args,ok=True):
    p=subprocess.run(['python3',*args],capture_output=True);self.assertEqual(p.returncode==0,ok,p.stderr.decode())
   run(a);run(a,False);run(b);run(bad,False);cat=json.loads((root/'storage/catalog.json').read_text());self.assertEqual(cat['latest'],'v0.0.2');self.assertEqual(len(cat['versions']),2);self.assertEqual(cat['versions'][0]['parent'],'v0.0.1');changes=json.loads((root/'storage/snapshots/v0.0.2/changes.json').read_text());self.assertTrue(any(x['path']=='README.md' and x['add']==1 and x['del']==1 for x in changes));self.assertFalse((root/'evil').exists())
   c=build('0.0.3','one\nfour');run(c);snap=root/'storage/snapshots/v0.0.3';self.assertTrue((snap/'comparisons/v0.0.1/changes.json').exists());comparison=json.loads((snap/'comparisons/v0.0.1/changes.json').read_text());self.assertTrue(any(x['path']=='README.md' and x['add']==1 and x['del']==1 for x in comparison));meta=json.loads((snap/'files.json').read_text());self.assertEqual(meta['CHANGELOG.md']['last_changed'],'v0.0.1');self.assertEqual(meta['README.md']['last_changed'],'v0.0.3')
   (root/'storage/system/update.lock').write_text('{}');run(build('0.0.4','fail'),False)
if __name__=='__main__':unittest.main()
