(()=>{'use strict';
  const drawer=document.getElementById('admin-drawer');if(!drawer)return;
  const button=drawer.querySelector('.admin-drawer-handle');if(!button)return;
  const collapse=(closed)=>{
    drawer.classList.toggle('is-collapsed',closed);
    button.setAttribute('aria-expanded',String(!closed));
    button.setAttribute('aria-label',closed?'管理目次を展開する':'管理目次を収納する');
    button.textContent=closed?'›':'‹';
  };
  collapse(true);
  button.addEventListener('click',event=>{event.stopPropagation();collapse(!drawer.classList.contains('is-collapsed'));});
  drawer.addEventListener('click',event=>{
    if(event.target instanceof Element&&event.target.closest('a[href]'))collapse(true);
  });
  document.addEventListener('pointerdown',event=>{
    if(!drawer.contains(event.target))collapse(true);
  });
  document.addEventListener('keydown',event=>{if(event.key==='Escape')collapse(true);});
})();
