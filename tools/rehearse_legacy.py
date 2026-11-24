#!/usr/bin/env python3
"""Rebuild an OFFLINE legacy storage copy into a new isolated directory. Never deploy."""
import argparse, json, pathlib, shutil, subprocess, sys, tempfile, os
import publish as p
import storage_admin as admin

def run(script,*args):
    subprocess.run([sys.executable,str(script),*map(str,args)],check=True,stdout=subprocess.DEVNULL)

def rehearse(legacy,ledger,expected,dest):
    admin.ensure_new(dest)
    if legacy.is_symlink() or not legacy.is_dir() or legacy.resolve().is_relative_to(p.ROOT.resolve()) or dest.resolve().is_relative_to(legacy.resolve()):
        raise ValueError('use an offline storage copy outside application; destination separate')
    if p.filehash(ledger)!=expected: raise ValueError('independent ledger SHA256 mismatch')
    approvals=p.read(ledger)
    if approvals.get('schema')!=1 or not isinstance(approvals.get('archives'),dict): raise ValueError('invalid ledger')
    old_summary=admin.audit(legacy); before=p.inventory(legacy); old=p.read(legacy/'catalog.json')
    rows=list(reversed(old['versions'])); tags=[r['tag'] for r in rows]
    if set(approvals['archives'])!=set(tags): raise ValueError('ledger must cover exactly all versions')
    for row in rows:
        tag=row['tag']; digest=approvals['archives'][tag]
        if digest!=row['digest'] or digest!=p.filehash(legacy/'snapshots'/tag/'source.zip'): raise ValueError('independent archive digest mismatch: '+tag)
    stage=pathlib.Path(tempfile.mkdtemp(prefix='.cp-rehearsal-',dir=dest.parent))
    try:
        shutil.copytree(p.ROOT/'tools',stage/'tools',ignore=shutil.ignore_patterns('__pycache__'))
        for row in rows:
            tag=row['tag']; archive=legacy/'snapshots'/tag/'source.zip'; notes=stage/'notes.md'; notes.write_text(row.get('notes',''),encoding='utf-8')
            args=[archive,'--sha256',approvals['archives'][tag],'--approve-public','--message',row['message'],'--notes',notes]
            for key,flag in [('upstream_commit','--upstream-commit'),('upstream_parent','--upstream-parent')]:
                if row.get(key):args.extend([flag,row[key]])
            run(stage/'tools/publish.py',*args)
            notes.unlink()
        store=stage/'storage'; new=p.read(store/'catalog.json'); new_rows={r['tag']:r for r in new['versions']}
        pairs=[]
        # Restore coverage of comparisons formerly present; recalculate instead of trusting old hashes.
        for row in rows:
            tag=row['tag']; oldsnap=legacy/'snapshots'/tag; newsnap=store/'snapshots'/tag
            for base in sorted({x.name for d in (oldsnap/'comparisons',legacy/'derived_comparisons'/tag) if d.exists() for x in d.iterdir()}):
                if not (newsnap/'comparisons'/base/'changes.json').is_file() and not (store/'derived_comparisons'/tag/base/'changes.json').is_file():
                    run(stage/'tools/rebuild_comparison.py','--base',base,'--target',tag,'--confirm')
                pairs.append([base,tag])
            n=new_rows[tag]
            for field in ('tag','date','message','digest','id','upstream_commit','upstream_parent','parent','files','languages','notes'):
                if row.get(field)!=n.get(field): raise ValueError('metadata mismatch: '+tag+'/'+field)
            if p.inventory(oldsnap/'files')!=p.inventory(newsnap/'files'): raise ValueError('source inventory mismatch: '+tag)
        if admin.audit(store)!=old_summary or p.inventory(legacy)!=before: raise ValueError('source changed or summary mismatch')
        shutil.rmtree(stage/'tools')
        report={'status':'REHEARSAL_ONLY','summary':old_summary,'source_unchanged':True,'independent_ledger_sha256':expected,'comparison_pairs_preserved':pairs,'registration_times_recomputed':True,'publication_authorized':False,'note':'Original dates/messages preserved; generated registration times and tar/diff hashes may differ. Operator acceptance required.'}
        p.atomic(stage/'rehearsal-report.json',report)
        os.rename(stage,dest);p.syncdir(dest.parent)
        return report
    finally:
        if stage.exists(): shutil.rmtree(stage)

def main():
    a=argparse.ArgumentParser(description=__doc__);a.add_argument('--legacy-storage',required=True,type=pathlib.Path);a.add_argument('--ledger',required=True,type=pathlib.Path);a.add_argument('--sha256',required=True);a.add_argument('--dest',required=True,type=pathlib.Path);a.add_argument('--confirm-rehearsal',action='store_true');x=a.parse_args()
    if not x.confirm_rehearsal:a.error('explicit --confirm-rehearsal required; no public deployment')
    print(json.dumps(rehearse(x.legacy_storage.absolute(),x.ledger.absolute(),x.sha256.lower(),x.dest.absolute()),ensure_ascii=False))
if __name__=='__main__':main()
