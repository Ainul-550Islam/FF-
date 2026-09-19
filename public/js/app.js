/* Fallback public JS - minimal offline detection */
(function(){const b=document.getElementById('offline-banner');function u(){if(!b)return;if(!navigator.onLine){b.classList.add('show')}else{b.classList.remove('show')}}window.addEventListener('online',u);window.addEventListener('offline',u);u();console.log('FF Arena fallback JS - offline check active');})();
