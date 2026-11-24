#!/usr/bin/env python3
"""Offline storage audit, external backup, isolated restoration; no web endpoint."""
import argparse,fcntl,json,os,pathlib,shutil,tempfile
import publish as p

def verify_derived(path,base,target,cat):
 rows={x['tag']:x for x in cat['versions']}; tags=list(rows)
 if base not in rows or target not in rows or tags.index(base)<=tags.index(target):raise ValueError('derived comparison reference')
 if path.is_symlink() or not path.is_dir():raise ValueError('unsafe derived comparison')
 manifest=p.read(path/'comparison-manifest.json');actual=p.inventory(path);actual.pop('comparison-manifest.json')
 if manifest!={'schema':1,'base':base,'target':target,'base_digest':rows[base]['digest'],'target_digest':rows[target]['digest'],'files':actual}:raise ValueError('derived comparison hash mismatch')
 changes=p.read(path/'changes.json');keys=[c['key'] for c in changes]
 if len(keys)!=len(set(keys)) or set(actual)!={'changes.json',*('diffs/'+k+'.json' for k in keys)}:raise ValueError('derived comparison inventory mismatch')
 for c in changes:
  if c['key']!=p.sha(c['path'].encode()):raise ValueError('derived comparison key mismatch')
  d=p.read(path/'diffs'/(c['key']+'.json'))
  if (d['add'],d['del'])!=(c['add'],c['del']):raise ValueError('derived comparison count mismatch')
 return len(changes)

def audit_derived(root,cat):
 derived=root/'derived_comparisons'
 if not derived.exists():return
 p.inventory(derived)
 for target in derived.iterdir():
  if not target.is_dir() or target.name not in [x['tag'] for x in cat['versions']]:raise ValueError('invalid comparison target')
  for base in target.iterdir():verify_derived(base,base.name,target.name,cat)

def audit(root):
 if root.is_symlink() or not root.is_dir():raise ValueError('unsafe storage')
 if (root/'system/transaction.json').exists():raise ValueError('pending transaction')
 cat=p.read(root/'catalog.json')
 if cat.get('schema')!=1 or p.read(root/'system/install.lock')!={'schema':1} or p.read(root/'system/update.lock')!=p.ready(cat,(root/'catalog.json').read_bytes()):raise ValueError('lock mismatch')
 rows=cat['versions'];tags=[r['tag'] for r in rows]
 if not tags or cat['latest']!=tags[0] or len(set(tags))!=len(tags):raise ValueError('catalog sequence')
 import re
 if any(not re.fullmatch(r'v\d+\.\d+\.\d+(?:-[a-zA-Z0-9.-]+)?',t) for t in tags):raise ValueError('invalid tag')
 if set(x.name for x in (root/'snapshots').iterdir())!=set(tags):raise ValueError('unexpected or missing snapshot')
 n=0
 for i,row in enumerate(rows):
  if row['parent']!=(tags[i+1] if i+1<len(tags) else None):raise ValueError('parent mismatch')
  snap=root/'snapshots'/row['tag'];p.inventory(snap);n+=p.validate_snapshot(snap,row)
 audit_derived(root,cat)
 return {'versions':len(tags),'source_files':n,'latest':cat['latest']}

def ensure_new(dest):
 if dest.exists() or dest.is_symlink():raise ValueError('destination must not exist')
 if dest.resolve().is_relative_to(p.ROOT.resolve()):raise ValueError('destination must be outside application directory')
 if not dest.parent.is_dir():raise ValueError('destination parent must exist')

def backup(dest):
 ensure_new(dest);result=audit(p.STORE);stage=pathlib.Path(tempfile.mkdtemp(prefix='.cp-backup-',dir=dest.parent))
 try:
  target=stage/'storage';target.mkdir();(target/'system').mkdir()
  shutil.copy2(p.STORE/'catalog.json',target/'catalog.json')
  for name in ('install.lock','update.lock'):shutil.copy2(p.STORE/'system'/name,target/'system'/name)
  shutil.copytree(p.STORE/'snapshots',target/'snapshots')
  if (p.STORE/'derived_comparisons').exists():shutil.copytree(p.STORE/'derived_comparisons',target/'derived_comparisons')
  if audit(target)!=result:raise ValueError('backup audit mismatch')
  p.atomic(stage/'backup-manifest.json',{'schema':1,'files':p.inventory(target),'summary':result})
  os.rename(stage,dest);p.syncdir(dest.parent)
 finally:
  if stage.exists():shutil.rmtree(stage)
 return result

def verify_backup(source):
 if source.is_symlink() or not source.is_dir():raise ValueError('unsafe backup')
 m=p.read(source/'backup-manifest.json');root=source/'storage'
 if m['schema']!=1 or p.inventory(root)!=m['files']:raise ValueError('backup hash mismatch')
 result=audit(root)
 if result!=m['summary']:raise ValueError('backup summary mismatch')
 return result

def restore(source,dest):
 ensure_new(dest);result=verify_backup(source);stage=pathlib.Path(tempfile.mkdtemp(prefix='.cp-restore-',dir=dest.parent))
 try:
  shutil.copytree(source/'storage',stage/'storage')
  if p.inventory(stage/'storage')!=p.read(source/'backup-manifest.json')['files'] or audit(stage/'storage')!=result:raise ValueError('restore mismatch')
  os.rename(stage/'storage',dest);p.syncdir(dest.parent)
 finally:shutil.rmtree(stage)
 return result

def capacity(root):
 summary=audit(root)
 def sizes(path):return sum(f.stat().st_size for f in path.rglob('*') if f.is_file()) if path.exists() else 0
 return {'summary':summary,'snapshot_bytes':sizes(root/'snapshots'),'derived_comparison_bytes':sizes(root/'derived_comparisons'),'storage_bytes':sizes(root),'free_bytes':shutil.disk_usage(root).free}

def main():
 a=argparse.ArgumentParser();a.add_argument('action',choices=['audit','capacity','backup','verify','restore']);a.add_argument('--source',type=pathlib.Path);a.add_argument('--dest',type=pathlib.Path);args=a.parse_args()
 if args.action in ('audit','capacity','backup'):
  with open(p.STORE/'system/publish.mutex','a') as f:
   fcntl.flock(f,fcntl.LOCK_EX)
   result=audit(p.STORE) if args.action=='audit' else capacity(p.STORE) if args.action=='capacity' else backup(args.dest.absolute())
 elif args.action=='verify':result=verify_backup(args.source.absolute())
 else:result=restore(args.source.absolute(),args.dest.absolute())
 print(json.dumps(result,ensure_ascii=False))
if __name__=='__main__':main()
