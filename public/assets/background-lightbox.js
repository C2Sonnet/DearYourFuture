/* UIkit Lightbox first; local accessible fallback if the UIkit CDN is unavailable. */
(()=>{'use strict';
  const sets=[...document.querySelectorAll('.site-resource-gallery[uk-lightbox]')];
  if(!sets.length||window.UIkit)return;
  const dialog=document.createElement('dialog');
  dialog.className='resource-fallback-dialog';
  dialog.setAttribute('aria-label','サイト背景画像を拡大表示');
  dialog.innerHTML='<button type="button" class="resource-fallback-close" aria-label="閉じる">×</button><figure><img alt=""><figcaption></figcaption></figure>';
  document.body.appendChild(dialog);
  const img=dialog.querySelector('img');
  const cap=dialog.querySelector('figcaption');
  let links=[];let at=0;
  const open=(group,index)=>{
    links=group;at=index;
    img.src=links[at].href;
    img.alt=links[at].querySelector('img')?.alt||'サイト背景画像';
    cap.textContent=links[at].dataset.caption||'';
    if(!dialog.open)dialog.showModal();
  };
  for(const set of sets){
    const group=[...set.querySelectorAll('a.site-resource-image-link[href]')];
    group.forEach((anchor,index)=>anchor.addEventListener('click',e=>{
      e.preventDefault();open(group,index);
    }));
  }
  dialog.querySelector('button').addEventListener('click',()=>dialog.close());
  dialog.addEventListener('click',e=>{if(e.target===dialog)dialog.close();});
  dialog.addEventListener('keydown',e=>{
    if(!links.length||!dialog.open)return;
    if(e.key==='ArrowRight'||e.key==='ArrowLeft'){
      e.preventDefault();open(links,(at+(e.key==='ArrowRight'?1:-1)+links.length)%links.length);
    }
  });
})();
