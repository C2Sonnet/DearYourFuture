#!/usr/bin/env python3
"""External acceptance harness: synthetic data, two operator-provisioned EMPTY test DBs. No automatic cutover."""
import argparse, hashlib, json, os, pathlib, re, shutil, subprocess, tempfile, zipfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def config(env):
    names=[env.get('CP_MYSQL_DATABASE',''),env.get('CP_RESTORE_MYSQL_DATABASE','')]
    if len(set(names))!=2 or any(not re.fullmatch(r'codepublication_test_[a-z0-9_]{1,30}',n) for n in names):raise ValueError('two distinct test database names required')
    if env.get('CP_MYSQL_HOST','127.0.0.1') not in ('127.0.0.1','localhost','::1'):raise ValueError('local DB only')
    if not env.get('CP_MYSQL_USER') or 'CP_MYSQL_PASSWORD' not in env:raise ValueError('test credentials required')
    if not env.get('CP_READER_USER') or env.get('CP_READER_USER')==env.get('CP_MYSQL_USER') or 'CP_READER_PASSWORD' not in env:raise ValueError('distinct SELECT-only reader credentials required')
    if env.get('CP_READER_HOST','127.0.0.1') not in ('127.0.0.1','localhost','::1'):raise ValueError('local reader only')
    return names

def fixture(path, i):
    v=f'0.0.{i}';data={'VERSION':v.encode(),'README.md':f'# Synthetic {i}\n'.encode(),'LICENSE':b'Synthetic test material only'}
    with zipfile.ZipFile(path,'w') as f:
        for k,b in data.items():f.writestr(k,b)
        f.writestr('release-manifest.json',json.dumps({'version':v,'variant':'GitHub_Source','deployment_allowed':False,'files':{k:hashlib.sha256(b).hexdigest() for k,b in data.items()}}))

def run(env):
    names=config(env);results=[]
    def step(label,cmd,expected=0,marker=None,env_override=None):
        r=subprocess.run(list(map(str,cmd)),env=env_override or env,capture_output=True,text=True,timeout=180)
        ok=(r.returncode==expected and (marker is None or marker in (r.stdout+r.stderr)))
        results.append({'step':label,'result':'PASS' if ok else 'FAIL','exit_code':r.returncode})
        if not ok:raise RuntimeError(label)
        return r.stdout
    try:
        with tempfile.TemporaryDirectory(prefix='cp-db-test-') as t:
            base=pathlib.Path(t);app=base/'app';app.mkdir()
            for name in ('tools','database','app'):shutil.copytree(ROOT/name,app/name,ignore=shutil.ignore_patterns('__pycache__'))
            tools=app/'tools';store=app/'storage';schema=app/'database/migrations/001_catalog_design.sql';plans=[]
            def publish(i):
                z=base/f'{i}.zip';fixture(z,i);step(f'publish-{i}',['python3',tools/'publish.py',z,'--sha256',sha(z),'--approve-public']);p=base/f'plan-{i}.json';step(f'audit-{i}',['python3',tools/'mysql_plan.py','--output',p]);plans.append(p)
            def migrate(p,e):return ['php',tools/'mysql_migrate.php','--plan='+str(p),'--sha256='+sha(p),'--apply','--confirm-isolated-db']
            def sync(old,new,storage,fault=None):
                args=['php',tools/'mysql_sync.php','--previous='+str(old),'--previous-sha256='+sha(old),'--target='+str(new),'--target-sha256='+sha(new),'--storage='+str(storage),'--schema='+str(schema),'--apply','--confirm-isolated-db']
                return args+(['--test-fault='+fault] if fault else [])
            publish(1);step('initial-import',migrate(plans[0],env),marker='Isolated import/readback complete');publish(2)
            step('before-commit-interruption',sync(*plans,store,'before-commit'),1,'injected before commit')
            step('retry-after-rollback',sync(*plans,store,'after-commit'),1,'injected after commit')
            step('retry-after-lost-ack',sync(*plans,store),marker='already-synchronized')
            step('verified-noop',sync(plans[1],plans[1],store),marker='already-synchronized')
            def reader(storage,db):
                e=env.copy();e['CP_READER_DATABASE']=db;step('reader-'+('primary' if db==names[0] else 'restored'),['php',tools/'verify_mysql_reader.php','--storage='+str(storage),'--confirm-isolated-reader'],marker='"result":"PASS"',env_override=e)
            reader(store,names[0])
            bundle=base/'bundle';report=json.loads(step('backup',['python3',tools/'mysql_recovery_bundle.py','create','--bundle',bundle]));rest=base/'restored'
            step('restore-storage',['python3',tools/'mysql_recovery_bundle.py','restore','--bundle',bundle,'--sha256',report['manifest_sha256'],'--dest',rest])
            e=env.copy();e['CP_MYSQL_DATABASE']=names[1];step('rebuild-fresh-db',migrate(rest/'plan.json',e),marker='Isolated import/readback complete',env_override=e)
            step('restored-readback',sync(rest/'plan.json',rest/'plan.json',rest/'storage'),marker='already-synchronized',env_override=e)
            reader(rest/'storage',names[1])
    except (RuntimeError,ValueError,OSError,subprocess.SubprocessError) as e:
        return {'result':'FAIL','checks':results,'release_gate':'HOLD','note':'Stop; inspect isolated DBs privately. No automatic cleanup.'}
    return {'result':'PASS','checks':results,'release_gate':'HOLD','note':'Synthetic DB projection only; DBs retained for operator inspection. Web mode, privileges and production still require acceptance.'}

def main():
    a=argparse.ArgumentParser();a.add_argument('--confirm-two-empty-isolated-test-dbs',action='store_true');x=a.parse_args()
    if not x.confirm_two_empty_isolated_test_dbs:a.error('explicit confirmation required; never use a production DB')
    try:r=run(os.environ.copy())
    except ValueError:a.error('invalid isolated database configuration')
    print(json.dumps(r,ensure_ascii=False,indent=2));return int(r['result']!='PASS')
if __name__=='__main__':raise SystemExit(main())
