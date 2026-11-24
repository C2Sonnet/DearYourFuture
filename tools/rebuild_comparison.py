#!/usr/bin/env python3
"""Generate one missing comparison, preserving original snapshots and catalog."""
import argparse,fcntl,json,os,pathlib,shutil,tempfile
import publish as p
import storage_admin as admin

class LazyFiles:
 def __init__(self,root,index):self.root=root;self.index=index
 def get(self,name,default):return (self.root/name).read_bytes() if name in self.index else default

def rebuild(base,target,max_bytes):
 summary=admin.audit(p.STORE);cat=p.load_ready();tags=[x['tag'] for x in cat['versions']]
 if base not in tags or target not in tags or tags.index(base)<=tags.index(target):raise ValueError('base must precede target')
 row=next(x for x in cat['versions'] if x['tag']==target)
 original=p.SNAP/target/('' if row['parent']==base else 'comparisons/'+base)
 root=p.STORE/'derived_comparisons';dest=root/target/base
 if (original/'changes.json').exists() or dest.exists():raise ValueError('comparison already exists; immutable')
 before=sum(f.stat().st_size for f in root.rglob('*') if f.is_file()) if root.exists() else 0
 if before>=max_bytes or shutil.disk_usage(p.STORE).free<32_000_000:raise ValueError('storage budget or free space exhausted')
 stage=pathlib.Path(tempfile.mkdtemp(prefix='.comparison-',dir=p.STORE/'system'))
 try:
  old=p.read(p.SNAP/base/'files.json');new=p.read(p.SNAP/target/'files.json')
  rows=p.build_comparison(p.SNAP/base,old,new,LazyFiles(p.SNAP/target/'files',new),stage)
  p.atomic(stage/'comparison-manifest.json',{'schema':1,'base':base,'target':target,'base_digest':next(x['digest'] for x in cat['versions'] if x['tag']==base),'target_digest':row['digest'],'files':p.inventory(stage)})
  admin.verify_derived(stage,base,target,cat)
  size=sum(f.stat().st_size for f in stage.rglob('*') if f.is_file())
  if before+size>max_bytes:raise ValueError('derived comparison storage budget exceeded')
  for d,_,files in os.walk(stage,topdown=False):
   for n in files:
    with open(pathlib.Path(d)/n,'rb') as f:os.fsync(f.fileno())
   p.syncdir(d)
  dest.parent.mkdir(parents=True,exist_ok=True)
  p.syncdir(root);p.syncdir(p.STORE)
  os.rename(stage,dest);p.syncdir(dest.parent)
  return {'base':base,'target':target,'changed_files':len(rows),'limited':sum(x['add'] is None for x in rows),'bytes':size,'derived_total_bytes':before+size}
 finally:
  if stage.exists():shutil.rmtree(stage)

def main():
 a=argparse.ArgumentParser();a.add_argument('--base',required=True);a.add_argument('--target',required=True);a.add_argument('--max-storage-mib',type=int,default=512);a.add_argument('--confirm',action='store_true');args=a.parse_args()
 if not args.confirm or not 1<=args.max_storage_mib<=4096:a.error('explicit --confirm and 1..4096 MiB required')
 with open(p.STORE/'system/publish.mutex','a') as lock:
  fcntl.flock(lock,fcntl.LOCK_EX);result=rebuild(args.base,args.target,args.max_storage_mib*1024*1024)
 print(json.dumps(result))
if __name__=='__main__':main()
