(function($){
  'use strict';

  // Debug mode
  var DEBUG = false;
  function log(msg, data){
    if(DEBUG) console.log('[MAD Incentives]', msg, data || '');
  }

  // Utilidad: debounce para no spamear AJAX en eventos seguidos
  function debounce(fn, wait){
    var t; 
    return function(){ 
      var ctx = this, args = arguments;
      clearTimeout(t); 
      t = setTimeout(function(){ 
        fn.apply(ctx, args); 
      }, wait || 120);
    };
  }

  // Encuentra el contenedor del mini-carrito
  function findCartContainer(){
    if (!window.MAD_Incentives || !MAD_Incentives.wrapSelector) {
      log('No hay configuración de MAD_Incentives');
      return null;
    }

    var selector = MAD_Incentives.wrapSelector;
    var wrap = document.querySelector(selector);
    
    if(!wrap){
      log('No se encontró el contenedor:', selector);
      // Intentar selectores alternativos comunes
      var alternatives = [
        '.widget_shopping_cart_content',
        '.cart_list',
        'div.widget_shopping_cart',
        '.woocommerce-mini-cart',
        '.mini-cart',
        '.cart-dropdown',
        '.nm-shop-cart-mid'
      ];
      
      for(var i = 0; i < alternatives.length; i++){
        wrap = document.querySelector(alternatives[i]);
        if(wrap){
          log('Contenedor encontrado con selector alternativo:', alternatives[i]);
          break;
        }
      }
    } else {
      log('Contenedor encontrado:', selector);
    }
    
    return wrap;
  }

  // Renderiza el incentivo en el contenedor configurado
  function renderIncentive(){
    log('Iniciando renderIncentive()');
    
    if (!window.MAD_Incentives || !MAD_Incentives.ajaxUrl) {
      log('MAD_Incentives no está definido correctamente');
      return;
    }
    
    $.post(MAD_Incentives.ajaxUrl, {
      action: 'madin_get_incentive',
      nonce: MAD_Incentives.nonce
    }).done(function(resp){
      log('Respuesta AJAX recibida:', resp);
      
      if (!resp || !resp.success) {
        log('Respuesta AJAX no exitosa');
        return;
      }
      
      var html = (resp.data && resp.data.html) ? resp.data.html : '';
      log('HTML recibido:', html ? 'Sí (' + html.length + ' caracteres)' : 'No');
      
      var wrap = findCartContainer();
      
      if (!wrap) {
        log('No se pudo encontrar el contenedor del carrito');
        return;
      }
      
      // Elimina incentivo previo
      var prev = document.getElementById('mad-minicart-incentive');
      if (prev && prev.parentNode) {
        prev.parentNode.removeChild(prev);
        log('Incentivo anterior eliminado');
      }
      
      if (!html) {
        log('No hay HTML para mostrar (probablemente todos los incentivos alcanzados)');
        return;
      }
      
      // Inserta el nuevo incentivo
      var temp = document.createElement('div');
      temp.innerHTML = html;
      var box = temp.firstElementChild;
      
      if (!box) {
        log('No se pudo crear el elemento del incentivo');
        return;
      }
      
      // Insertar al inicio del contenedor
      if (wrap.firstElementChild) {
        wrap.insertBefore(box, wrap.firstElementChild);
      } else {
        wrap.appendChild(box);
      }
      
      log('Incentivo insertado correctamente');
    }).fail(function(xhr, status, error){
      log('Error en AJAX:', {xhr: xhr, status: status, error: error});
    });
  }

  var renderIncentiveDebounced = debounce(renderIncentive, 150);

  // Inicializar cuando el DOM esté listo
  $(document).ready(function(){
    log('DOM Ready - Inicializando');
    log('Configuración:', window.MAD_Incentives);
    
    // Pequeño delay para asegurar que WooCommerce esté listo
    setTimeout(renderIncentive, 300);
  });

  // Reinyectar tras eventos típicos de WooCommerce/AJAX Fragments
  $(document.body).on(
    'added_to_cart removed_from_cart updated_cart_totals ' +
    'wc_fragments_refreshed wc_fragments_loaded ' +
    'updated_wc_div wc_cart_button_updated',
    function(e){
      log('Evento WooCommerce detectado:', e.type);
      renderIncentiveDebounced();
    }
  );

  // Evento específico cuando se abre el mini-carrito
  $(document.body).on('click', '.nm-menu-cart-btn, .cart-button, [data-toggle="cart"]', function(){
    log('Click en botón de carrito detectado');
    setTimeout(renderIncentive, 100);
  });

  // Observer para detectar cambios en el contenedor del carrito
  var started = false;
  
  function startObserver(){
    if (started) return;
    
    var wrap = findCartContainer();
    if (!wrap || !window.MutationObserver) {
      log('No se puede iniciar el observer');
      return;
    }
    
    started = true;
    log('Observer iniciado en:', wrap);
    
    var observer = new MutationObserver(debounce(function(mutations){
      log('Mutación detectada en el carrito');
      renderIncentive();
    }, 200));
    
    observer.observe(wrap, { 
      childList: true, 
      subtree: true 
    });
  }

  // Intentar iniciar el observer después de un pequeño delay
  $(document).ready(function(){
    setTimeout(startObserver, 500);
  });

  // Exponer función para debug manual
  window.MAD_IncentivesDebug = {
    render: renderIncentive,
    findContainer: findCartContainer,
    enableDebug: function(){ DEBUG = true; log('Debug activado'); },
    config: function(){ return window.MAD_Incentives; }
  };

})(jQuery);