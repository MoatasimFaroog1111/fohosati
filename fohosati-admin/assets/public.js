document.addEventListener('DOMContentLoaded',function(){
  document.querySelectorAll('.foh-search-box input[type="search"]').forEach(function(input){
    input.addEventListener('keydown',function(e){ if(e.key==='Escape'){ input.value=''; }});
  });
});
