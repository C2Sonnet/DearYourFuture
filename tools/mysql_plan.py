#!/usr/bin/env python3
"""Export audited catalog metadata for isolated MySQL migration rehearsal; no DB writes."""
import argparse,fcntl,pathlib,json,os
import publish as p
import storage_admin as admin

def plan(root=None):
 root=p.STORE if root is None else pathlib.Path(root)
 summary=admin.audit(root)
 catalog_path=root/'catalog.json'
 cat=p.read(catalog_path);rows=[];known=set();indices={}
 for row in reversed(cat['versions']):
  tag=row['tag']; known.add(tag); files=p.read(root/'snapshots'/tag/'files.json'); indices[tag]=files; entries=[]
  for name,e in sorted(files.items()):
   last=e.get('last_changed',tag)
   if last not in known:raise ValueError('invalid last_changed reference')
   prior=indices[last].get(name)
   if prior is None or prior['sha256']!=e['sha256']:raise ValueError('last_changed hash mismatch')
   if e['bytes']!=(root/'snapshots'/tag/'files'/name).stat().st_size:raise ValueError('file size mismatch')
   entries.append({'path':name,'sha256':e['sha256'],'bytes':e['bytes'],'last_changed':last})
  if len(entries)!=row['files']:raise ValueError('file count mismatch')
  rows.append({'metadata':row,'files':entries})
 return {'format':1,'catalog_sha256':p.filehash(catalog_path),'latest':cat['latest'],'summary':summary,'releases':rows}

def main():
 a=argparse.ArgumentParser();a.add_argument('--output',type=pathlib.Path,required=True);args=a.parse_args()
 admin.ensure_new(args.output.absolute())
 with open(p.STORE/'system/publish.mutex','a') as f:
  fcntl.flock(f,fcntl.LOCK_EX); data=plan()
 with args.output.open('xb') as f:f.write(p.encoded(data));f.flush();os.fsync(f.fileno())
 print(json.dumps({'summary':data['summary'],'sha256':p.filehash(args.output)}))
if __name__=='__main__':main()
