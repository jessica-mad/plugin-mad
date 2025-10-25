(function($){
  // Rellena con cero a la izquierda
  function pad(n){ 
    return (n < 10 ? '0' : '') + n; 
  }
  
  function init(){
    var el = document.querySelector('.mad-countdown');
    if (!el) {
      console.warn('MAD Countdown: No se encontró el elemento .mad-countdown');
      return;
    }
    
    // Obtener datos desde los atributos data-*
    var cut = parseInt(el.getAttribute('data-cut'), 10);
    var now = parseInt(el.getAttribute('data-now'), 10);
    var tpl = el.getAttribute('data-template');
    
    // Validación de datos
    if(!cut || !now || !tpl){
      console.error('MAD Countdown: Faltan datos necesarios', {cut: cut, now: now, tpl: tpl});
      return;
    }
    
    console.log('MAD Countdown inicializado', {
      cutoff_timestamp: cut,
      now_timestamp: now,
      template: tpl,
      diferencia_segundos: cut - now
    });
    
    var tickMs = now * 1000;
    
    (function render(){
      var leftMs = cut * 1000 - tickMs;
      
      // Si ya pasó el tiempo, ocultar
      if (leftMs <= 0) { 
        el.style.display = 'none';
        console.log('MAD Countdown: Tiempo agotado, ocultando elemento');
        return; 
      }
      
      var totalSec = Math.floor(leftMs / 1000);
      var hh = Math.floor(totalSec / 3600);
      var mm = Math.floor((totalSec % 3600) / 60);
      
      // Reemplazar variables en el template
      var message = tpl
        .replace('{{hh}}', pad(hh))
        .replace('{{mm}}', pad(mm));
      
      el.textContent = message;
      
      tickMs += 1000;
      setTimeout(render, 1000);
    })();
  }
  
  // Inicializar cuando el DOM esté listo
  if(document.readyState === 'loading'){
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
  
})(jQuery);