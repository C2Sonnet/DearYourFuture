import hashlib,json,pathlib,shutil,subprocess,tempfile,unittest,urllib.request,urllib.error,zipfile,socket,time
ROOT=pathlib.Path(__file__).resolve().parents[1]
class SecurityTests(unittest.TestCase):
 def setUp(self):
  t=tempfile.TemporaryDirectory();self.addCleanup(t.cleanup);self.root=pathlib.Path(t.name)
  for n in ('app','config','public','tools'):shutil.copytree(ROOT/n,self.root/n)
 def publish(self,extra,ok=True):
  data={'VERSION':b'1.0.0','README.md':b'# Readme\n<script>alert(1)</script>','LICENSE':b'test','SECURITY.md':b'test','a.php':b'<?php echo "INERT";','fake.png':b'<svg onload=alert(1)>'};data.update(extra);z=self.root/'source.zip';m={'version':'1.0.0','variant':'GitHub_Source','deployment_allowed':False,'files':{k:hashlib.sha256(b).hexdigest() for k,b in data.items()}}
  with zipfile.ZipFile(z,'w') as f:
   for k,b in data.items():f.writestr(k,b)
   f.writestr('release-manifest.json',json.dumps(m))
  r=subprocess.run(['python3',str(self.root/'tools/publish.py'),str(z),'--sha256',hashlib.sha256(z.read_bytes()).hexdigest(),'--approve-public'],capture_output=True);self.assertEqual(r.returncode==0,ok,r.stderr.decode());return r
 def test_import_secret_and_protected_paths(self):
  for name,body in [('.env.production',b'fixture'),('docs/id_rsa',b'fixture'),('docs/vendor/code.txt',b'fixture'),('notes.txt',b'-----BEGIN PRIVATE KEY-----\nfixture'),('notes.txt',b'ghp_'+b'A'*36)]:
   r=self.publish({name:body},False);self.assertNotIn(body,r.stdout+r.stderr);self.assertFalse((self.root/'storage/catalog.json').exists())
 def test_http_integrity_and_methods(self):
  self.publish({});root=self.root
  with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
  p=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root/'public')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
  try:
   time.sleep(.4)
   def req(path,status=200,method='GET'):
    try:r=urllib.request.urlopen(urllib.request.Request(f'http://127.0.0.1:{port}'+path,method=method),timeout=5)
    except urllib.error.HTTPError as e:r=e
    self.assertEqual(r.status,status,path);body=r.read();self.assertNotIn(str(root).encode(),body);return body,r.headers
   for page in ('code','branches','commits','commit','tags','releases','compare'):
    _,h=req('/?page='+page);self.assertIn("default-src 'none'",h['Content-Security-Policy']);self.assertEqual(h['X-Content-Type-Options'],'nosniff');self.assertEqual(h['X-Frame-Options'],'DENY')
   self.assertIn(b'&lt;script&gt;',req('/')[0]);self.assertIn(b'&lt;?php',req('/?page=file&path=a.php')[0]);req('/?page=image&path=fake.png',404);req('/?page[]=code',400);req('/?page=code',405,'POST');req('/?page=code',405,'PUT');self.assertEqual(req('/?page=code',200,'HEAD')[0],b'');req('/?page=file&path=..%2Fetc%2Fpasswd',404);req('/?page=download&type=php',404);req('/storage/catalog.json',404);req('/config/site.php',404)
   snap=root/'storage/snapshots/v1.0.0';src=snap/'files/a.php';old=src.read_bytes();src.write_bytes(b'CHANGED');req('/?page=file&path=a.php',503);src.unlink();src.symlink_to(snap/'files/README.md');req('/?page=file&path=a.php',503);src.unlink();src.write_bytes(old)
   for name,query in [('source.zip','/?page=download&type=zip'),('source.tar.gz','/?page=download&type=tar.gz'),('files.json','/?page=code'),('meta.json','/?page=code'),('changes.json','/?page=commit')]:
    path=snap/name;old=path.read_bytes();path.write_bytes(b'{}' if name=='meta.json' else b'corrupt');req(query,503);path.write_bytes(old)
   # Reject even byte-identical metadata and whole snapshot symlinks.
   for path,query in [(root/'storage/catalog.json','/'),(root/'storage/system/update.lock','/'),(snap/'meta.json','/'),(snap/'files.json','/'),(snap,'/?page=download&type=zip')]:
    moved=path.with_name(path.name+'.real');path.rename(moved);path.symlink_to(moved,target_is_directory=moved.is_dir())
    try:req(query,503)
    finally:path.unlink();moved.rename(path)
   req('/?page=download&type=zip');req('/?page=download&type=tar.gz');req('/')
  finally:p.terminate();p.wait(timeout=5)
if __name__=='__main__':unittest.main()
