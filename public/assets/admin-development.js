// Local Mermaid module; no inline JS or external CDN required.
import mermaid from './vendor/mermaid-11.12.2/mermaid-entry.js';
const nodes=document.querySelectorAll('[data-admin-mermaid]');
mermaid.initialize({startOnLoad:false,securityLevel:'strict',theme:document.documentElement.dataset.theme==='dark'?'dark':'default',flowchart:{htmlLabels:false}});
for(const [index,node] of [...nodes].entries()){
 const source=node.querySelector('[data-admin-mermaid-source]');const target=node.querySelector('[data-admin-mermaid-output]');
 if(!source||!target)continue;
 try{const result=await mermaid.render('kmm-waterfall-'+index,source.textContent||'');
  if(!result.svg||result.svg.length>180000)continue;
  const img=document.createElement('img');img.className='r06-mermaid-image';img.alt='要件定義から保守までの工程図。工程別の文書一覧はこの下にあります。';
  img.addEventListener('load',()=>{source.hidden=true;},{once:true});
  img.src='data:image/svg+xml;charset=utf-8,'+encodeURIComponent(result.svg);target.replaceChildren(img);
 }catch(_e){source.hidden=false;}
}
