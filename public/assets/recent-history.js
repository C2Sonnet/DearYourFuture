(()=>{'use strict';
  for(const button of document.querySelectorAll('[data-history-scroll]')){
    button.addEventListener('click',()=>{
      const target=document.getElementById(button.dataset.historyTarget||'');if(!target)return;
      const direction=button.dataset.historyScroll==='-1'?-1:1;
      target.scrollBy({left:direction*Math.max(250,target.clientWidth*.8),behavior:window.matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth'});
    });
  }
})();
