"""Use synthetic versions only; no private PDF or real v3 source is registered."""
import hashlib,json,pathlib,shutil,subprocess,tempfile,unittest,urllib.request,urllib.error,zipfile,socket,time
ROOT=pathlib.Path(__file__).resolve().parents[1]
class DevelopmentTests(unittest.TestCase):
 def test_development_and_release(self):
  with tempfile.TemporaryDirectory() as d:
   root=pathlib.Path(d)
   for n in ('app','config','public','tools'):shutil.copytree(ROOT/n,root/n)
   shutil.copy2(ROOT/'VERSION',root/'VERSION')
   def register(version,extra=None,notes=None):
    data={'VERSION':version.encode(),'README.md':b'# Synthetic','LICENSE':b'license','SECURITY.md':b'security','docs/USER_GUIDE.md':b'# Guide'};data.update(extra or {})
    m={'version':version,'variant':'GitHub_Source','deployment_allowed':False,'build_date':'2026-11-25','files':{k:hashlib.sha256(v).hexdigest() for k,v in data.items()}};z=root/(version+'.zip')
    with zipfile.ZipFile(z,'w') as f:
     for k,b in data.items():f.writestr(k,b)
     f.writestr('release-manifest.json',json.dumps(m))
    cmd=['python3',str(root/'tools/publish.py'),str(z),'--sha256',hashlib.sha256(z.read_bytes()).hexdigest(),'--approve-public']
    if notes:cmd+=['--notes',str(notes)]
    subprocess.run(cmd,check=True,capture_output=True)
   register('2.6.1')
   with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
   proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root/'public')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
   try:
    time.sleep(.4)
    def get(q,status=200):
     try:r=urllib.request.urlopen(f'http://127.0.0.1:{port}/?'+q,timeout=5)
     except urllib.error.HTTPError as e:r=e
     with r:self.assertEqual(r.status,status);return r.read(),r.headers
    body,headers=get('page=development');html=body.decode();self.assertIn('開発資料・ウォーターフォール',html);self.assertEqual(html.count('class="development-document"'),8);self.assertEqual(html.count('公開用PDF未収録'),8);self.assertIn('docs%2FUSER_GUIDE.md',html);self.assertIn('fonts.googleapis.com/css2?family=Zen+Maru+Gothic',html);self.assertIn("font-src 'self' https://fonts.gstatic.com",headers['Content-Security-Policy']);self.assertEqual(headers['X-CodePublication-Version'],(ROOT/'VERSION').read_text().strip())
    self.assertIn('upstream-v3-notice',get('page=releases')[0].decode());self.assertNotIn('release-v3.0.0',get('page=tags')[0].decode());get('page=development-pdf&document=requirements',404);get('page=development-pdf&document=../secret',404)
    # Synthetic fixture, not actual v3.0.0 original. Demonstrate real import contract and immutable history.
    pdf=b'%PDF-1.4\n%%EOF\n';register('3.0.0',{'docs/development/requirements.pdf':pdf},root/'app/data/v3.0.0-release-notes.md')
    html=get('page=development')[0].decode();self.assertEqual(html.count('公開用PDF未収録'),7);self.assertIn('PDFをダウンロード',html)
    data,headers=get('page=development-pdf&document=requirements');self.assertEqual(data,pdf);self.assertEqual(headers['Content-Type'],'application/pdf');self.assertIn('attachment',headers['Content-Disposition']);get('page=development-pdf&document=requirements&v=v2.6.1',404)
    html=get('page=releases')[0].decode();self.assertNotIn('upstream-v3-notice',html);self.assertIn('release-v3.0.0',html);self.assertIn('release-v2.6.1',html);self.assertIn('プロジェクト名称・正式リリース',html);self.assertIn('release-v3.0.0',get('page=tags')[0].decode());self.assertIn('v3.0.0',get('page=commits')[0].decode());self.assertIn('main',get('page=branches')[0].decode())
    (root/'storage/snapshots/v3.0.0/files/docs/development/requirements.pdf').write_bytes(b'%PDF-1.4\nmodified');get('page=development-pdf&document=requirements',503)
   finally:proc.terminate();proc.wait(timeout=5)
if __name__=='__main__':unittest.main(verbosity=2)
