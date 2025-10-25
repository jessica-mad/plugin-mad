// assets/admin.js
(function($){
  $(document).ready(function(){
    // Ejemplo: alerta al cambiar un checkbox de autorender
    $('input[name*="[autorender]"]').on('change', function(){
      if( ! $(this).is(':checked') ) {
        alert('Recuerda que si desactivas el autorender, deberás usar el shortcode [product_note_field]');
      }
    });
  });
})(jQuery);
