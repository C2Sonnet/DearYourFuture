"""Read-only HTTP/HTML structural check; not a browser/layout/accessibility certification."""
import sys,urllib.request
from html.parser import HTMLParser
class Parser(HTMLParser):
 def __init__(self):super().__init__();self.tags=[]
 def handle_starttag(self,tag,attrs):self.tags.append((tag,dict(attrs)))
 def find(self,tag=None,**attrs):return [a for t,a in self.tags if (tag is None or t==tag) and all(a.get(k)==v for k,v in attrs.items())]
queries=['','?page=code&dir=app','?page=code&doc=LICENSE','?page=code&doc=SECURITY.md','?page=file&path=README.md','?page=file&path=README.md&view=rendered','?page=branches','?page=branches&filter=Stale','?page=commits','?page=commit','?page=compare','?page=tags','?page=releases','?page=development']
for q in queries:
 with urllib.request.urlopen(sys.argv[1].rstrip('/')+'/'+q,timeout=10) as res:
  assert res.status==200 and "script-src 'self'" in res.headers['Content-Security-Policy'];p=Parser();p.feed(res.read().decode())
 ids=[a['id'] for _,a in p.tags if 'id' in a];assert len(ids)==len(set(ids)),q
 for a in p.find('input',type='search'):assert a.get('aria-label') or a.get('aria-labelledby'),q
 assert p.find('main',id='main',tabindex='-1') and p.find('body',id='top',tabindex='-1'),q
 assert p.find('a',id='back-to-top',href='#top') and p.find('button',id='theme-toggle',type='button'),q
 assert p.find('div',id='notice',role='status') and p.find('meta',name='viewport'),q
 assert all(a.get('src','').startswith('/assets/') for a in p.find('script')),q
 for t,a in p.tags:
  if 'code-scroll' in a.get('class','').split():assert a.get('tabindex')=='0' and a.get('aria-label'),q
 print('PASS',q or '/')
print(f'{len(queries)}/{len(queries)} HTTP structure checks PASS')
