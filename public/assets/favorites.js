(()=>{'use strict';
  document.addEventListener('submit',async e=>{
    const form=e.target;if(!(form instanceof HTMLFormElement)||!form.matches('.favorite-form'))return;
    if(!window.fetch)return;
    e.preventDefault();const btn=form.querySelector('.favorite-toggle');if(!btn)return;
    btn.disabled=true;
    const data=new FormData(form);
    try{
      const response=await fetch(form.action,{method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}});
      const body=await response.json();if(!response.ok||body.ok!==true)throw new Error(body.error||'保存できませんでした');
      const active=body.favorite===true;form.querySelector('[name="action"]').value=active?'remove':'add';
      btn.setAttribute('aria-pressed',String(active));btn.textContent=active?'★ お気に入り':'☆ お気に入りに登録';
      const status=form.querySelector('[role="status"]')||document.createElement('span');status.setAttribute('role','status');status.className='sr-only';status.textContent=active?'お気に入りに登録しました':'お気に入りを解除しました';if(!status.parentNode)form.append(status);
    }catch(error){const status=form.querySelector('[role="alert"]')||document.createElement('span');status.setAttribute('role','alert');status.className='favorite-error';status.textContent=error instanceof Error?error.message:'通信を確認して再度操作してください';if(!status.parentNode)form.append(status);}
    finally{btn.disabled=false;}
  });
})();
