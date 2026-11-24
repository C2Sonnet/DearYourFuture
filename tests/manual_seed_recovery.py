import pathlib,subprocess,tempfile,shutil,urllib.request,urllib.error,socket,time
root=pathlib.Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory() as d:
 b=pathlib.Path(d); app=b/'app';app.mkdir()
 for n in ('app','config','public','tools','seed'):shutil.copytree(root/n,app/n)
 def run(*a,ok=True):
  r=subprocess.run(['python3',*map(str,a)],cwd=app,capture_output=True,text=True);assert (r.returncode==0)==ok,r.stderr;return r.stdout.strip()
 print(run('tools/publish.py','seed/KimiNoMirai_v2.6.1_GitHub_Source.zip','--sha256','7a848d19a2c5c34202baeda22744d169f3a6e23de5a89c2e13b1b47975e679e3','--approve-public','--message','[リリース] v2.6.1 - 安定版','--upstream-commit','693752f','--upstream-parent','70003ed','--test-fault','catalog',ok=False))
 with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
 proc=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t',str(app/'public')],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
 try:
  time.sleep(.4)
  def get(q,status=200):
   try:r=urllib.request.urlopen(f'http://127.0.0.1:{port}/?'+q,timeout=8)
   except urllib.error.HTTPError as e:r=e
   assert r.status==status,(q,r.status);assert "script-src 'self'" in r.headers.get('Content-Security-Policy','');assert r.headers.get('X-Content-Type-Options')=='nosniff';return r.read()
  get('page=code',503);print('HTTP pending 503 PASS')
  print(run('tools/publish.py','--recover'));print(run('tools/storage_admin.py','audit'));print(run('tools/storage_admin.py','capacity'))
  for q in ['page=code','page=code&dir=app','page=file&path=README.md','page=file&path=README.md&view=rendered','page=branches','page=commits','page=commit','page=tags','page=releases','page=compare','page=download&type=zip','page=download&type=tar.gz']:get(q)
  get('page=file&path=../etc/passwd',404);print('HTTP after recovery 13/13 PASS')
  print(run('tools/storage_admin.py','backup','--dest',b/'backup'));print(run('tools/storage_admin.py','verify','--source',b/'backup'));print(run('tools/storage_admin.py','restore','--source',b/'backup','--dest',b/'restored'));print('Real backup/restore PASS')
 finally:proc.terminate();proc.wait()
