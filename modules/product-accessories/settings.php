<?php
if ( ! defined('ABSPATH') ) exit;

/**
 * Metabox UI para el CPT de accesorios
 * Campos:
 * - Producto Woo accesorio (buscador ajax)
 * - Mostrar en PDP / Carrito
 * - Cantidades: min / max / default
 * - Título/label opcional
 * - Alcance: categorías (árbol), etiquetas, productos concretos
 */

$nonce = wp_create_nonce('mad_acc_save');

$product_id  = (int) get_post_meta($post->ID, '_mad_acc_product_id', true);
$show_pdp    = (int) get_post_meta($post->ID, '_mad_acc_show_on_pdp', true);
$show_cart   = (int) get_post_meta($post->ID, '_mad_acc_show_in_cart', true);
$qty_min     = (int) get_post_meta($post->ID, '_mad_acc_qty_min', true);
$qty_max     = (int) get_post_meta($post->ID, '_mad_acc_qty_max', true);
$qty_def     = (int) get_post_meta($post->ID, '_mad_acc_qty_default', true);
$title_over  = (string) get_post_meta($post->ID, '_mad_acc_title_override', true);

$scope_cats  = (array) get_post_meta($post->ID, '_mad_acc_scope_cats', true);
$scope_tags  = (array) get_post_meta($post->ID, '_mad_acc_scope_tags', true);
$scope_prods = (array) get_post_meta($post->ID, '_mad_acc_scope_products', true);

$acc_label   = $title_over ?: __('Incluir bolsas al vacío','mad-suite');

// Helper: título de producto por ID
function mad_acc_product_title($pid){
  if (!$pid) return '';
  if ('product_variation' === get_post_type($pid)) {
    $parent_id = wp_get_post_parent_id($pid);
    $base = get_the_title($parent_id);
    $prod = wc_get_product($pid);
    $var  = $prod ? wc_get_formatted_variation($prod, true, false, true) : '';
    return sprintf('#%d – %s – %s', $pid, $base, $var);
  }
  return sprintf('#%d – %s', $pid, get_the_title($pid));
}

// Árbol de categorías
$all_cats = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'parent'=>0]);
function mad_acc_render_cat_branch($term_id, $selected){
  $children = get_terms(['taxonomy'=>'product_cat','hide_empty'=>false,'parent'=>$term_id]);
  echo '<li>';
  printf(
    '<label><input type="checkbox" name="_mad_acc_scope_cats[]" value="%d" %s> %s</label>',
    $term_id,
    in_array($term_id, $selected, true) ? 'checked' : '',
    esc_html(get_term($term_id)->name)
  );
  if ($children) {
    echo '<ul>';
    foreach($children as $ch){
      mad_acc_render_cat_branch($ch->term_id, $selected);
    }
    echo '</ul>';
  }
  echo '</li>';
}

?>
<input type="hidden" name="mad_acc_nonce" value="<?php echo esc_attr($nonce); ?>"/>

<div class="mad-acc-field">
  <h3><?php esc_html_e('Producto WooCommerce accesorio','mad-suite'); ?></h3>
  <p class="mad-acc-hint"><?php esc_html_e('Busca por nombre o ID. El precio será el del propio producto.','mad-suite'); ?></p>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input type="number" min="0" step="1" placeholder="<?php esc_attr_e('ID de producto','mad-suite'); ?>" name="_mad_acc_product_id" id="mad_acc_product_id" value="<?php echo esc_attr($product_id); ?>" style="width:140px;">
    <input type="text" id="mad_acc_product_search" placeholder="<?php esc_attr_e('Escribe para buscar…','mad-suite'); ?>" style="min-width:260px;">
    <button type="button" class="button" id="mad_acc_pick_first"><?php esc_html_e('Asignar el primero','mad-suite'); ?></button>
    <span id="mad_acc_product_title" class="mad-acc-pill"><?php echo $product_id ? esc_html(mad_acc_product_title($product_id)) : ''; ?></span>
  </div>
</div>

<hr/>

<div class="mad-acc-field">
  <h3><?php esc_html_e('Dónde mostrar','mad-suite'); ?></h3>
  <label><input type="checkbox" name="_mad_acc_show_on_pdp" value="1" <?php checked($show_pdp,1); ?>> <?php esc_html_e('Mostrar selector en la página de producto (Opción B)','mad-suite'); ?></label><br/>
  <label><input type="checkbox" name="_mad_acc_show_in_cart" value="1" <?php checked($show_cart,1); ?>> <?php esc_html_e('Mostrar controles en el carrito (Opción C)','mad-suite'); ?></label>
</div>

<div class="mad-acc-field">
  <h3><?php esc_html_e('Cantidades','mad-suite'); ?></h3>
  <label><?php esc_html_e('Mínima','mad-suite'); ?>:
    <input type="number" name="_mad_acc_qty_min" min="0" step="1" value="<?php echo esc_attr($qty_min ?: 0); ?>" style="width:100px;">
  </label>
  &nbsp;&nbsp;
  <label><?php esc_html_e('Máxima','mad-suite'); ?>:
    <input type="number" name="_mad_acc_qty_max" min="0" step="1" value="<?php echo esc_attr($qty_max ?: 0); ?>" style="width:100px;">
  </label>
  &nbsp;&nbsp;
  <label><?php esc_html_e('Por defecto','mad-suite'); ?>:
    <input type="number" name="_mad_acc_qty_default" min="0" step="1" value="<?php echo esc_attr($qty_def ?: 0); ?>" style="width:100px;">
  </label>
  <p class="mad-acc-hint"><?php esc_html_e('Si Máxima = 0 se considera “sin límite”. El valor por defecto se ajustará a los topes.','mad-suite'); ?></p>
</div>

<div class="mad-acc-field">
  <h3><?php esc_html_e('Texto del campo (opcional)','mad-suite'); ?></h3>
  <input type="text" name="_mad_acc_title_override" value="<?php echo esc_attr($title_over); ?>" placeholder="<?php echo esc_attr($acc_label); ?>" style="width:100%;">
</div>

<hr/>

<div class="mad-acc-field">
  <h3><?php esc_html_e('Ámbito por categorías','mad-suite'); ?></h3>
  <div class="mad-acc-tree">
    <ul>
      <?php
      if ($all_cats) {
        foreach($all_cats as $root){
          mad_acc_render_cat_branch($root->term_id, $scope_cats);
        }
      } else {
        echo '<li><em>'.esc_html__('No hay categorías.','mad-suite').'</em></li>';
      }
      ?>
    </ul>
  </div>
  <p class="mad-acc-hint"><?php esc_html_e('Marcar una categoría padre incluye hijas y nietas; puedes desmarcar finamente.','mad-suite'); ?></p>
</div>

<div class="mad-acc-field">
  <h3><?php esc_html_e('Ámbito por etiquetas','mad-suite'); ?></h3>
  <?php
    $all_tags = get_terms(['taxonomy'=>'product_tag','hide_empty'=>false]);
    if ($all_tags && !is_wp_error($all_tags)) {
      echo '<div style="max-height:180px;overflow:auto;border:1px solid #ddd;padding:8px;">';
      foreach($all_tags as $tag){
        printf(
          '<label style="display:inline-block;min-width:220px;margin:2px 10px 2px 0;"><input type="checkbox" name="_mad_acc_scope_tags[]" value="%d" %s> %s</label>',
          $tag->term_id,
          in_array($tag->term_id, $scope_tags, true) ? 'checked' : '',
          esc_html($tag->name)
        );
      }
      echo '</div>';
    } else {
      echo '<p><em>'.esc_html__('No hay etiquetas.','mad-suite').'</em></p>';
    }
  ?>
</div>

<div class="mad-acc-field">
  <h3><?php esc_html_e('Ámbito por productos concretos','mad-suite'); ?></h3>
  <p class="mad-acc-hint"><?php esc_html_e('Escribe para buscar y pulsa “Añadir”.','mad-suite'); ?></p>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <input type="text" id="mad_acc_scope_prod_search" placeholder="<?php esc_attr_e('Buscar productos…','mad-suite'); ?>" style="min-width:260px;">
    <button type="button" class="button" id="mad_acc_scope_prod_add"><?php esc_html_e('Añadir','mad-suite'); ?></button>
  </div>
  <div id="mad_acc_scope_prod_list" style="margin-top:8px;">
    <?php
      if ($scope_prods){
        foreach($scope_prods as $pid){
          printf(
            '<span class="mad-acc-pill" data-id="%d">%s <a href="#" class="mad-acc-del" title="%s">×</a><input type="hidden" name="_mad_acc_scope_products[]" value="%d"></span>',
            (int)$pid,
            esc_html(mad_acc_product_title($pid)),
            esc_attr__('Quitar','mad-suite'),
            (int)$pid
          );
        }
      }
    ?>
  </div>
</div>

<script>
(function($){
  // ===== Buscador de PRODUCTO accesorio =====
  var typingTimer1, $input1 = $('#mad_acc_product_search'), $id = $('#mad_acc_product_id'), $title = $('#mad_acc_product_title');

  function doSearchProducts(q, cb){
    $.get(MAD_ACC_ADMIN.ajax_url, { action:'mad_acc_search_products', nonce:MAD_ACC_ADMIN.nonce, q:q }, function(res){
      cb(res && res.results ? res.results : []);
    });
  }

  $input1.on('keyup', function(){
    clearTimeout(typingTimer1);
    var v = $(this).val();
    typingTimer1 = setTimeout(function(){
      if (!v) return;
      doSearchProducts(v, function(list){
        if (!list.length) return;
        // Pone el PRIMERO como sugerencia visual (no asigna aún)
        $('#mad_acc_pick_first').data('first', list[0]);
      });
    }, 250);
  });

  $('#mad_acc_pick_first').on('click', function(e){
    e.preventDefault();
    var it = $(this).data('first');
    if (!it) return;
    $id.val(it.id);
    $title.text(it.text);
  });

  // ===== Lista de productos explícitos en Ámbito =====
  var typingTimer2, $input2 = $('#mad_acc_scope_prod_search'), $list = $('#mad_acc_scope_prod_list');

  $('#mad_acc_scope_prod_add').on('click', function(e){
    e.preventDefault();
    var q = $input2.val();
    if (!q) return;
    doSearchProducts(q, function(list){
      if (!list.length) return;
      var it = list[0]; // añade el primero encontrado (rápido)
      // Evitar duplicados
      if ($list.find('span.mad-acc-pill[data-id="'+it.id+'"]').length) return;
      var html = '<span class="mad-acc-pill" data-id="'+it.id+'">'+it.text+' <a href="#" class="mad-acc-del" title="<?php echo esc_js(__('Quitar','mad-suite')); ?>">×</a><input type="hidden" name="_mad_acc_scope_products[]" value="'+it.id+'"></span>';
      $list.append(html);
      $input2.val('');
    });
  });

  $list.on('click', '.mad-acc-del', function(e){
    e.preventDefault();
    $(this).closest('.mad-acc-pill').remove();
  });

})(jQuery);
</script>
