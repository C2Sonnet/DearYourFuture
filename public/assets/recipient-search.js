(()=>{'use strict';
// One controller per field: compose and block forms must not share stale results.
for(const field of document.querySelectorAll('[data-recipient-search]')){
  const list=document.getElementById(field.getAttribute('list')||'');if(!list)continue;
  const status=document.getElementById(field.dataset.searchStatus||'');
  const endpoint=field.dataset.suggestUrl||'/messages/recipients';
  let timer=null,serial=0,controller=null;
  function announce(message){if(status)status.textContent=message;}
  function clear(){list.replaceChildren();if(controller){controller.abort();controller=null;}}
  field.addEventListener('input',()=>{
    clearTimeout(timer);const query=field.value.trim();const current=++serial;clear();
    if(query.length<2){announce('2文字以上で候補を検索できます。');return;}
    if(query.length>60){announce('検索語は60文字以内にしてください。');return;}
    announce('候補を検索中…');
    timer=setTimeout(async()=>{
      const active=new AbortController();controller=active;
      try{
        const response=await fetch(endpoint+'?q='+encodeURIComponent(query),{
          credentials:'same-origin',headers:{Accept:'application/json'},signal:active.signal,cache:'no-store'
        });
        if(current!==serial)return;
        if(!response.ok){announce(response.status===429?'検索回数が多すぎます。少ししてからお試しください。':'検索できませんでした。ユーザーIDを直接入力することもできます。');return;}
        const data=await response.json();if(current!==serial||!Array.isArray(data.users))return;
        for(const user of data.users.slice(0,12)){
          if(typeof user.handle!=='string'||!/^[A-Za-z0-9_-]{3,40}$/.test(user.handle))continue;
          const option=document.createElement('option');option.value=user.handle;
          const display=typeof user.display_name==='string'?user.display_name.slice(0,100):'';
          option.label=display+' (@'+user.handle+')';list.append(option);
        }
        announce(list.options.length?list.options.length+'件の候補があります。候補からユーザーIDを選択してください。':'候補がありません。');
      }catch(error){if(current===serial&&error?.name!=='AbortError')announce('通信できませんでした。ユーザーIDを直接入力できます。');}
      finally{if(controller===active)controller=null;}
    },250);
  });
  field.addEventListener('blur',()=>{clearTimeout(timer);});
}
})();
