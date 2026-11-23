(()=>{'use strict';
const form=document.querySelector('[data-avatar-editor]');if(!form)return;
const input=form.querySelector('input[type="file"]');
const canvas=form.querySelector('canvas'),ctx=canvas?.getContext('2d');
const controls=form.querySelector('.avatar-crop-ui'),zoom=form.querySelector('[data-avatar-zoom]'),format=form.querySelector('[data-avatar-format]');
const reset=form.querySelector('[data-avatar-reset]'),error=form.querySelector('.avatar-editor-error');
const status=form.querySelector('[data-avatar-status]');
const submit=form.querySelector('button[type="submit"]');
if(!input||!canvas||!ctx||!controls||!zoom||!format||!reset||!error||!submit)return;
let image=null,offsetX=0,offsetY=0,scale=1,drag=null,loading=false,preparing=false,readyToSubmit=false,generation=0;
const MAX_BYTES=2*1024*1024;
function announce(message,isError=false){error.textContent=isError?message:'';if(status)status.textContent=isError?'':message;}
function paint(){if(!image)return;const w=canvas.width,h=canvas.height;
  ctx.clearRect(0,0,w,h);ctx.fillStyle='#e3d8d5';ctx.fillRect(0,0,w,h);
  const factor=Math.max(w/image.width,h/image.height)*scale,iw=image.width*factor,ih=image.height*factor;
  const maxX=Math.max(0,(iw-w)/2),maxY=Math.max(0,(ih-h)/2);
  offsetX=Math.max(-maxX,Math.min(maxX,offsetX));offsetY=Math.max(-maxY,Math.min(maxY,offsetY));
  ctx.drawImage(image,(w-iw)/2+offsetX,(h-ih)/2+offsetY,iw,ih);
}
function resetCrop(){offsetX=offsetY=0;scale=1;zoom.value='1';paint();}
input.addEventListener('change',async()=>{
  const id=++generation;readyToSubmit=false;drag=null;loading=true;submit.disabled=true;
  if(image&&typeof image.close==='function')image.close();image=null;controls.hidden=true;announce('');
  const file=input.files?.[0];if(!file){loading=false;submit.disabled=false;return;}
  if(!['image/png','image/jpeg'].includes(file.type)||file.size>MAX_BYTES||file.size<1){
    announce('2MB以内のJPEG（JPG）またはPNG画像を選んでください。',true);input.value='';loading=false;submit.disabled=false;return;
  }
  try{
    const bitmap=await createImageBitmap(file);
    if(id!==generation){bitmap.close();return;}
    if(bitmap.width<1||bitmap.height<1||bitmap.width>4096||bitmap.height>4096){bitmap.close();throw new Error('画像の縦横は1〜4096pxにしてください。');}
    image=bitmap;resetCrop();controls.hidden=false;announce('切り抜き位置を調整できます。');
  }catch(e){if(id===generation){announce(e.message||'画像を読み込めません。',true);input.value='';}}
  finally{if(id===generation){loading=false;submit.disabled=false;}}
});
zoom.addEventListener('input',()=>{if(!image||preparing)return;const n=Number(zoom.value);scale=Number.isFinite(n)?Math.max(1,Math.min(3,n)):1;paint();});
reset.addEventListener('click',()=>{if(image&&!preparing){resetCrop();announce('位置と倍率を戻しました。');}});
canvas.addEventListener('pointerdown',e=>{if(!image||preparing)return;drag={id:e.pointerId,x:e.clientX,y:e.clientY};canvas.setPointerCapture(e.pointerId);});
canvas.addEventListener('pointermove',e=>{if(!drag||drag.id!==e.pointerId||!image)return;
  const r=canvas.getBoundingClientRect();if(!r.width||!r.height)return;
  offsetX+=(e.clientX-drag.x)*canvas.width/r.width;offsetY+=(e.clientY-drag.y)*canvas.height/r.height;
  drag.x=e.clientX;drag.y=e.clientY;paint();
});
for(const name of ['pointerup','pointercancel','lostpointercapture'])canvas.addEventListener(name,()=>{drag=null;});
canvas.addEventListener('keydown',e=>{
  if(!image||preparing)return;
  const step=e.shiftKey?20:5;
  if(e.key==='ArrowLeft')offsetX-=step;
  else if(e.key==='ArrowRight')offsetX+=step;
  else if(e.key==='ArrowUp')offsetY-=step;
  else if(e.key==='ArrowDown')offsetY+=step;
  else if(e.key==='Home')resetCrop();
  else return;
  e.preventDefault();paint();
});
// Native form validation can reject requestSubmit() without firing a submit event.
// Restore the guard so a second click never uploads unprocessed source bytes.
form.addEventListener('invalid',()=>{if(readyToSubmit){readyToSubmit=false;preparing=false;submit.disabled=false;}},true);
form.addEventListener('submit',async e=>{
  if(readyToSubmit){
    const current=input.files?.[0];
    const expected=format.value==='image/png'?'image/png':'image/jpeg';
    if(current?.type===expected||(!current&&form.dataset.avatarOptional==='true'))return;
    readyToSubmit=false; // Format changed: require re-encoding before native submission.
  }
  e.preventDefault();
  if(loading||preparing)return;
  if(!image){if(form.dataset.avatarOptional==='true'){readyToSubmit=true;form.requestSubmit(submit);return;}announce('画像を選択して正方形を確定してください。',true);return;}
  if(!form.checkValidity()){form.reportValidity();return;}
  const requestGeneration=generation;preparing=true;submit.disabled=true;input.disabled=true;announce('画像を圧縮しています。');
  try{
    paint();const out=document.createElement('canvas');out.width=out.height=512;
    const outCtx=out.getContext('2d');if(!outCtx)throw new Error('画像処理に対応していません。');
    outCtx.drawImage(canvas,0,0,512,512);
    // User preference: new compressed images must be JPEG or PNG, never WebP.
    // JPEG defaults to smaller files; PNG is selectable where lossless output is desired.
    const mime=format.value==='image/png'?'image/png':'image/jpeg';
    const extension=mime==='image/png'?'png':'jpg';
    const blob=await new Promise(resolve=>out.toBlob(resolve,mime,mime==='image/jpeg'?.85:undefined));
    if(!blob||blob.type!==mime||blob.size===0||blob.size>MAX_BYTES){
      throw new Error(mime==='image/png'?'PNGが2MBを超えたか変換に失敗しました。JPEGを選択して再試行してください。':'JPEG画像の圧縮に失敗しました。');
    }
    if(typeof DataTransfer!=='function')throw new Error('このブラウザは加工済みファイルの送信に対応していません。');
    const transfer=new DataTransfer();transfer.items.add(new File([blob],'avatar.'+extension,{type:mime}));
    if(requestGeneration!==generation)throw new Error('画像が変更されました。もう一度確定してください。');
    input.files=transfer.files;input.disabled=false;
    readyToSubmit=true;preparing=false;submit.disabled=false;announce('加工済み画像を送信します。');
    form.requestSubmit(submit);
    // If the browser blocks submission due to validation, the `invalid` handler resets.
  }catch(err){input.disabled=false;readyToSubmit=false;preparing=false;submit.disabled=false;announce(err.message||'画像を送信できません。',true);}
});
})();
