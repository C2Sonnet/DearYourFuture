(()=>{'use strict';const root=document.querySelector('.desktop-navigation');if(!root)return;
const groups=[...root.querySelectorAll('details.nav-group')];
for(const item of groups){item.addEventListener('toggle',()=>{if(item.open)for(const other of groups)if(other!==item)other.open=false;});}
document.addEventListener('pointerdown',event=>{if(!root.contains(event.target))for(const item of groups)item.open=false;});
document.addEventListener('keydown',event=>{if(event.key==='Escape')for(const item of groups)item.open=false;});
})();