(()=>{
  'use strict';
  const root=document.documentElement,modes=['dark','light'];
  const names={light:'ライト',dark:'ダーク'};
  const toggle=document.querySelector('#theme-toggle'),notice=document.querySelector('#notice');
  function announce(message){if(notice)notice.textContent=message;}
  function current(){return modes.includes(root.dataset.theme)?root.dataset.theme:'dark';}
  function label(){
    if(!toggle)return;
    const t=current();
    toggle.textContent={light:'☀',dark:'☾'}[t];
    toggle.setAttribute('aria-label',`テーマ: ${names[t]}（現在: ${names[t]}、次: ${names[modes[(modes.indexOf(t)+1)%modes.length]]}）`);
    toggle.title=`テーマ: ${names[t]}（切り替え）`;
  }
  if(toggle){
    label();
    toggle.addEventListener('click',()=>{
      root.dataset.theme=modes[(modes.indexOf(current())+1)%modes.length];
      try{localStorage.setItem('code-publication-theme',root.dataset.theme);}catch{}
      label();announce(`${names[current()]}テーマに変更しました`);
    });
    window.addEventListener('storage',e=>{if(e.key==='code-publication-theme'||e.key===null){root.dataset.theme=modes.includes(e.newValue)?e.newValue:'dark';label();}});
  }
  const top=document.querySelector('#back-to-top');
  if(top)top.addEventListener('click',e=>{
    const target=document.getElementById('top');if(!target)return;
    e.preventDefault();target.focus({preventScroll:true});window.scrollTo({top:0,behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'});
  });
  for(const el of document.querySelectorAll('[data-filter]'))el.addEventListener('input',()=>{
    const target=document.getElementById(el.dataset.filter);if(!target)return;
    const query=el.value.trim().toLowerCase();let count=0;
    for(const x of target.children){x.hidden=!x.textContent.toLowerCase().includes(query);if(!x.hidden)count++;}
    announce(`検索結果 ${count}件`);
  });
  for(const el of document.querySelectorAll('[data-filter-lines]'))el.addEventListener('input',()=>{
    const target=document.getElementById(el.dataset.filterLines);if(!target)return;
    for(const tr of target.querySelectorAll('tr'))tr.hidden=!tr.textContent.toLowerCase().includes(el.value.toLowerCase());
  });
  for(const b of document.querySelectorAll('[data-copy]'))b.addEventListener('click',async()=>{
    try{await navigator.clipboard.writeText([...document.querySelectorAll('#'+b.dataset.copy+' .code-text')].map(x=>x.textContent).join('\n'));announce('コピーしました');b.textContent='コピー済み';}
    catch{announce('コピーできませんでした');}
  });
  for(const toc of document.querySelectorAll('.toc a'))toc.addEventListener('click',()=>toc.closest('details').open=false);
  const disclosures=document.querySelectorAll('.commit-pop,.toc,.profile,.dropdown,.menu');
  // Native details/summary click and keyboard behavior; hovering is not required to open.
  for(const pop of disclosures){
    pop.addEventListener('keydown',e=>{if(e.key==='Escape'&&pop.open){e.preventDefault();e.stopPropagation();pop.open=false;pop.querySelector('summary')?.focus();}});
  }
  document.addEventListener('click',e=>{for(const pop of disclosures)if(pop.open&&!pop.contains(e.target))pop.open=false;});
  // Fixed viewport coordinates prevent right-aligned branch menus escaping the left edge.
  function place(pop){
    if(!pop.open||!pop.getBoundingClientRect)return;
    const menu=pop.querySelector('.popover'), anchor=pop.querySelector('summary');
    if(!menu?.getBoundingClientRect||!anchor?.getBoundingClientRect)return;
    const a=anchor.getBoundingClientRect(), pad=12, gap=6;
    if(a.bottom<pad||a.top>window.innerHeight-pad){pop.open=false;return;}
    const w=Math.max(0,Math.min(menu.getBoundingClientRect().width,window.innerWidth-2*pad));
    const below=window.innerHeight-a.bottom-gap-pad, above=a.top-gap-pad;
    const useAbove=below<120&&above>below;
    const available=Math.max(0,useAbove?above:below);
    menu.dataset.placed='true';
    menu.style.width=w+'px';menu.style.left=Math.max(pad,Math.min(a.left,window.innerWidth-pad-w))+'px';
    menu.style.maxHeight=Math.min(560,available)+'px';
    menu.style.top=(useAbove?Math.max(pad,a.top-gap-menu.getBoundingClientRect().height):Math.max(pad,a.bottom+gap))+'px';
  }
  for(const pop of disclosures){pop.addEventListener('toggle',()=>{if(pop.open)place(pop);});if(pop.open)place(pop);}
  window.addEventListener('resize',()=>{for(const pop of disclosures)if(pop.open)place(pop);});
  window.addEventListener('scroll',()=>{for(const pop of disclosures)if(pop.open)place(pop);},{passive:true});
})();
