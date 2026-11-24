(()=>{'use strict';
  // Keep name="action" for the existing POST API. HTMLFormElement.action can
  // be shadowed by a descendant form control with that name.
  document.addEventListener('submit',async event=>{
    const form=event.target;
    if(!(form instanceof HTMLFormElement)||!form.matches('.favorite-form'))return;
    if(typeof window.fetch!=='function')return;
    event.preventDefault();
    const button=form.querySelector('.favorite-toggle');
    if(!button)return;
    const endpoint=form.getAttribute('action');
    let url;
    try{
      url=new URL(endpoint||'',window.location.href);
      if(!endpoint||url.origin!==window.location.origin||url.pathname!=='/my/favorite'||url.search||url.hash)
        throw new Error('お気に入りの送信先が正しくありません');
    }catch(_error){
      const message=form.querySelector('[data-favorite-error]')||document.createElement('span');
      message.dataset.favoriteError='';message.setAttribute('role','alert');
      message.className='favorite-error';message.textContent='送信先を確認できません。ページを再読み込みしてください。';
      if(!message.parentNode)form.append(message);
      return;
    }
    button.disabled=true;
    const data=new FormData(form);
    try{
      const response=await window.fetch(url.href,{
        method:'POST',body:data,credentials:'same-origin',
        headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}
      });
      let result;
      try{result=await response.json();}catch(_error){throw new Error('サーバーからの応答を確認できませんでした（HTTP '+response.status+'）');}
      if(!response.ok||result.ok!==true)throw new Error(result.error||'保存できませんでした（HTTP '+response.status+'）');
      const active=result.favorite===true;
      const actionInput=form.querySelector('input[name="action"]');
      if(actionInput)actionInput.value=active?'remove':'add';
      button.setAttribute('aria-pressed',String(active));
      button.textContent=active?'★ お気に入り':'☆ お気に入りに登録';
      const existingError=form.querySelector('[data-favorite-error]');
      if(existingError)existingError.remove();
      const status=form.querySelector('[data-favorite-status]')||document.createElement('span');
      status.dataset.favoriteStatus='';status.setAttribute('role','status');
      status.className='sr-only';
      status.textContent=active?'お気に入りに登録しました':'お気に入りを解除しました';
      if(!status.parentNode)form.append(status);
    }catch(error){
      const status=form.querySelector('[data-favorite-error]')||document.createElement('span');
      status.dataset.favoriteError='';status.setAttribute('role','alert');
      status.className='favorite-error';
      status.textContent=error instanceof Error?error.message:'通信を確認して再度操作してください';
      if(!status.parentNode)form.append(status);
    }finally{button.disabled=false;}
  });
})();
