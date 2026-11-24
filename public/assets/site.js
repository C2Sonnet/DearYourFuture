(()=>{'use strict';
 const root=document.documentElement;
 const button=document.querySelector('[data-theme-toggle]');
 const topButton=document.querySelector('[data-back-to-top]');
 const authenticated=root.dataset.authenticated==='1';
 const key='kmm-theme';
 let theme='light';
 if(authenticated){try{if(localStorage.getItem(key)==='dark')theme='dark';}catch(_e){}}
 function applyTheme(next){
   theme=next==='dark'?'dark':'light';root.dataset.theme=theme;
   if(button){button.textContent=theme==='dark'?'☼':'☾';button.setAttribute('aria-label',theme==='dark'?'ライトモードへ切り替える':'ダークモードへ切り替える');button.setAttribute('aria-pressed',theme==='dark'?'true':'false');}
 }
 applyTheme(theme);
 if(button && authenticated)button.addEventListener('click',()=>{applyTheme(theme==='light'?'dark':'light');if(authenticated){try{localStorage.setItem(key,theme);}catch(_e){}}void showMermaidFlows();});
 if(topButton)topButton.addEventListener('click',()=>window.scrollTo({top:0,behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'}));
 // Only render explicitly provided private R06 selection steps. The Mermaid 11.12.2
 // renderer and every module it needs are served locally; no external CDN or inline JS.
 let mermaidModulePromise=null;
 let mermaidSerial=0;
 async function showMermaidFlows(){
   if(!authenticated)return;
   const diagrams=[...document.querySelectorAll('[data-r06-mermaid]')];
   if(!diagrams.length)return;
   const request=++mermaidSerial;
   try{
     if(!mermaidModulePromise)mermaidModulePromise=import('/assets/vendor/mermaid-11.12.2/mermaid-entry.js');
     const {default:mermaid}=await mermaidModulePromise;
     if(request!==mermaidSerial)return;
     mermaid.initialize({startOnLoad:false,securityLevel:'strict',theme:theme==='dark'?'dark':'default',
       flowchart:{htmlLabels:false},suppressErrorRendering:true});
     for(let i=0;i<diagrams.length;i++){
       if(request!==mermaidSerial)return;
       const wrapper=diagrams[i];
       const input=wrapper.querySelector('[data-r06-mermaid-source]');
       const output=wrapper.querySelector('[data-r06-mermaid-output]');
       const fallback=wrapper.querySelector('[data-r06-mermaid-fallback]');
       if(!input||!output||!fallback)continue;
       const source=input.textContent||'';
       if(!source.startsWith('flowchart TD\n')||source.length>15000)continue;
       try{
         const generated=await mermaid.render('kmm-r06-selection-'+request+'-'+i,source);
         if(request!==mermaidSerial)return;
         const svg=generated?.svg;
         if(typeof svg!=='string'||svg.length>350000||!svg.includes('<svg'))continue;
         // Image transport confines the generated SVG to a non-executable image context,
         // and the existing CSP already permits img-src data:. No unsafe-inline style.
         const img=document.createElement('img');
         img.className='r06-mermaid-image';
         img.alt='選考フロー図。下に工程順のテキスト一覧があります。';
         img.decoding='async';
         img.addEventListener('load',()=>{
           if(request!==mermaidSerial)return;
           output.replaceChildren(img);output.hidden=false;fallback.hidden=true;
         },{once:true});
         img.addEventListener('error',()=>{
           if(request!==mermaidSerial)return;
           output.hidden=true;fallback.hidden=false;
         },{once:true});
         img.src='data:image/svg+xml;charset=utf-8,'+encodeURIComponent(svg);
       }catch(_error){output.hidden=true;fallback.hidden=false;}
     }
   }catch(_error){
     mermaidModulePromise=null;
     for(const node of diagrams){const x=node.querySelector('[data-r06-mermaid-fallback]');if(x)x.hidden=false;}
   }
 }
 void showMermaidFlows();
})();
