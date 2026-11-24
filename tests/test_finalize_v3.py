"""Synthetic fixture only; never accesses a private or production archive."""
import hashlib,json,os,pathlib,shutil,tempfile,unittest,zipfile
import sys
sys.path.insert(0,str(pathlib.Path(__file__).resolve().parents[1]/'tools'))
from finalize_v3 import run,PDFS,sha
class Tests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory();self.root=pathlib.Path(self.tmp.name);(self.root/'tools').mkdir();(self.root/'app/data').mkdir(parents=True)
  shutil.copy2(pathlib.Path(os.environ.get('CP_BASE',pathlib.Path.cwd()))/'tools/publish.py',self.root/'tools/publish.py')
  (self.root/'app/data/v3.0.0-release-notes.md').write_text('# 正式版\n',encoding='utf-8')
 def tearDown(self):self.tmp.cleanup()
 def make(self,version='3.0.0',variant='GitHub_Source',missing=False,bad=False):
  path=self.root/'fixture.zip';d={'VERSION':version.encode(),'README.md':b'# fixture'};d.update({n:b'%PDF-1.4\n%%EOF\n' for n in PDFS})
  if missing:d.pop(PDFS[0])
  if bad:d[PDFS[0]]=b'not a pdf'
  m={'version':version,'variant':variant,'deployment_allowed':False,'files':{k:hashlib.sha256(v).hexdigest() for k,v in d.items()}}
  with zipfile.ZipFile(path,'w') as z:
   for k,v in d.items():z.writestr(k,v)
   z.writestr('release-manifest.json',json.dumps(m))
  return path
 def test_check(self):
  z=self.make();self.assertEqual(run(self.root,z,sha(z))['documents'],8);self.assertFalse((self.root/'storage').exists())
 def test_missing(self):
  z=self.make(missing=True)
  with self.assertRaisesRegex(ValueError,'PDF未収録'):run(self.root,z,sha(z))
 def test_bad_pdf(self):
  z=self.make(bad=True)
  with self.assertRaisesRegex(ValueError,'PDFヘッダー'):run(self.root,z,sha(z))
 def test_wrong_version(self):
  z=self.make(version='2.6.1')
  with self.assertRaisesRegex(ValueError,'v3.0.0'):run(self.root,z,sha(z))
 def test_wrong_variant(self):
  z=self.make(variant='Private_Deployment')
  with self.assertRaises(ValueError):run(self.root,z,sha(z))
 def test_hash(self):
  z=self.make()
  with self.assertRaisesRegex(ValueError,'SHA256不一致'):run(self.root,z,'0'*64)
 def test_unapproved(self):
  z=self.make()
  with self.assertRaisesRegex(ValueError,'approve-public'):run(self.root,z,sha(z),True,False)
 def test_apply(self):
  z=self.make();result=run(self.root,z,sha(z),True,True);self.assertEqual(result['registration'],'PASS');self.assertEqual(result['tag'],'v3.0.0');self.assertEqual(result['snapshot_id'],sha(z)[:12]);self.assertIsNone(result['parent'])
  with self.assertRaisesRegex(ValueError,'登録失敗'):run(self.root,z,sha(z),True,True)
if __name__=='__main__':unittest.main(verbosity=2)
