/* Account-scoped, UIkit-compatible modal; no inline JavaScript or external CDN. */
(()=>{'use strict';
 const modal=document.getElementById('kimi-intro-modal');
 if(!modal)return;
 const dialog=modal.querySelector('.uk-modal-dialog');
 const buttons=[...document.querySelectorAll('[data-kimi-open]')];
 const closeButton=modal.querySelector('[data-kimi-close]');
 let previousFocus=null, acked=modal.dataset.kimiAuto!=='1';
 let pending=false;
 function open(event){
   if(!modal.hidden)return;
   previousFocus=event?.currentTarget instanceof HTMLElement ? event.currentTarget : document.activeElement;
   modal.hidden=false;modal.setAttribute('aria-hidden','false');
   document.documentElement.classList.add('kimi-modal-open');
   dialog?.focus();
 }
 function acknowledge(){
   if(acked||pending)return;
   pending=true;
   const csrf=modal.dataset.kimiCsrf||'';
   const body=new URLSearchParams({csrf});
   fetch('/account/kimi/ack',{method:'POST',credentials:'same-origin',
     headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body})
   .then(response=>{if(!response.ok)throw new Error('not saved');return response.json();})
   .then(data=>{if(data.ok)acked=true;})
   .catch(()=>{/* Keep first-visit state until acknowledgment can be saved. */})
   .finally(()=>{pending=false;});
 }
 function close(){
   if(modal.hidden)return;
   modal.hidden=true;modal.setAttribute('aria-hidden','true');
   document.documentElement.classList.remove('kimi-modal-open');
   acknowledge();
   const target=previousFocus instanceof HTMLElement && previousFocus.isConnected ? previousFocus : buttons[0];
   target?.focus({preventScroll:true});
 }
 for(const button of buttons)button.addEventListener('click',open);
 closeButton?.addEventListener('click',close);
 modal.addEventListener('click',event=>{if(event.target===modal)close();});
 modal.addEventListener('keydown',event=>{
   if(event.key==='Escape'){event.preventDefault();close();return;}
   if(event.key!=='Tab')return;
   const focusable=[...modal.querySelectorAll('button:not([disabled]),input:not([disabled]),a[href],select:not([disabled]),textarea:not([disabled])')].filter(x=>x.getClientRects().length);
   if(!focusable.length){event.preventDefault();dialog?.focus();return;}
   const first=focusable[0],last=focusable[focusable.length-1];
   if(document.activeElement===dialog){event.preventDefault();(event.shiftKey?last:first).focus();return;}
   if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus();}
   else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus();}
 });
 if(modal.dataset.kimiAuto==='1')open();
})();
