/* Minimal UIkit uk-modal-compatible interaction, no external assets. */
(()=>{'use strict';
 const modal=document.getElementById('recommend-conditions-modal');
 const opener=document.querySelector('[data-recommend-open]');
 if(!modal||!opener)return;
 const dialog=modal.querySelector('.uk-modal-dialog');let lastFocus=null;
 function open(){lastFocus=document.activeElement;modal.hidden=false;modal.setAttribute('aria-hidden','false');document.documentElement.classList.add('recommend-modal-open');dialog?.focus();}
 function close(){modal.hidden=true;modal.setAttribute('aria-hidden','true');document.documentElement.classList.remove('recommend-modal-open');(lastFocus instanceof HTMLElement?lastFocus:opener).focus();}
 opener.addEventListener('click',open);
 modal.querySelectorAll('[data-recommend-close]').forEach(el=>el.addEventListener('click',close));
 modal.addEventListener('click',event=>{if(event.target===modal)close();});
 modal.addEventListener('keydown',event=>{
   if(event.key==='Escape'){event.preventDefault();close();return;}
   if(event.key!=='Tab')return;
   const focusable=[...modal.querySelectorAll('button:not([disabled]),input:not([disabled]),a[href],select:not([disabled])')].filter(el=>el.offsetParent!==null);
   if(!focusable.length){event.preventDefault();return;}
   const first=focusable[0],last=focusable[focusable.length-1];
   if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
   else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
 });
})();
