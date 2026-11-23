(()=>{'use strict';document.querySelectorAll('[data-login-notice-close]').forEach(button=>{button.addEventListener('click',()=>{button.closest('.login-notice')?.remove();});});})();
