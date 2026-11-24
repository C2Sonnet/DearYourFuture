#!/usr/bin/env python3
"""Read-only verification of a release ZIP, never extract or publish it."""
import argparse, hashlib, json, pathlib, re, shutil, stat, subprocess, sys, zipfile
MAX_BYTES=128*1024*1024
REWRITE=b'location / {\n    try_files $uri $uri/ /index.php?$query_string;\n}\n'
PUBLIC={'public/index.php','public/.htaccess','public/robots.txt','public/assets/author-avatar.png','public/assets/project-favicon.ico','public/assets/site.css','public/assets/site.js','public/assets/theme-init.js','public/assets/vendor/highlight/highlight.min.js','public/assets/code-highlight.js','public/assets/highlight-theme.css'}
SEED='seed/KimiNoMirai_v2.6.1_GitHub_Source.zip'
SEED_SHA='7a848d19a2c5c34202baeda22744d169f3a6e23de5a89c2e13b1b47975e679e3'
SEED_V3='seed/DearYourFuture_v3.0.0_GitHub_Source.zip'
SEED_V3_SHA='1e48f8dbb23ad2c918d58ad5d40055d279ab7f68fe71c0c52ef6c5517247bc4f'
def hashfile(p):
 with p.open('rb') as f:return hashlib.file_digest(f,'sha256').hexdigest()
def require(ok,msg):
 if not ok:raise ValueError(msg)
def inspect_zip(path, expected):
 require(re.fullmatch(r'[a-f0-9]{64}',expected) and hashfile(path)==expected,'external archive SHA256 mismatch')
 with zipfile.ZipFile(path) as z:
  info=z.infolist(); names=[i.filename for i in info]
  require(len(names)==len(set(names)) and len(names)<=2000 and sum(i.file_size for i in info)<=MAX_BYTES,'archive limits or duplicate path')
  for i in info:
   name=i.filename;parts=name.split('/');mode=stat.S_IFMT(i.external_attr>>16)
   require(not i.is_dir() and not name.startswith('/') and '\\' not in name and ':' not in name and all(p not in ('','.','..') for p in parts) and not any(ord(c)<32 or ord(c)==127 for c in name) and mode in (0,stat.S_IFREG),'unsafe archive member')
  require('release-manifest.json' in names and z.testzip() is None,'manifest absent or CRC failure')
  manifest=json.loads(z.read('release-manifest.json')); files=manifest['files']; variant=manifest['variant']; version=z.read('VERSION').decode().strip()
  require(re.fullmatch(r'(?:\d+\.\d+\.\d+|c\d+\.\d+\.\d+(?:-rc\.[1-9]\d*)?)',version) and version==manifest['version'],'version mismatch')
  require(variant in ('Source_Repository','Initial_Deployment','Viewer_Update'),'unknown package variant')
  require(set(files)==set(names)-{'release-manifest.json'},'manifest coverage mismatch')
  for n,h in files.items():require(re.fullmatch(r'[a-f0-9]{64}',h) and hashlib.sha256(z.read(n)).hexdigest()==h,'member SHA256 mismatch')
  require({n for n in names if n.startswith('public/')}==PUBLIC,'unexpected public file')
  require(z.read('deploy/nginx-rewrite.conf')==REWRITE,'rewrite fragment mismatch')
  require({n for n in names if n.startswith('storage/')}==(set() if variant=='Viewer_Update' else {'storage/.gitkeep'}),'runtime data included')
  seed,seed_sha=(SEED_V3,SEED_V3_SHA) if version=='c1.0.0' else (SEED,SEED_SHA)
  require({n for n in names if n.startswith('seed/')}==({seed} if variant=='Initial_Deployment' else set()),'seed inventory mismatch')
  if variant=='Initial_Deployment':require(hashlib.sha256(z.read(seed)).hexdigest()==seed_sha,'unapproved seed digest')
  require(all(p not in {'.env','.user.ini','.git','node_modules','__pycache__'} for n in names for p in n.split('/')),'environment file included')
 return {'version':version,'variant':variant,'manifest_members':len(files),'archive_sha256':expected,'package':'PASS'}
def env():
 result={'php_cli':None,'pdo_mysql':False,'nginx_present':bool(shutil.which('nginx')),'php_fpm_present':any(shutil.which(x) for x in ('php-fpm','php-fpm8.5','php-fpm8.4'))}
 if shutil.which('php'):
  try:
   p=subprocess.run(['php','-r','echo json_encode([PHP_VERSION,class_exists("PDO")?PDO::getAvailableDrivers():[]]);'],capture_output=True,text=True,check=True,timeout=5);version,drivers=json.loads(p.stdout);result.update(php_cli=version,pdo_mysql='mysql' in drivers)
  except (subprocess.SubprocessError,ValueError):pass
 return result
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('archive',type=pathlib.Path);p.add_argument('--sha256',required=True);a=p.parse_args()
 try:
  out=inspect_zip(a.archive,a.sha256);out['environment']=env();out['release_gate']='HOLD';out['unverified']=['operator rights/privacy approval','production Nginx/PHP-FPM/HTTPS','real browser acceptance','MySQL integration and source-of-truth switch'];print(json.dumps(out,ensure_ascii=False,indent=2))
 except (ValueError,KeyError,TypeError,OSError,RuntimeError,zipfile.BadZipFile):print(json.dumps({'package':'FAIL','release_gate':'HOLD','reason':'archive verification failed'}));sys.exit(1)
