(()=>{'use strict';const root=document.documentElement,button=document.querySelector('[data-theme-toggle]');const key='kmm-theme';const values=['light','dark','system'];let current='system';try{const stored=localStorage.getItem(key);if(values.includes(stored))current=stored;}catch(_e){}root.dataset.theme=current;if(button)button.addEventListener('click',()=>{current=values[(values.indexOf(current)+1)%values.length];root.dataset.theme=current;try{localStorage.setItem(key,current);}catch(_e){}button.setAttribute('aria-label','現在の表示テーマ: '+current+'。切り替える');});if(button)button.setAttribute('aria-label','現在の表示テーマ: '+current+'。切り替える');})();

/* The native <details> remains keyboard/touch operable without JavaScript. */
(()=>{'use strict';if(!window.matchMedia||!window.matchMedia('(hover:hover) and (pointer:fine)').matches)return;
for(const menu of document.querySelectorAll('details.user-menu')){
  menu.addEventListener('pointerenter',()=>{menu.open=true;});
  menu.addEventListener('pointerleave',()=>{if(!menu.contains(document.activeElement))menu.open=false;});
  menu.addEventListener('focusout',()=>{requestAnimationFrame(()=>{if(!menu.contains(document.activeElement)&&!menu.matches(':hover'))menu.open=false;});});
}})();
