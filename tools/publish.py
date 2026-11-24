#!/usr/bin/env python3
"""Import approved GitHub_Source archives as inert immutable snapshots, never execute them."""
import argparse,collections,datetime,difflib,io,fcntl,hashlib,json,os,pathlib,re,shutil,stat,tarfile,tempfile,zipfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
STORE=ROOT/'storage'; SNAP=STORE/'snapshots'
def sha(b):return hashlib.sha256(b).hexdigest()
def read(p):return json.loads(p.read_text('utf-8'))
def dump(p,x):p.write_text(json.dumps(x,ensure_ascii=False,separators=(',',':')),encoding='utf-8')
def encoded(x):return json.dumps(x,ensure_ascii=False,separators=(',',':')).encode('utf-8')
def syncdir(p):
 fd=os.open(p,os.O_RDONLY|os.O_DIRECTORY)
 try:os.fsync(fd)
 finally:os.close(fd)
def atomic(p,x):
 fd,name=tempfile.mkstemp(prefix='.atomic-',dir=p.parent)
 try:
  with os.fdopen(fd,'wb') as f:f.write(encoded(x));f.flush();os.fsync(f.fileno());os.fchmod(f.fileno(),0o644)
  os.replace(name,p);syncdir(p.parent)
 finally:
  if os.path.exists(name):os.unlink(name)
def filehash(p):
 with p.open('rb') as f:return hashlib.file_digest(f,'sha256').hexdigest()
def inventory(root):
 out={}
 for directory,dirs,files in os.walk(root,followlinks=False):
  for n in dirs+files:
   p=pathlib.Path(directory)/n
   if p.is_symlink() or not (p.is_dir() or p.is_file()):raise ValueError('unsafe storage member')
   if p.is_file():out[p.relative_to(root).as_posix()]=filehash(p)
 return out
def comparison_hashes(root):
 result={'changes.json':filehash(root/'changes.json')}
 for name in ('diffs','comparisons'):
  d=root/name
  if d.is_symlink():raise ValueError('unsafe comparison path')
  if d.exists():result.update({name+'/'+k:v for k,v in inventory(d).items()})
 return result
def validate_snapshot(root,row):
 if root.is_symlink() or not root.is_dir():raise ValueError('snapshot missing or unsafe')
 if read(root/'meta.json')!=row or filehash(root/'source.zip')!=row['digest']:raise ValueError('snapshot metadata/hash mismatch')
 if row.get('files_sha256') and filehash(root/'files.json')!=row['files_sha256']:raise ValueError('file index hash mismatch')
 if row.get('tar_sha256') and filehash(root/'source.tar.gz')!=row['tar_sha256']:raise ValueError('tar hash mismatch')
 if 'comparison_hashes' in row and comparison_hashes(root)!=row['comparison_hashes']:raise ValueError('comparison inventory mismatch')
 meta=read(root/'files.json'); actual=inventory(root/'files')
 if actual!={n:e['sha256'] for n,e in meta.items()}:raise ValueError('snapshot source mismatch')
 with zipfile.ZipFile(root/'source.zip') as z:
  if z.testzip() or {i.filename:sha(z.read(i)) for i in z.infolist() if not i.is_dir()}!=actual:raise ValueError('snapshot ZIP mismatch')
 with tarfile.open(root/'source.tar.gz','r:gz') as t:
  members=t.getmembers();names=[m.name for m in members]
  if len(names)!=len(set(names)) or any(not m.isfile() for m in members) or {m.name:sha(t.extractfile(m).read()) for m in members}!=actual:raise ValueError('snapshot tar mismatch')
 return len(meta)
def ready(cat,raw):return {'state':'READY','latest':cat['latest'],'catalog_sha256':sha(raw)}
def load_ready():
 catalog=STORE/'catalog.json'; install=STORE/'system/install.lock'; update=STORE/'system/update.lock'
 if (STORE/'system/transaction.json').exists():raise ValueError('interrupted publication; run --recover')
 present=[x.exists() for x in (catalog,install,update)]
 if any(present) and not all(present):raise ValueError('inconsistent state; recovery required')
 cat=read(catalog) if catalog.exists() else {'schema':1,'latest':None,'versions':[]}
 if all(present) and (read(install).get('schema')!=1 or cat.get('schema')!=1 or read(update)!=ready(cat,catalog.read_bytes())):raise ValueError('lock mismatch; recovery required')
 return cat
def resume(j,fault=None):
 system=STORE/'system';journal=system/'transaction.json';catalog=STORE/'catalog.json';install=system/'install.lock';update=system/'update.lock'
 tag=j['tag'];stage_name=j['stage'];new=j['new_catalog'];old=j['old_catalog'];old_hash=j['old_hash']
 if not re.fullmatch(r'v\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?',tag) or not re.fullmatch(r'\.stage-[a-zA-Z0-9_]+',stage_name) or new['latest']!=tag or new['versions'][1:]!=old['versions'] or new['versions'][0]['tag']!=tag:raise ValueError('invalid journal')
 new_hash=sha(encoded(new));current=filehash(catalog) if catalog.exists() else None
 if current not in (old_hash,new_hash):raise ValueError('catalog changed outside journal')
 if install.exists() and read(install)!={'schema':1}:raise ValueError('install lock mismatch')
 if old_hash is not None and not install.exists():raise ValueError('install lock missing')
 if update.exists() and read(update) not in ({'state':'READY','latest':old['latest'],'catalog_sha256':old_hash},ready(new,encoded(new))):raise ValueError('update lock mismatch')
 if old_hash is not None and not update.exists():raise ValueError('update lock missing')
 stage=SNAP/stage_name; final=SNAP/tag
 if stage.exists()==final.exists():raise ValueError('ambiguous snapshot state')
 candidate=final if final.exists() else stage
 if inventory(candidate)!=j['inventory']:raise ValueError('snapshot inventory mismatch')
 validate_snapshot(candidate,new['versions'][0])
 def stop(point):
  if fault==point:raise RuntimeError('injected interruption: '+point)
 stop('journal')
 if stage.exists():os.replace(stage,final);syncdir(SNAP)
 stop('snapshot')
 if not install.exists():atomic(install,{'schema':1})
 atomic(catalog,new);stop('catalog')
 atomic(update,ready(new,catalog.read_bytes()));stop('ready')
 journal.unlink();syncdir(system)

APPROVED_WATERFALL_SHA = '1e48f8dbb23ad2c918d58ad5d40055d279ab7f68fe71c0c52ef6c5517247bc4f'
APPROVED_WATERFALL_PATHS = frozenset(['resources/private/waterfall/キミの未来へ_テスト_テスト仕様書.pdf', 'resources/private/waterfall/キミの未来へ_テスト_テスト報告書.pdf', 'resources/private/waterfall/キミの未来へ_保守_保守マニュアル.pdf', 'resources/private/waterfall/キミの未来へ_基本設計_基本設計書.pdf', 'resources/private/waterfall/キミの未来へ_要件定義_仕様書.pdf', 'resources/private/waterfall/キミの未来へ_要件定義_要件定義書.pdf', 'resources/private/waterfall/キミの未来へ_詳細設計_詳細設計書.pdf', 'resources/private/waterfall/キミの未来へ_運用_機能説明書.pdf', 'resources/private/waterfall/SHA256SUMS.txt'])

def validate_name(n, approved_waterfall=False):
 parts=n.split('/')
 if not n or n.startswith('/') or '\\' in n or any(p in ('','.','..') for p in parts) or re.search(r'[\x00-\x1f\x7f:]',n):raise ValueError('unsafe archive path')
 if approved_waterfall and n in APPROVED_WATERFALL_PATHS:return
 if any(p.lower() in {'.git','.env','.user.ini','storage','node_modules','vendor','waterfall','backups','private'} for p in parts if p.lower()!='vendor') or any(p.lower().endswith(('.pem','.key','.p12','.pfx','.lock','.sqlite','.db','.zip','.tar.gz')) for p in parts):raise ValueError('protected archive path: '+n)
 # public/assets/vendor contains redistributable frontend assets; root PHP vendor is not allowed.
 if any(x.lower()=='vendor' for x in parts) and not n.startswith('public/assets/vendor/'):raise ValueError('unexpected vendor path')
 if any(x.lower().startswith('.env.') and x.lower()!='.env.example' for x in parts):raise ValueError('environment-specific file')
 if any(x.lower() in {'.htpasswd','id_rsa','id_ed25519','credentials.json'} for x in parts):raise ValueError('credential file')
# Heuristic scanner; never emit matched values. A clean result is not publication approval.
SECRET_PATTERNS={
 'private_key':rb'-----BEGIN (?:RSA |EC |OPENSSH |DSA |ENCRYPTED )?PRIVATE KEY-----',
 'aws_access_key':rb'\b(?:AKIA|ASIA)[A-Z0-9]{16}\b',
 'github_token':rb'\b(?:gh[pousr]_[A-Za-z0-9]{30,}|github_pat_[A-Za-z0-9_]{30,})\b',
 'credential_literal_review':rb'(?i)(?:password|passwd|api[_-]?key|secret)[\x22\x27]?\s*(?:=>|=|:)\s*[\x22\x27][^\x22\x27\r\n]{6,}[\x22\x27]'
}
def scan_secrets(members):
 return [{'path':n,'rule':k,'count':len(matches),'severity':'review' if k.endswith('_review') else 'block'} for n,b in sorted(members.items()) for k,p in SECRET_PATTERNS.items() if (matches:=re.findall(p,b))]
def text(b):
 if b'\0' in b:return None
 try:return b.decode('utf-8')
 except UnicodeDecodeError:return None
LANG={'.php':'PHP','.css':'CSS','.js':'JavaScript','.mjs':'JavaScript','.py':'Python','.html':'HTML','.htm':'HTML','.sql':'SQL','.sh':'Shell'}
def diff(old,new):
 if old==new:return None
 a=text(old);b=text(new)
 if a is None or b is None:return {'binary':True,'add':None,'del':None,'lines':[]}
 a=a.splitlines();b=b.splitlines()
 if len(a)+len(b)>30000 or len(old)+len(new)>2000000 or len(a)*len(b)>4_000_000 or any(len(line)>8192 for line in a+b):return {'limited':True,'add':None,'del':None,'lines':[]}
 result=[];adds=deletes=0;oldn=newn=0
 for line in difflib.unified_diff(a,b,n=3,lineterm=''):
  if line.startswith(('--- ','+++ ')) and not result:continue
  if line.startswith('@@'):
   m=re.search(r'-(\d+)(?:,\d+)? \+(\d+)',line);oldn,newn=map(int,m.groups());result.append(['h','','',line]);continue
  if line.startswith('+'):adds+=1;result.append(['a','',newn,line[1:]]);newn+=1
  elif line.startswith('-'):deletes+=1;result.append(['d',oldn,'',line[1:]]);oldn+=1
  elif line.startswith(' '):result.append(['c',oldn,newn,line[1:]]);oldn+=1;newn+=1
  if len(result)>50000:return {'limited':True,'add':None,'del':None,'lines':[]}
 return {'add':adds,'del':deletes,'lines':result}
def build_comparison(base,oldmeta,meta,members,target,budget=None):
 if budget is None:budget=[16_000_000,30_000_000]
 remaining=8_000_000
 target.mkdir(parents=True,exist_ok=True); (target/'diffs').mkdir()
 changes=[]
 for n in sorted(set(oldmeta)|set(meta)):
  if n in oldmeta and n in meta and oldmeta[n]['sha256']==meta[n]['sha256']:continue
  # Do not read oversized files or run expensive comparisons after the content budget is spent.
  limited={'limited':True,'add':None,'del':None,'lines':[]}
  left=oldmeta.get(n,{});right=meta.get(n,{})
  la=left.get('lines') or 0;lb=right.get('lines') or 0;cost=max(1,la+lb,la*lb)
  if remaining<256 or budget[0]<256 or cost>budget[1] or left.get('bytes',0)+right.get('bytes',0)>2_000_000:d=limited
  else:
   budget[1]-=cost
   a=(base/'files'/n).read_bytes() if n in oldmeta else b''; b=members.get(n,b''); d=diff(a,b)
   if d is None:d={'add':0,'del':0,'lines':[]}
   size=len(encoded(d))
   if size>min(2_000_000,remaining,budget[0]):d=limited
   else:remaining-=size;budget[0]-=size
  key=sha(n.encode());dump(target/'diffs'/(key+'.json'),d)
  changes.append({'path':n,'key':key,'status':'added' if n not in oldmeta else 'deleted' if n not in meta else 'modified','add':d['add'],'del':d['del']})
 dump(target/'changes.json',changes)
 return changes

def verify_site(strict=False):
 """Detect mixed viewer versions; never copy code from an imported snapshot."""
 manifest=ROOT/'release-manifest.json'
 if not manifest.is_file():
  if strict:raise ValueError('viewer release-manifest.json missing; verify installed application package')
  return
 m=read(manifest);files=m['files'];version=(ROOT/'VERSION').read_text('utf-8').strip()
 if m.get('version')!=version or m.get('variant') not in ('Source_Repository','Initial_Deployment','Viewer_Update'):raise ValueError('viewer VERSION/manifest mismatch')
 expected={n:h for n,h in files.items() if n=='VERSION' or n.startswith(('app/','public/'))}
 if not {'VERSION','public/index.php','app/View.php','public/assets/site.js','public/assets/project-favicon.ico','public/assets/author-avatar.png'}.issubset(expected):raise ValueError('viewer manifest missing UI assets')
 for n,h in expected.items():
  validate_name(n);path=ROOT/n
  if not path.is_file() or path.is_symlink() or filehash(path)!=h:raise ValueError('viewer package mixed or damaged: '+n+'; restore matching application files (do not overwrite storage)')
 config=ROOT/'config/site.php'
 if not config.is_file() or filehash(config)!=files.get('config/site.php'):
  if strict:raise ValueError('viewer config differs from packaged Japanese branding; inspect local configuration')
  print('WARNING: viewer config differs; inspect Japanese branding settings')
 print('Viewer '+(version if version.startswith('c') else 'v'+version)+': UI asset/application manifest PASS; repository import does not modify viewer files')

def main():
 p=argparse.ArgumentParser();p.add_argument('archive',type=pathlib.Path,nargs='?');p.add_argument('--sha256');p.add_argument('--recover',action='store_true');p.add_argument('--verify-site',action='store_true');p.add_argument('--test-fault',choices=['journal','snapshot','catalog','ready']);p.add_argument('--approve-public',action='store_true');p.add_argument('--message');p.add_argument('--upstream-commit');p.add_argument('--upstream-parent');p.add_argument('--notes',type=pathlib.Path);p.add_argument('--check-only',action='store_true');p.add_argument('--compare-base',action='append',default=[],help='explicit older snapshot to precompute (max 20 including adjacent)');args=p.parse_args()
 if args.verify_site:
  if args.archive or args.recover:p.error('--verify-site is standalone')
  verify_site(strict=True);return
 if args.recover:
  if args.archive or args.sha256 or args.test_fault:raise ValueError('recovery takes no archive or fault arguments')
  with open(STORE/'system/publish.mutex','a') as lock:
   fcntl.flock(lock,fcntl.LOCK_EX);resume(read(STORE/'system/transaction.json'))
  print('Recovery complete');return
 if not args.archive or not args.sha256:p.error('archive and --sha256 required')
 verify_site()
 if not args.archive.is_file():p.error('source ZIP not found: '+str(args.archive))
 if args.archive.stat().st_size>100_000_000:raise ValueError('archive too large')
 raw=args.archive.read_bytes();digest=sha(raw)
 if digest!=args.sha256.lower():raise ValueError('SHA256 mismatch')
 if len(raw)>100_000_000:raise ValueError('archive too large')
 with zipfile.ZipFile(io.BytesIO(raw)) as z:
  members={};total=0
  for i in z.infolist():
   if i.is_dir():continue
   validate_name(i.filename, digest==APPROVED_WATERFALL_SHA);mode=(i.external_attr>>16)&0xffff
   if stat.S_IFMT(mode) not in (0,stat.S_IFREG) or i.flag_bits&1 or i.filename in members:raise ValueError('non-regular/encrypted/duplicate')
   total+=i.file_size
   if len(members)>=10000 or total>300_000_000 or i.file_size>30_000_000 or i.file_size>max(i.compress_size,1)*500:raise ValueError('archive resource limit')
   members[i.filename]=z.read(i)
 m=json.loads(members['release-manifest.json']);v=m['version'];tag='v'+v
 if not re.fullmatch(r'\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?',v) or m.get('variant')!='GitHub_Source' or m.get('deployment_allowed') is not False or members['VERSION'].decode().strip().lstrip('v')!=v:raise ValueError('not an approved source variant')
 if digest==APPROVED_WATERFALL_SHA and not (v=='3.0.0' and m.get('public_source_approved_by_operator') is True and m.get('development_pdf_count')==8 and APPROVED_WATERFALL_PATHS.issubset(members)):raise ValueError('approved document source metadata mismatch')
 expected=m['files'];actual={n:sha(b) for n,b in members.items() if n!='release-manifest.json'}
 if expected!=actual:raise ValueError('manifest coverage/hash mismatch')
 findings=scan_secrets(members)
 if any(x['severity']=='block' for x in findings):raise ValueError('potential credential or private key detected; publication refused')
 print(f'{tag}: {len(members)} files, {total:,} bytes; CRC, source variant, manifest PASS; credential review candidates: {sum(x["count"] for x in findings)}')
 if args.check_only:return
 if not args.approve_public:raise ValueError('explicit --approve-public required after rights/privacy review')
 STORE.mkdir(exist_ok=True);SNAP.mkdir(exist_ok=True);(STORE/'system').mkdir(exist_ok=True)
 with open(STORE/'system/publish.mutex','a') as lock:
  fcntl.flock(lock,fcntl.LOCK_EX)
  catalog=STORE/'catalog.json';install=STORE/'system/install.lock';update=STORE/'system/update.lock'
  cat=load_ready()
  if any(x['tag']==tag for x in cat['versions']) or (SNAP/tag).exists():raise ValueError('immutable version already exists')
  prev=cat['latest'];meta={};langs=collections.Counter();changes=[];stage=pathlib.Path(tempfile.mkdtemp(prefix='.stage-',dir=SNAP))
  try:
   (stage/'files').mkdir();(stage/'diffs').mkdir()
   for n,b in members.items():
    f=stage/'files'/n;f.parent.mkdir(parents=True,exist_ok=True);f.write_bytes(b)
    tx=text(b);meta[n]={'bytes':len(b),'sha256':sha(b),'text':tx is not None,'lines':len(tx.splitlines()) if tx is not None else None}
    lang=LANG.get(f.suffix.lower());
    if lang and not n.startswith('public/assets/vendor/'):langs[lang]+=len(b)
   # Precompute adjacent comparison and earlier published baselines, outside web root.
   # No hard version-count ceiling. Bound new pair generation; older explicit baselines take priority.
   historical=[x['tag'] for x in cat['versions']]
   requested=list(dict.fromkeys(args.compare_base))
   if any(x not in historical for x in requested):raise ValueError('unknown comparison base')
   bases=list(dict.fromkeys(([prev] if prev else [])+requested))
   if len(bases)>20:raise ValueError('too many explicit comparison bases')
   bases=(bases+[x for x in historical if x not in bases])[:20]
   budget=[16_000_000,30_000_000]
   oldmeta=read(SNAP/prev/'files.json') if prev else {}
   for n,entry in meta.items():
    if n in oldmeta and oldmeta[n]['sha256']==entry['sha256']:
     entry['last_changed']=oldmeta[n].get('last_changed',prev)
    else:entry['last_changed']=tag
   if prev:
    (stage/'diffs').rmdir()
    changes=build_comparison(SNAP/prev,oldmeta,meta,members,stage,budget)
    for base in bases[1:]:
     if budget[0]<256:break
     build_comparison(SNAP/base,read(SNAP/base/'files.json'),meta,members,stage/'comparisons'/base,budget)
   dump(stage/'files.json',meta);dump(stage/'changes.json',changes)
   (stage/'source.zip').write_bytes(raw)
   with tarfile.open(stage/'source.tar.gz','w:gz') as tar:
    for n in sorted(members):tar.add(stage/'files'/n,arcname=n,recursive=False)
   notes=args.notes.read_text('utf-8') if args.notes else members.get('CHANGELOG.md',b'').decode('utf-8').split('\n## ')[1].split('\n## ')[0] if b'\n## ' in members.get('CHANGELOG.md',b'') else ''
   if not args.notes and notes:notes='## '+notes
   row={'tag':tag,'date':m.get('build_date',''),'imported':datetime.datetime.now(datetime.timezone.utc).isoformat(),'message':args.message or f'[リリース] {tag}','digest':digest,'files_sha256':filehash(stage/'files.json'),'tar_sha256':filehash(stage/'source.tar.gz'),'id':digest[:12],'upstream_commit':args.upstream_commit,'upstream_parent':args.upstream_parent,'parent':prev,'files':len(meta),'languages':dict(langs),'changes':len(changes),'add':sum(x['add'] or 0 for x in changes),'del':sum(x['del'] or 0 for x in changes),'limited':sum(x['add'] is None for x in changes),'notes':notes}
   row['comparison_hashes']=comparison_hashes(stage)
   dump(stage/'meta.json',row)
   # previous catalog backup; all files outside public root; inconsistent states fail closed.
   if catalog.exists():
    (STORE/'backups').mkdir(exist_ok=True);shutil.copy2(catalog,STORE/'backups'/f'catalog-before-{tag}.json')
   # Journal before the first visible state change. Keep stage on interrupted publication.
   old_hash=filehash(catalog) if catalog.exists() else None
   new={'schema':1,'latest':tag,'versions':[row,*cat['versions']]}
   # Durable stage contents before publishing journal.
   for directory,dirs,files in os.walk(stage,topdown=False):
    for n in files:
     with open(pathlib.Path(directory)/n,'rb') as f:os.fsync(f.fileno())
    syncdir(directory)
   syncdir(SNAP)
   journal=STORE/'system/transaction.json'
   j={'tag':tag,'stage':stage.name,'old_catalog':cat,'old_hash':old_hash,'new_catalog':new,'inventory':inventory(stage)}
   atomic(journal,j);resume(j,args.test_fault)
  finally:
   if stage.exists() and not (STORE/'system/transaction.json').exists():shutil.rmtree(stage)
 print('Imported',tag,'as inert snapshot; no live site changed.')
if __name__=='__main__':main()
