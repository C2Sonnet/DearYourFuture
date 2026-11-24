#!/usr/bin/env python3
"""Metadata-only candidate inventory. No matched strings, no external requests, no publication approval."""
import argparse, collections, hashlib, json, pathlib, re, stat, zipfile
import publish as p
RULES={'email_like':rb'(?i)\b[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}\b','jp_phone_like':rb'(?<![0-9])(?:0[789]0|050)[- ]?[0-9]{4}[- ]?[0-9]{4}(?![0-9])','private_path_like':rb'(?i)(?:[A-Z]:\\Users\\|/home/|/Users/)[^\s\x22\x27<>]{2,}'}
def category(name):
    n=name.lower();ext=pathlib.PurePosixPath(n).suffix
    if n.startswith('public/assets/vendor/'):return 'third_party_vendor'
    if 'letter' in n or 'message' in n or 'privacy' in n:return 'personal_context_review'
    if n.startswith(('database/','resources/research/','docs/research_')) or n.startswith('docs/accepted_research') or n.startswith('docs/change25') or ext in ('.sql','.csv'):return 'research_or_database'
    if ext in ('.jpg','.jpeg','.png','.gif','.svg','.webp','.ico','.pdf','.woff','.woff2'):return 'media_rights'
    if n in ('license','composer.json','docs/third_party_notices.md'):return 'licence_and_dependencies'
    if n.startswith('docs/') or ext in ('.md','.txt'):return 'documentation_review'
    return 'code_and_configuration_review'
def audit(path,expected):
    if not re.fullmatch('[a-f0-9]{64}',expected) or p.filehash(path)!=expected:raise ValueError('archive SHA mismatch')
    members={}
    with zipfile.ZipFile(path) as z:
        if len(z.infolist())>10000 or sum(i.file_size for i in z.infolist())>300_000_000:raise ValueError('resource limits')
        for i in z.infolist():
            if i.is_dir():continue
            p.validate_name(i.filename, expected==p.APPROVED_WATERFALL_SHA)
            if i.filename in members or i.flag_bits&1 or stat.S_IFMT(i.external_attr>>16) not in (0,stat.S_IFREG) or i.file_size>30_000_000 or i.file_size>max(i.compress_size,1)*500:raise ValueError('archive member refused')
            members[i.filename]=z.read(i)
    m=json.loads(members['release-manifest.json']);actual={n:p.sha(b) for n,b in members.items() if n!='release-manifest.json'}
    if m['variant']!='GitHub_Source' or m['deployment_allowed'] is not False or actual!=m['files'] or members['VERSION'].decode().strip().lstrip('v')!=m['version']:raise ValueError('invalid source variant or manifest')
    items=[];counts=collections.Counter();pattern_counts=collections.Counter()
    for n,b in sorted(members.items()):
        cat=category(n);counts[cat]+=1;patterns={k:len(re.findall(v,b)) for k,v in RULES.items()} if p.text(b) is not None else {}
        patterns={k:v for k,v in patterns.items() if v};pattern_counts.update(patterns)
        items.append({'path':n,'category':cat,'sha256':p.sha(b),'bytes':len(b),'heuristic_counts':patterns,'disposition':'OPERATOR_REVIEW_REQUIRED'})
    findings=p.scan_secrets(members)
    return {'version':m['version'],'archive_sha256':expected,'members':len(items),'category_counts':dict(counts),'heuristic_counts':dict(pattern_counts),'secret_findings':findings,'block_findings':sum(f['count'] for f in findings if f['severity']=='block'),'items':items,'release_gate':'HOLD','limitations':'Inventory and heuristics only; patterns include examples and public contacts. No values retained. No rights or consent certification.'}
def main():
    a=argparse.ArgumentParser();a.add_argument('archive',type=pathlib.Path);a.add_argument('--sha256',required=True);a.add_argument('--output',type=pathlib.Path,required=True);x=a.parse_args()
    try:
        if x.output.exists() or x.output.is_symlink():raise ValueError('output already exists')
        r=audit(x.archive,x.sha256)
        with x.output.open('x',encoding='utf8') as f:json.dump(r,f,ensure_ascii=False,indent=2)
        print(json.dumps({k:v for k,v in r.items() if k not in ('items','secret_findings')},ensure_ascii=False))
    except (OSError,ValueError,KeyError,TypeError,zipfile.BadZipFile):a.exit(1,'Inventory refused.\n')
if __name__=='__main__':main()
