(()=>{'use strict';
 function draw(){
   const nodes=[...document.querySelectorAll('[data-r07-chart]')];
   if(typeof echarts==='undefined'){
     for(const node of nodes)node.textContent='グラフを読み込めませんでした。ブラウザの接続状態をご確認ください。';
     return;
   }
   for(const node of nodes){
     const kind=node.dataset.r07Chart;
     let data=[];try{data=JSON.parse(document.getElementById('r07-chart-'+kind)?.textContent||'[]');}catch{}
     if(!Array.isArray(data)||!data.length){node.textContent='集計対象のデータはありません。';continue;}
     try{
       const chart=echarts.init(node,null,{renderer:'svg'});
       chart.setOption({tooltip:{trigger:'item'},legend:{type:'scroll',bottom:0},aria:{enabled:true},
         series:[{type:'pie',radius:['32%','65%'],padAngle:4,itemStyle:{borderRadius:5},
           label:{formatter:'{b}: {d}%'},data}]});
       window.addEventListener('resize',()=>chart.resize(),{passive:true});
     }catch{node.textContent='グラフを表示できませんでした。ページの再読み込みをお試しください。';}
   }
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',draw);else draw();
})();
