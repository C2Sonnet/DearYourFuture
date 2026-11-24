// UIkit uk-countdown markup-compatible local timer; no CDN or inline scripts.
(()=>{'use strict';
  const root=document.querySelector('[uk-countdown][data-countdown-ends]');if(!root)return;
  const end=Date.parse(root.dataset.countdownEnds||'');if(!Number.isFinite(end))return;
  const fields=['days','hours','minutes','seconds'].map(k=>root.querySelector('.uk-countdown-'+k));
  if(fields.some(x=>!x))return;
  let timer=null;
  function tick(){
    let seconds=Math.max(0,Math.ceil((end-Date.now())/1000));
    const numbers=[Math.floor(seconds/86400),Math.floor(seconds%86400/3600),Math.floor(seconds%3600/60),seconds%60];
    numbers.forEach((n,i)=>{fields[i].textContent=String(n).padStart(2,'0');});
    if(seconds===0){if(timer!==null)clearInterval(timer);window.location.replace('/');}
  }
  tick();timer=setInterval(tick,1000);
})();
