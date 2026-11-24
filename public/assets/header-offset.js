/* CSP-safe sticky-header offset: use only CSS classes declared in site.css.
   Never set element.style or write a style attribute under style-src 'self'. */
(()=>{'use strict';
 const header=document.querySelector('.site-header');
 if(!header)return;
 const root=document.documentElement;
 let previous='';
 const update=()=>{
   const pixels=Math.max(32,Math.min(512,Math.ceil(header.getBoundingClientRect().height)));
   const rounded=Math.ceil(pixels/16)*16;
   const next='kmm-header-h-'+rounded;
   if(next!==previous){if(previous)root.classList.remove(previous);root.classList.add(next);previous=next;}
 };
 update();
 if('ResizeObserver' in window){const observer=new ResizeObserver(update);observer.observe(header);}
 window.addEventListener('resize',update,{passive:true});
 window.addEventListener('load',update,{once:true});
})();
