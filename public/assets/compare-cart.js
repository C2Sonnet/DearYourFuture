(()=>{'use strict';
  const uid=document.documentElement.dataset.userId;
  if(!uid)return;
  const key='kmm-compare-cart-v1-user-'+uid,types=['jobs','companies'];
  const names={jobs:'求人',companies:'企業'};
  function read(){try{const val=JSON.parse(sessionStorage.getItem(key)||'{}');const out={jobs:[],companies:[]};
    for(const type of types)if(Array.isArray(val[type]))out[type]=val[type].filter(x=>x&&typeof x.id==='string'&&typeof x.name==='string'&&new RegExp(type==='jobs'?'^JOB-[0-9]{4}$':'^CO-[0-9]{4}$').test(x.id)).slice(0,10);
    return out;}catch{return {jobs:[],companies:[]};}}
  let cart=read();
  const buttons=[...document.querySelectorAll('[data-compare-add]')];
  function href(type){return (type==='jobs'?'/compare/jobs':'/compare/companies')+'?ids='+encodeURIComponent(cart[type].map(x=>x.id).join(','));}
  function render(){for(const btn of buttons){const type=btn.dataset.compareType,id=btn.dataset.compareId;
      const exists=cart[type]?.some(x=>x.id===id)??false;btn.textContent=exists?'比較中':'比較';btn.classList.toggle('is-selected',exists);btn.setAttribute('aria-pressed',String(exists));}
    for(const type of types){const count=document.querySelector('[data-compare-count="'+type+'"]');if(count)count.textContent=String(cart[type].length);
      const nav=document.querySelector('[data-compare-link="'+type+'"]');if(nav)nav.href=href(type);}
    const float=document.querySelector('[data-compare-floating]');if(float){const any=types.some(t=>cart[t].length);float.hidden=!any;
      for(const type of types){const holder=float.querySelector('[data-compare-list="'+type+'"]');if(!holder)continue;holder.replaceChildren();
        for(const item of cart[type]){const li=document.createElement('li');const label=document.createElement('span');label.textContent=item.name;const remove=document.createElement('button');remove.type='button';remove.textContent='×';remove.title=names[type]+'を比較から外す';remove.setAttribute('aria-label',item.name+'を比較から外す');remove.onclick=()=>{cart[type]=cart[type].filter(x=>x.id!==item.id);save();};li.append(label,remove);holder.append(li);}
      }
      for(const type of types){const link=float.querySelector('[data-compare-float-link="'+type+'"]');if(link)link.href=href(type);}
    }
  }
  function save(){try{sessionStorage.setItem(key,JSON.stringify(cart));}catch{}render();}
  for(const btn of buttons)btn.addEventListener('click',()=>{const type=btn.dataset.compareType,id=btn.dataset.compareId,name=btn.dataset.compareName||id;
    if(!types.includes(type)||!id||!name)return;
    if(cart[type].some(x=>x.id===id)){cart[type]=cart[type].filter(x=>x.id!==id);}else if(cart[type].length<10){cart[type].push({id,name:name.slice(0,160)});}else{alert(names[type]+'の比較は最大10件です。');return;}save();
  });
  for(const btn of document.querySelectorAll('[data-compare-clear]'))btn.addEventListener('click',()=>{
    const type=btn.dataset.compareClear;if(!types.includes(type))return;
    cart[type]=[];save();
    if(location.pathname==='/compare/'+type || (type==='companies'&&location.pathname==='/companies/compare')){
      location.assign(type==='companies'?'/compare/companies':'/compare/jobs');
    }
  });
  const toggle=document.querySelector('[data-compare-toggle]');
  const floating=document.querySelector('[data-compare-floating]');
  if(toggle&&floating)toggle.addEventListener('click',()=>{
    const closed=floating.classList.toggle('is-collapsed');
    toggle.setAttribute('aria-expanded',String(!closed));
    toggle.setAttribute('aria-label',closed?'比較カートを広げる':'比較カートを収納する');
    toggle.textContent=closed?'‹':'›';
  });
  render();
})();
