(()=>{'use strict';
const ns='http://www.w3.org/2000/svg';const shades=['#B56A7E','#75A9B5','#DBAC75','#8FAF8C','#A18AC0','#C98D77','#7E9EBC','#B8B8BA'];
function svg(tag,attrs={},txt){const e=document.createElementNS(ns,tag);for(const [k,v] of Object.entries(attrs))e.setAttribute(k,String(v));if(txt!==undefined)e.textContent=txt;return e;}
function from(node,key){try{const data=JSON.parse(node.dataset[key]||'[]');return Array.isArray(data)?data:[];}catch{return [];}}
for(const root of document.querySelectorAll('[data-admin-pie]')){
 const data=from(root,'adminPie').filter(x=>Number(x.value)>0);const total=data.reduce((a,b)=>a+Number(b.value),0);
 if(!total){root.textContent='対象データなし';continue;}
 const view=svg('svg',{viewBox:'0 0 360 270',role:'presentation'}),cx=115,cy=125,r=95;let theta=-Math.PI/2;
 const pad=4*Math.PI/180;
 function point(angle){return [cx+r*Math.cos(angle),cy+r*Math.sin(angle)];}
 data.forEach((slice,i)=>{const span=2*Math.PI*Number(slice.value)/total;const gap=Math.min(pad,span*.24);const a=theta+gap/2,b=theta+span-gap/2;
  if(b>a){const x=point(a),y=point(b);const d=`M ${cx} ${cy} L ${x[0]} ${x[1]} A ${r} ${r} 0 ${b-a>Math.PI?1:0} 1 ${y[0]} ${y[1]} Z`;
   const path=svg('path',{d,fill:shades[i%shades.length]});path.append(svg('title',{},`${slice.name}: ${slice.value}件 (${(100*slice.value/total).toFixed(1)}%)`));view.append(path);}
  theta+=span;
 });
 data.slice(0,7).forEach((slice,i)=>{const y=25+i*31;view.append(svg('circle',{cx:229,cy:y,r:6,fill:shades[i%shades.length]}));const text=String(slice.name).slice(0,13)+' '+Math.round(Number(slice.value)/total*100)+'%';view.append(svg('text',{x:242,y:y+4,'font-size':10,fill:'currentColor'},text));});
 root.replaceChildren(view);
}
for(const root of document.querySelectorAll('[data-admin-line]')){
 const data=from(root,'adminLine');if(!data.length){root.textContent='履歴なし';continue;}
 const names=[['company_new','企業・新規','#B56A7E'],['company_edited','企業・修正','#75A9B5'],['company_deleted','企業・削除','#8FAF8C'],['job_new','求人・新規','#B05C6D'],['job_edited','求人・修正','#457D9A'],['job_deleted','求人・削除','#659264']],width=820,height=300,top=46,left=48,right=20,bottom=35;
 const chart=svg('svg',{viewBox:`0 0 ${width} ${height}`,role:'img','aria-label':'日別企業・求人の新規・修正・削除数'});
 const max=Math.max(1,...data.flatMap(row=>names.map(n=>Number(row[n[0]])||0)));const base=height-bottom;const sx=i=>left+(width-left-right)*i/Math.max(data.length-1,1);const sy=v=>base-(base-top)*v/max;
 for(let t=0;t<=4;t++){const y=sy(max*t/4);chart.append(svg('line',{x1:left,x2:width-right,y1:y,y2:y,stroke:'currentColor',opacity:.12}));chart.append(svg('text',{x:5,y:y+4,'font-size':10,fill:'currentColor'},String(Math.round(max*t/4))));}
 names.forEach(([key,label,color],i)=>{const path=data.map((row,j)=>(j?'L':'M')+sx(j).toFixed(1)+' '+sy(Number(row[key])||0).toFixed(1)).join(' ');
  chart.append(svg('path',{d:path,stroke:color,'stroke-width':3,fill:'none'}));chart.append(svg('text',{x:left+(i%3)*155,y:10+Math.floor(i/3)*22,fill:color,'font-size':13},'● '+label));});
 [0,7,14,21,29].forEach(i=>{const row=data[Math.min(i,data.length-1)];if(row)chart.append(svg('text',{x:sx(i),y:height-9,'text-anchor':'middle','font-size':11,fill:'currentColor'},String(row.date).slice(5)));});
 root.replaceChildren(chart);
}
})();
