import json,pathlib,subprocess,sys,unittest,os
ROOT=pathlib.Path(__file__).resolve().parents[1];sys.path.insert(0,str(ROOT/'tools'))
import rehearse_mysql,accept_browser
class ExternalToolsTests(unittest.TestCase):
 def test_db_names(self):
  e={'CP_MYSQL_DATABASE':'codepublication_test_a','CP_RESTORE_MYSQL_DATABASE':'codepublication_test_b','CP_MYSQL_USER':'synthetic','CP_MYSQL_PASSWORD':'synthetic','CP_READER_USER':'synthetic_reader','CP_READER_PASSWORD':'synthetic'};self.assertEqual(rehearse_mysql.config(e),[e['CP_MYSQL_DATABASE'],e['CP_RESTORE_MYSQL_DATABASE']]);
  for patch in ({'CP_RESTORE_MYSQL_DATABASE':e['CP_MYSQL_DATABASE']},{'CP_MYSQL_DATABASE':'codepublication_live'},{'CP_MYSQL_HOST':'example.test'}):
   with self.assertRaises(ValueError):rehearse_mysql.config(e|patch)
 def test_guard(self):
  for cmd in (['rehearse_mysql.py'],['accept_browser.py','https://example.test']):
   r=subprocess.run([sys.executable,str(ROOT/'tools'/cmd[0]),*cmd[1:]],capture_output=True);self.assertEqual(r.returncode,2)
 def test_browser_describe(self):
  r=subprocess.check_output([sys.executable,str(ROOT/'tools/accept_browser.py'),'--describe']);d=json.loads(r);self.assertEqual(d['widths'],[320,390,768,1024,1200]);self.assertEqual(d['checks_per_width'],19);self.assertEqual(d['release_gate'],'HOLD')
 def test_reader_guard(self):
  r=subprocess.run(['php',str(ROOT/'tools/verify_mysql_reader.php'),'--storage=/tmp'],capture_output=True,text=True);self.assertNotEqual(r.returncode,0);self.assertIn('verification refused',r.stderr)
 def test_fault_guard(self):
  r=subprocess.run(['php',str(ROOT/'tools/mysql_sync.php'),'--previous=x','--previous-sha256='+'0'*64,'--target=y','--target-sha256='+'0'*64,'--test-fault=before-commit'],capture_output=True,text=True);self.assertNotEqual(r.returncode,0);self.assertIn('fault injection requires explicit isolated apply',r.stderr)
if __name__=='__main__':unittest.main(verbosity=2)
