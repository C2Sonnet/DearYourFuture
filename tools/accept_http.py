#!/usr/bin/env python3
"""Read-only HTTP acceptance of an operator-owned isolated code-publication origin."""
import argparse, hashlib, json, re, sys, urllib.request, urllib.error, urllib.parse
from html.parser import HTMLParser

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

class Markers(HTMLParser):
    def __init__(self): super().__init__(); self.ids=set()
    def handle_starttag(self, tag, attrs):
        a=dict(attrs)
        if 'id' in a: self.ids.add(a['id'])

PAGES=['','?page=code&dir=app','?page=code&doc=LICENSE','?page=code&doc=SECURITY.md','?page=file&path=README.md','?page=file&path=README.md&view=rendered','?page=branches','?page=commits','?page=commit','?page=compare','?page=tags','?page=releases','?page=development']
ASSETS=['assets/site.css','assets/site.js','assets/theme-init.js','assets/author-avatar.png','assets/project-favicon.ico','assets/vendor/highlight/highlight.min.js','assets/code-highlight.js','assets/highlight-theme.css','robots.txt']
DENY=['storage/catalog.json','config/site.php','tools/publish.py','seed/KimiNoMirai_v2.6.1_GitHub_Source.zip','release-manifest.json','app/Repository.php','.htaccess','.git/config','unknown.php','index.php/extra','missing-path','?page=file&path=..%2Fetc%2Fpasswd','?page=unknown']

def origin(value):
    u=urllib.parse.urlsplit(value)
    if u.scheme not in ('https','http') or not u.hostname or u.username or u.password or u.path not in ('','/') or u.query or u.fragment:
        raise ValueError('origin only; no path, credentials, query or fragment')
    if u.scheme=='http' and u.hostname not in ('127.0.0.1','::1'):
        raise ValueError('plain HTTP permitted only on literal loopback')
    return value.rstrip('/')+'/'

def run(base, zip_sha, tar_sha, timeout=10):
    base=origin(base)
    for h in (zip_sha,tar_sha):
        if not re.fullmatch('[a-f0-9]{64}',h): raise ValueError('expected SHA256 required')
    opener=urllib.request.build_opener(urllib.request.ProxyHandler({}),NoRedirect())
    results=[]
    def check(path, method='GET', expected=(200,), kind=None, digest=None):
        ok=False; status=None; why=''
        try:
            req=urllib.request.Request(base+path,method=method,headers={'User-Agent':'CodePublication-Acceptance/1','Accept-Encoding':'identity'})
            try: res=opener.open(req,timeout=timeout)
            except urllib.error.HTTPError as e: res=e
            with res:
                status=res.code
                if status not in expected: raise ValueError('unexpected status')
                if kind=='html':
                    csp=res.headers.get('Content-Security-Policy','')
                    if "script-src 'self'" not in csp or res.headers.get('X-Content-Type-Options','').lower()!='nosniff': raise ValueError('security header')
                    raw=res.read(8*1024*1024+1)
                    if len(raw)>8*1024*1024: raise ValueError('HTML size limit')
                    p=Markers(); p.feed(raw.decode('utf-8'))
                    if not {'main','theme-toggle','back-to-top'}.issubset(p.ids): raise ValueError('HTML markers')
                elif kind=='download':
                    if 'attachment' not in res.headers.get('Content-Disposition','').lower(): raise ValueError('attachment header')
                    h=hashlib.sha256(); total=0
                    while chunk:=res.read(65536):
                        total+=len(chunk)
                        if total>128*1024*1024: raise ValueError('download size limit')
                        h.update(chunk)
                    if h.hexdigest()!=digest: raise ValueError('download SHA256 mismatch')
                elif kind=='asset':
                    if not res.read(1): raise ValueError('empty asset')
                elif method=='HEAD' and res.read(1): raise ValueError('HEAD body')
                ok=True
        except ValueError as e: why=str(e) if str(e) in ('unexpected status','security header','HTML size limit','HTML markers','attachment header','download size limit','download SHA256 mismatch','empty asset','HEAD body') else 'invalid response'
        except Exception: why='transport or response error'
        results.append({'method':method,'route':path or '/', 'status':status,'result':'PASS' if ok else 'FAIL','reason':why})
    for p in PAGES: check(p,kind='html')
    for p in ASSETS: check(p,kind='asset')
    check('?page=image&path=public%2Fassets%2Fkimi-no-mirai-title.jpg',kind='asset')
    check('?page=download&type=zip',kind='download',digest=zip_sha)
    check('?page=download&type=tar.gz',kind='download',digest=tar_sha)
    for p in ('?page=code','?page=download&type=zip'): check(p,method='HEAD')
    for p in DENY: check(p,expected=(403,404))
    check('?page%5B%5D=code',expected=(400,))
    for m in ('POST','PUT'): check('?page=code',method=m,expected=(405,))
    passed=sum(x['result']=='PASS' for x in results)
    return {'passed':passed,'total':len(results),'http_acceptance':'PASS' if passed==len(results) else 'FAIL','release_gate':'HOLD','not_verified':['PHP-FPM runtime identity','MySQL','browser','rights and privacy','production rollback'], 'checks':results}

def main():
    p=argparse.ArgumentParser();p.add_argument('origin');p.add_argument('--zip-sha256',required=True);p.add_argument('--tar-sha256',required=True);p.add_argument('--confirm-owned-isolated',action='store_true');a=p.parse_args()
    if not a.confirm_owned_isolated: p.error('operator-owned isolated environment confirmation required')
    try: r=run(a.origin,a.zip_sha256,a.tar_sha256)
    except ValueError as e: p.error(str(e))
    print(json.dumps(r,ensure_ascii=False,indent=2));return int(r['http_acceptance']!='PASS')
if __name__=='__main__': sys.exit(main())
