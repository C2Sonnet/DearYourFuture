(()=>{'use strict';
 const root=document.getElementById('r07-google-map');if(!root)return;
 const state=document.getElementById('r07-map-status');
 const key=root.dataset.apiKey||'';const mapId=root.dataset.mapId||'';
 if(!/^AIza[0-9A-Za-z_-]{25,90}$/.test(key)){if(state)state.textContent='地図APIキーを確認してください。';return;}
 let rows=[];
 try{rows=JSON.parse(document.getElementById('r07-google-locations')?.textContent||'[]');}
 catch{if(state)state.textContent='拠点一覧の読込みに失敗しました。下の住所一覧をご確認ください。';return;}
 if(!Array.isArray(rows)){if(state)state.textContent='拠点データの形式が不正です。';return;}
 function safeUrl(url){return typeof url==='string'&&/^\/companies\/(?:research\/CO-[0-9]{4}|[a-f0-9]{32})$/.test(url);}
 // Preserve successful address lookups for page navigation within this tab.
 // Address is part of the cache key: changed addresses always get fresh coordinates.
 function cached(address){try{
    const raw=sessionStorage.getItem('kmm-r07-geocode-v1:'+address);if(!raw)return null;
    const p=JSON.parse(raw);return Number.isFinite(p.lat)&&Number.isFinite(p.lng)&&Math.abs(p.lat)<=90&&Math.abs(p.lng)<=180?p:null;
 }catch{return null;}}
 function cache(address,p){try{sessionStorage.setItem('kmm-r07-geocode-v1:'+address,JSON.stringify(p));}catch{}}
 window.kmmR07MapLoaded=async function(){
  try{
    const options={center:{lat:35.68,lng:139.72},zoom:10,mapTypeControl:false};
    if(/^[0-9a-fA-F]{16}$/.test(mapId))options.mapId=mapId;
    const map=new google.maps.Map(root,options);
    const geo=new google.maps.Geocoder(),popup=new google.maps.InfoWindow(),bounds=new google.maps.LatLngBounds();
    let success=0,failed=0,lookup=0;
    for(const [i,item] of rows.entries()){
      if(!item||typeof item!=='object'||typeof item.address!=='string'||!item.address.trim()||!safeUrl(item.url)){failed++;continue;}
      try{
        let point=cached(item.address);
        if(point===null){
          const response=await geo.geocode({address:item.address,region:'jp'});
          const location=response.results[0]?.geometry?.location;
          if(!location){failed++;continue;}
          point={lat:location.lat(),lng:location.lng()};cache(item.address,point);lookup++;
        }
        let marker;
        try{
          if(!options.mapId||!google.maps.marker?.AdvancedMarkerElement)throw new Error('Advanced marker unavailable');
          marker=new google.maps.marker.AdvancedMarkerElement({map,position:point,title:String(item.name||'企業')});
        }catch{
          // Documented fallback for unsupported advanced-marker configurations.
          marker=new google.maps.Marker({map,position:point,title:String(item.name||'企業')});
        }
        marker.addListener('click',()=>{
          const wrap=document.createElement('div');const title=document.createElement('strong');title.textContent=String(item.name||'企業');
          const desc=document.createElement('p');desc.textContent=item.address;
          const link=document.createElement('a');link.textContent='企業詳細を見る';link.href=item.url;link.className='btn primary';
          wrap.append(title,desc,link);popup.setContent(wrap);popup.open({map,anchor:marker});
        });
        bounds.extend(point);success++;
      }catch{failed++;}
      if(state)state.textContent=`拠点処理中 ${i+1}/${rows.length}件（ピン${success}件）`;
      // Throttle only fresh geocoding requests, not cache hits.
      if(lookup>0){lookup=0;await new Promise(resolve=>setTimeout(resolve,900));}
    }
    if(success)map.fitBounds(bounds,52);
    if(state)state.textContent=`ピン ${success}件、座標化できなかった拠点 ${failed}件。住所一覧は下に表示しています。`;
  }catch{if(state)state.textContent='地図の初期化に失敗しました。Maps APIキー・課金・CSP設定をご確認ください。住所一覧は下に残しています。';}
 };
 const script=document.createElement('script');
 script.src='https://maps.googleapis.com/maps/api/js?key='+encodeURIComponent(key)+'&callback=kmmR07MapLoaded&loading=async&v=weekly&libraries=marker';
 script.async=true;script.onerror=()=>{if(state)state.textContent='Google Maps APIの読込みに失敗しました。住所一覧をご確認ください。';};
 document.head.append(script);
})();
