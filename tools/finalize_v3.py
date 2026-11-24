#!/usr/bin/env python3
"""Final content gate for c1.0.0. Default: read-only. Never uses private archives."""
import argparse, hashlib, json, pathlib, re, subprocess, sys, tempfile, zipfile

PDFS=tuple('docs/development/'+n+'.pdf' for n in ('requirements','requirements-spec','basic-design','detailed-design','test-spec','test-report','operations','maintenance'))
PINNED_SHA='1e48f8dbb23ad2c918d58ad5d40055d279ab7f68fe71c0c52ef6c5517247bc4f'
APPROVED_PDFS=('resources/private/waterfall/キミの未来へ_要件定義_要件定義書.pdf', 'resources/private/waterfall/キミの未来へ_要件定義_仕様書.pdf', 'resources/private/waterfall/キミの未来へ_基本設計_基本設計書.pdf', 'resources/private/waterfall/キミの未来へ_詳細設計_詳細設計書.pdf', 'resources/private/waterfall/キミの未来へ_テスト_テスト仕様書.pdf', 'resources/private/waterfall/キミの未来へ_テスト_テスト報告書.pdf', 'resources/private/waterfall/キミの未来へ_運用_機能説明書.pdf', 'resources/private/waterfall/キミの未来へ_保守_保守マニュアル.pdf')
NOTES='app/data/v3.0.0-release-notes.md'
TAG='v3.0.0'

def sha(p):
    with p.open('rb') as f:return hashlib.file_digest(f,'sha256').hexdigest()

def demand(ok,msg):
    if not ok:raise ValueError(msg)

def run(root,archive,expected,apply=False,approved=False):
    root=root.resolve(); archive=archive.resolve()
    demand(re.fullmatch('[a-f0-9]{64}',expected) is not None,'独立台帳のSHA256が必要です')
    demand((root/'tools/publish.py').is_file() and (root/NOTES).is_file(),'コード公開サイトのルートを指定してください')
    demand(archive.is_file() and archive.stat().st_size<=100_000_000,'公開用ZIPが存在しないか、上限を超えています')
    demand(not apply or approved,'公開権利・個人情報の確認後に --approve-public を指定してください')
    # Stage a stable copy outside the web root; publish.py independently rechecks it.
    with tempfile.TemporaryDirectory(prefix='cp-v3-final-') as tmp:
        staged=pathlib.Path(tmp)/'approved-source.zip'
        with archive.open('rb') as src,staged.open('wb') as dst:
            while chunk:=src.read(1024*1024):
                demand(dst.tell()+len(chunk)<=100_000_000,'ZIPサイズ上限超過');dst.write(chunk)
        demand(sha(staged)==expected,'公開用ZIPのSHA256不一致')
        cmd=[sys.executable,str(root/'tools/publish.py'),str(staged),'--sha256',expected]
        p=subprocess.run(cmd+['--check-only'],cwd=root,capture_output=True,text=True)
        demand(p.returncode==0,'publish.py の原本検査に不合格（単独の --check-only で確認）')
        with zipfile.ZipFile(staged) as z:
            m=json.loads(z.read('release-manifest.json'))
            demand(m.get('version')=='3.0.0' and m.get('variant')=='GitHub_Source' and m.get('deployment_allowed') is False,'v3.0.0の公開専用原本ではありません')
            pdfs=APPROVED_PDFS if expected==PINNED_SHA else PDFS
            if expected==PINNED_SHA:demand(m.get('public_source_approved_by_operator') is True and m.get('development_pdf_count')==8,'公開承認メタデータ不一致')
            missing=[name for name in pdfs if name not in m['files']]
            demand(not missing,'公開用PDF未収録: '+', '.join(missing))
            for name in pdfs:
                with z.open(name) as f:demand(f.read(5)==b'%PDF-','PDFヘッダー不一致: '+name)
        result={'tag':TAG,'archive_sha256':expected,'documents':len(PDFS),'notes_sha256':sha(root/NOTES),'mode':'check-only','registration':'NOT_REQUESTED','rights_privacy':'OPERATOR_CONFIRMATION_REQUIRED','formal_release':'NOT_AUTOMATIC'}
        if apply:
            # No upstream commit ID is invented. Existing publisher creates an archive-derived ID.
            p=subprocess.run(cmd+['--approve-public','--message','[リリース] v3.0.0 - Dear Your Future 正式版','--notes',str(root/NOTES)],cwd=root,capture_output=True,text=True)
            demand(p.returncode==0,'登録失敗。既存ストレージとジャーナルを確認してください。自動上書き・再試行なし。')
            cat=json.loads((root/'storage/catalog.json').read_text('utf-8'))
            row=next((r for r in cat['versions'] if r['tag']==TAG),None)
            demand(cat['latest']==TAG and row is not None and row['digest']==expected and row['notes']==(root/NOTES).read_text('utf-8'),'登録後のカタログ照合に失敗')
            result.update(mode='apply',registration='PASS',rights_privacy='OPERATOR_CONFIRMED',parent=row['parent'],snapshot_id=row['id'])
        return result

def main():
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('archive',type=pathlib.Path);p.add_argument('--root',type=pathlib.Path,default=pathlib.Path.cwd());p.add_argument('--sha256',required=True);p.add_argument('--apply',action='store_true');p.add_argument('--approve-public',action='store_true');a=p.parse_args()
    try:print(json.dumps(run(a.root,a.archive,a.sha256,a.apply,a.approve_public),ensure_ascii=False,indent=2))
    except (ValueError,OSError,KeyError,RuntimeError,zipfile.BadZipFile) as e:print('HOLD: '+str(e),file=sys.stderr);return 1
    return 0
if __name__=='__main__':sys.exit(main())
