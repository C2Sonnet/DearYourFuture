import hashlib,json,pathlib,sys,tempfile,unittest,zipfile
ROOT=pathlib.Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
from publication_inventory import audit
class InventoryTests(unittest.TestCase):
 def test_counts_and_no_literals(self):
  with tempfile.TemporaryDirectory() as t:
   z=pathlib.Path(t)/'source.zip';data={'VERSION':b'1.0.0','app/Core/KimiLetter.php':b'test example@example.test','database/seed.sql':b'SELECT 1;','public/assets/a.svg':b'<svg/>'}
   with zipfile.ZipFile(z,'w') as f:
    for k,b in data.items():f.writestr(k,b)
    f.writestr('release-manifest.json',json.dumps({'version':'1.0.0','variant':'GitHub_Source','deployment_allowed':False,'files':{k:hashlib.sha256(b).hexdigest() for k,b in data.items()}}))
   r=audit(z,hashlib.sha256(z.read_bytes()).hexdigest());self.assertEqual(r['members'],5);self.assertEqual(r['heuristic_counts']['email_like'],1);self.assertNotIn('example@example.test',json.dumps(r));self.assertEqual(r['release_gate'],'HOLD');self.assertEqual(sum(r['category_counts'].values()),5)
   with self.assertRaises(ValueError):audit(z,'0'*64)
if __name__=='__main__':unittest.main(verbosity=2)
