import hashlib,json,pathlib,shutil,subprocess,tempfile,unittest,urllib.request,urllib.error,zipfile,socket,time
ROOT=pathlib.Path(__file__).resolve().parents[1]
class HttpTests(unittest.TestCase):
 def test_three_snapshots(self):
  with tempfile.TemporaryDirectory() as t:
   root=pathlib.Path(t)
   for name in ('app','config','public','tools'):shutil.copytree(ROOT/name,root/name)
   shutil.copy2(ROOT/'VERSION',root/'VERSION')
   ui={n.relative_to(root).as_posix():hashlib.sha256(n.read_bytes()).hexdigest() for folder in ('app','config','public') for n in (root/folder).rglob('*') if n.is_file()}
   ui['VERSION']=hashlib.sha256((root/'VERSION').read_bytes()).hexdigest();(root/'release-manifest.json').write_text(json.dumps({'version':(root/'VERSION').read_text().strip(),'variant':'Source_Repository','files':ui}));
   def verify():return subprocess.run(['python3',str(root/'tools/publish.py'),'--verify-site'],capture_output=True)
   self.assertEqual(verify().returncode,0);p=root/'public/assets/site.js';old=p.read_bytes();p.write_bytes(old+b'\n// mixed version');self.assertNotEqual(verify().returncode,0);p.write_bytes(old)
   for v,body in [('0.0.1','one\ntwo'),('0.0.2','one\nthree'),('0.0.3','one\nfour')]:
    data={'VERSION':v.encode(),'README.md':body.encode(),'CHANGELOG.md':b'# log\n## release\n- test','app/unchanged.php':b'<?php echo "<script>";','LICENSE':b'test','SECURITY.md':b'test'}
    m={'version':v,'variant':'GitHub_Source','deployment_allowed':False,'build_date':'2026-11-24','files':{k:hashlib.sha256(b).hexdigest() for k,b in data.items()}}
    z=root/(v+'.zip')
    with zipfile.ZipFile(z,'w') as f:
     for k,b in data.items():f.writestr(k,b)
     f.writestr('release-manifest.json',json.dumps(m))
    subprocess.run(['python3',str(root/'tools/publish.py'),str(z),'--sha256',hashlib.sha256(z.read_bytes()).hexdigest(),'--approve-public'],check=True,capture_output=True)
   self.assertEqual(verify().returncode,0)
   for n,digest in ui.items():self.assertEqual(hashlib.sha256((root/n).read_bytes()).hexdigest(),digest)
   with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
   p=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(root/'public')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
   try:
    time.sleep(.4)
    def get(q,status=200):
     try:r=urllib.request.urlopen(f'http://127.0.0.1:{port}/?'+q,timeout=5)
     except urllib.error.HTTPError as e:r=e
     self.assertEqual(r.status,status);return r.read().decode()
    home=get('page=code');self.assertIn('光と影のソネット',home);self.assertIn('@C2Sonnet',home);self.assertIn('href="https://im.c2s.me"',home);self.assertIn('C2Sonnet / DearYourFuture',home);self.assertIn('rel="icon" href="/assets/project-favicon.ico?rev=',home);self.assertIn('class="project-logo project-logo-title" src="/assets/project-favicon.ico?rev=',home);self.assertIn('class="avatar author-large" src="/assets/author-avatar.png?rev=',home)
    self.assertIn('data-theme="dark"',home);self.assertIn('doc=LICENSE#documentation',home);self.assertIn('doc=SECURITY.md#documentation',home);self.assertIn('/assets/vendor/highlight/highlight.min.js?rev=',home)
    for page,labels in {'code':['ファイルへ移動','ZIPをダウンロード','ライセンス','セキュリティ','プロジェクトについて','作者','使用言語'],'branches':['ブランチを検索','すべて','既定'],'commits':['コミット履歴'],'commit':['コミット'],'compare':['比較元','比較先','比較'],'tags':['タグを検索'],'releases':['リリースを検索','最新','リリースノート'],'file&path=README.md':['表示中のコードをコピー','プレビュー']}.items():
     rendered=get('page='+page)
     for label in labels:self.assertIn(label,rendered)
     for label in ('>About<','>Contributors<','>Languages<','>Latest<','>Compare<','Go to file','Search branches…','Copy shown code','Filter headings','files changed','Read-only repository display'):
      self.assertNotIn(label,rendered)
    for asset in ('author-avatar.png','project-favicon.ico'):
     with urllib.request.urlopen(f'http://127.0.0.1:{port}/assets/{asset}',timeout=5) as res:self.assertEqual(res.read(),(root/'public/assets'/asset).read_bytes())
    h=get('page=compare&base=v0.0.1&v=v0.0.3&path=README.md&search=four');self.assertIn('four',h);self.assertIn('two',h);self.assertIn('1 / 3',h) # VERSION, README, manifest
    self.assertIn('比較元には',get('page=compare&base=v0.0.3&v=v0.0.1'))
    h=get('page=code&dir=app');self.assertIn('v0.0.1',h)
    h=get('page=file&path=app%2Funchanged.php');self.assertIn('&lt;script&gt;',h);self.assertIn('id="L1"',h);self.assertIn('data-code-language="php"',h)
    get('page=file&path=../etc/passwd',404)
    snap=root/'storage/snapshots/v0.0.3';q='page=compare&base=v0.0.1&v=v0.0.3&path=README.md'
    changes=snap/'comparisons/v0.0.1/changes.json';original=changes.read_bytes();changes.write_text('[]');get(q,503);changes.write_bytes(original)
    delta=snap/'comparisons/v0.0.1/diffs'/(hashlib.sha256(b'README.md').hexdigest()+'.json');original=delta.read_bytes();delta.write_text('{"add":0,"del":0,"lines":[]}');get(q,503);delta.write_bytes(original)
    get(q)

   finally:p.terminate();p.wait(timeout=5)
if __name__=='__main__':unittest.main()
