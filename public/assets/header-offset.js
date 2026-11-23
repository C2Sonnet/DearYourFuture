/* Sticky header stays in normal document flow; main is never obscured.
   Measure the actual expanded/nav-wrapped height for anchor scrolling and admin sidebar. */
(()=>{'use strict';
 const header=document.querySelector('.site-header');
 if(!header)return;
 const update=()=>{
   const height=Math.max(0,Math.ceil(header.getBoundingClientRect().height));
   document.documentElement.style.setProperty('--kmm-header-height',height+'px');
 };
 update();
 if('ResizeObserver' in window){const observer=new ResizeObserver(update);observer.observe(header);}
 window.addEventListener('resize',update,{passive:true});
 window.addEventListener('load',update,{once:true});
})();
