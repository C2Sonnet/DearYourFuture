#!/usr/bin/env python3
"""Browser acceptance for explicitly authorized, isolated synthetic/test origin. No browser policy bypass."""
import argparse, json, sys, urllib.parse
from accept_http import origin
WIDTHS=(320,390,768,1024,1200)
ROUTES=('', '?page=branches','?page=commits','?page=commit','?page=compare','?page=tags','?page=releases','?page=file&path=README.md','?page=file&path=README.md&view=rendered','?page=development')
def run(base, allow_google_fonts=False):
    from playwright.sync_api import sync_playwright
    base=origin(base);checks=[]
    def check(name,fn):
        try:fn();checks.append({'name':name,'result':'PASS'})
        except Exception:checks.append({'name':name,'result':'FAIL'})
    def require(ok):
        if not ok:raise AssertionError('browser acceptance condition')
    with sync_playwright() as p:
        browser=p.chromium.launch()
        try:
            for width in WIDTHS:
                ctx=browser.new_context(viewport={'width':width,'height':900},color_scheme='light',reduced_motion='reduce',service_workers='block');expected=urllib.parse.urlsplit(base);ctx.route('**/*',lambda route:route.continue_() if (urllib.parse.urlsplit(route.request.url).netloc==expected.netloc and urllib.parse.urlsplit(route.request.url).scheme==expected.scheme) or (allow_google_fonts and urllib.parse.urlsplit(route.request.url).scheme=='https' and urllib.parse.urlsplit(route.request.url).netloc in ('fonts.googleapis.com','fonts.gstatic.com')) else route.abort());page=ctx.new_page();errors=[]
                page.on('pageerror',lambda _:errors.append('pageerror'))
                page.on('console',lambda msg:errors.append('console error') if msg.type=='error' and 'content security policy' in msg.text.lower() else None)
                def layout(route):
                    response=page.goto(base+route,wait_until='domcontentloaded',timeout=10000);require(response and response.status==200);require(page.locator('#main').count()==1);require(page.evaluate('document.documentElement.scrollWidth <= innerWidth+1'))
                # Fail fast if the isolated origin is unavailable; never bypass policy.
                layout('')
                for route in ROUTES:check(f'{width}: layout {route or "/"}',lambda r=route:layout(r))
                def theme():
                    layout('');require(page.locator('html').get_attribute('data-theme')=='dark');page.locator('#theme-toggle').click();require(page.locator('html').get_attribute('data-theme')=='light');page.locator('#theme-toggle').click();require(page.locator('html').get_attribute('data-theme')=='dark');page.reload();require(page.locator('html').get_attribute('data-theme')=='dark');page.locator('#theme-toggle').click();require(page.locator('html').get_attribute('data-theme')=='light');page.emulate_media(color_scheme='dark');require(page.locator('html').get_attribute('data-theme')=='light');page.locator('#theme-toggle').click()
                check(f'{width}: theme and reload',theme)
                def search():
                    layout('?page=branches');box=page.locator('[data-filter="branch-list"]');box.fill('not-a-branch-123456');require(page.locator('#branch-list > :visible').count()==0);box.fill('main');require(page.locator('#branch-list > :visible').count()==1)
                check(f'{width}: branch search',search)
                def disclosure():
                    layout('');pop=page.locator('details.dropdown').first;summary=pop.locator('summary');summary.focus();summary.press('Enter');require(pop.evaluate('(x)=>x.open'));pop.locator('.popover[data-placed=true]').wait_for(state='visible');require(pop.locator('.popover').evaluate('(x)=>{const r=x.getBoundingClientRect();return r.left>=0&&r.right<=innerWidth+1&&r.top>=0&&r.bottom<=innerHeight+1&&r.width>0&&r.height>0}'));summary.press('Escape');require(not pop.evaluate('(x)=>x.open'));require(summary.evaluate('(x)=>x===document.activeElement'))
                check(f'{width}: keyboard disclosure',disclosure)
                def top():
                    layout('?page=file&path=README.md');page.evaluate('window.scrollTo(0,document.body.scrollHeight)');page.locator('#back-to-top').click();require(page.evaluate('scrollY===0 && document.activeElement.id==="top"'))
                check(f'{width}: back to top',top)
                def branding():
                    layout('');require(page.locator('html').get_attribute('lang')=='ja');require(page.locator('link[rel="icon"]').get_attribute('href').split('?')[0]=='/assets/project-favicon.ico');require(page.locator('.repo-title img').get_attribute('src').split('?')[0]=='/assets/project-favicon.ico');profile=page.locator('details.profile');profile.locator('summary').click();require(profile.evaluate('(x)=>x.open'));require('光と影のソネット' in profile.inner_text());require(profile.locator('a[href="https://im.c2s.me"]').is_visible());require(profile.locator('.author-large').evaluate('(x)=>x.complete && x.naturalWidth>0'));require(page.locator('.repo-title img').evaluate('(x)=>x.complete && x.naturalWidth>0'))
                check(f'{width}: Japanese branding and author',branding)
                def documents():
                    layout('');page.locator('aside a[href*="doc=LICENSE"]').click();require(page.evaluate('location.hash==="#documentation"'));require(page.locator('#documentation').is_visible());require(page.locator('.tabs a[aria-current="page"]').inner_text()=='ライセンス');page.locator('aside a[href*="doc=SECURITY.md"]').click();require(page.evaluate('location.hash==="#documentation"'));require(page.locator('.tabs a[aria-current="page"]').inner_text()=='セキュリティ')
                check(f'{width}: document navigation',documents)
                def highlighting():
                    layout('?page=file&path=README.md');require(page.locator('#code-lines').get_attribute('data-code-language')=='markdown');require(page.locator('#code-lines .hljs-line').count()>0);require(page.evaluate('typeof hljs!=="undefined"'))
                check(f'{width}: syntax highlighting',highlighting)
                def development():
                    layout('');page.locator('nav a[href*="page=development"]').click();require(page.locator('nav a[aria-current="page"]').inner_text()=='開発資料');require(page.locator('.development-flow li').count()==4);require(page.locator('.development-document').count()==8);require(page.evaluate('getComputedStyle(document.body).fontFamily.includes("Zen Maru Gothic")'));require(page.locator('link[href*="fonts.googleapis.com/css2"]').count()==1)
                check(f'{width}: development and font configuration',development)
                check(f'{width}: JS/CSP errors',lambda:require(not errors));ctx.close()
        finally:browser.close()
    return {'checks':checks,'passed':sum(x['result']=='PASS' for x in checks),'total':len(checks),'release_gate':'HOLD','manual_remaining':['clipboard, touch and mobile devices','screen reader, zoom, contrast and screenshots','real Git diff if source available','Firefox and other browser engines']}
def main():
    a=argparse.ArgumentParser();a.add_argument('origin',nargs='?');a.add_argument('--confirm-owned-isolated',action='store_true');a.add_argument('--describe',action='store_true');a.add_argument('--allow-google-fonts',action='store_true');x=a.parse_args()
    if x.describe:print(json.dumps({'widths':WIDTHS,'routes':ROUTES,'checks_per_width':len(ROUTES)+9,'release_gate':'HOLD'}));return 0
    if not x.origin or not x.confirm_owned_isolated:a.error('explicit isolated origin confirmation required')
    try:r=run(x.origin,x.allow_google_fonts)
    except Exception:print(json.dumps({'result':'HOLD','reason':'browser unavailable or execution refused','release_gate':'HOLD'}));return 1
    print(json.dumps(r,ensure_ascii=False,indent=2));return int(r['passed']!=r['total'])
if __name__=='__main__':sys.exit(main())
