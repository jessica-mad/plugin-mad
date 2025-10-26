<?php
/**
 * Vista: Editar/Crear Regla de Descuento
 */

defined('ABSPATH') || exit;

$is_new = !isset($_GET['rule_id']);
$rule_id = $is_new ? '' : sanitize_text_field($_GET['rule_id']);

// Valores por defecto
$rule = [
    'id' => $rule_id,
    'name' => '',
    'enabled' => true,
    'discount_type' => 'percentage',
    'discount_value' => 0,
    'apply_to' => 'products',
    'target_ids' => [],
    'roles' => [],
    'priority' => 10,
    'date_from' => '',
    'date_to' => '',
    'coupon_config' => [
        'prefix' => 'ps',
        'name_length' => 7,
        'exclude_sale_items' => true,
        'individual_use' => true,
    ]
];

// Si es edición, cargar datos
if (!$is_new) {
    $rules = get_option('mad_private_shop_rules', []);
    if (isset($rules[$rule_id])) {
        $rule = array_merge($rule, $rules[$rule_id]);
        
        // Asegurar que coupon_config existe
        if (!isset($rule['coupon_config'])) {
            $rule['coupon_config'] = [
                'prefix' => 'ps',
                'name_length' => 7,
                'exclude_sale_items' => true,
                'individual_use' => true,
            ];
        }
    }
}

// Obtener datos necesarios
$all_roles = wp_roles()->roles;
?>

<div class="wrap">
    <h1>
        <?php echo $is_new ? '➕ Nueva Regla de Descuento' : '✏️ Editar Regla de Descuento'; ?>
    </h1>
    
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="rule-form">
        <?php wp_nonce_field('save_private_shop_rule', 'private_shop_nonce'); ?>
        <input type="hidden" name="action" value="save_private_shop_rule">
        <input type="hidden" name="rule_id" value="<?php echo esc_attr($rule['id']); ?>">
        
        <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
            
            <!-- Columna principal -->
            <div>
                
                <!-- Información Básica -->
                <div class="postbox">
                    <div class="postbox-header">
                        <h2>📝 Información Básica</h2>
                    </div>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th><label for="rule_name">Nombre de la Regla *</label></th>
                                <td>
                                    <input type="text" 
                                           id="rule_name" 
                                           name="rule_name" 
                                           value="<?php echo esc_attr($rule['name']); ?>" 
                                           class="regular-text" 
                                           required>
                                    <p class="description">Ej: "Descuento VIP", "Black Friday 2024"</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="rule_enabled">Estado</label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" 
                                               id="rule_enabled" 
                                               name="rule_enabled" 
                                               <?php checked($rule['enabled'], true); ?>>
                                        Regla activa
                                    </label>
                                    <p class="description">Solo las reglas activas generan cupones</p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Configuración del Descuento -->
                <div class="postbox">
                    <div class="postbox-header">
                        <h2>💰 Configuración del Descuento</h2>
                    </div>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th><label for="discount_type">Tipo de Descuento *</label></th>
                                <td>
                                    <select id="discount_type" name="discount_type" required>
                                        <option value="percentage" <?php selected($rule['discount_type'], 'percentage'); ?>>
                                            Porcentaje (%)
                                        </option>
                                        <option value="fixed" <?php selected($rule['discount_type'], 'fixed'); ?>>
                                            Cantidad Fija (€)
                                        </option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="discount_value">Valor del Descuento *</label></th>
                                <td>
                                    <input type="number" 
                                           id="discount_value" 
                                           name="discount_value" 
                                           value="<?php echo esc_attr($rule['discount_value']); ?>" 
                                           step="0.01" 
                                           min="0" 
                                           required>
                                    <p class="description">
                                        Si es porcentaje: del 0 al 100<br>
                                        Si es cantidad fija: monto en euros
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- Aplicar a -->
                <div class="postbox">
                    <div class="postbox-header">
                        <h2>🎯 Aplicar Descuento A</h2>
                    </div>
                    <div class="inside">
                        <table class="form-table">
                            <tr>
                                <th><label for="apply_to">Seleccionar Tipo *</label></th>
                                <td>
                                    <select id="apply_to" name="apply_to" required>
                                        <option value="products" <?php selected($rule['apply_to'], 'products'); ?>>
                                            📦 Productos específicos
                                        </option>
                                        <option value="categories" <?php selected($rule['apply_to'], 'categories'); ?>>
                                            📁 Categorías
                                        </option>
                                        <option value="tags" <?php selected($rule['apply_to'], 'tags'); ?>>
                                            🏷️ Etiquetas
                                        </option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="target_ids">Seleccionar Items *</label></th>
                                <td>
                                    <div id="target-selector">
                                        <!-- Products -->
                                        <div class="target-option" data-type="products" style="<?php echo $rule['apply_to'] === 'products' ? '' : 'display:none;'; ?>">
                                            <select name="target_ids[]" multiple size="10" style="width: 100%; min-height: 200px;">
                                                <?php
                                                $products = wc_get_products(['limit' => -1, 'orderby' => 'title', 'order' => 'ASC']);
                                                foreach ($products as $product) {
                                                    $selected = in_array($product->get_id(), $rule['target_ids']) ? 'selected' : '';
                                                    echo '<option value="' . $product->get_id() . '" ' . $selected . '>';
                                                    echo esc_html($product->get_name()) . ' (#' . $product->get_id() . ')';
                                                    echo '</option>';
                                                }
                                                ?>
                                            </select>
                                            <p class="description">Mantén Ctrl/Cmd para seleccionar múltiples</p>
                                        </div>
                                        
                                        <!-- Categories -->
                                        <div class="target-option" data-type="categories" style="<?php echo $rule['apply_to'] === 'categories' ? '' : 'display:none;'; ?>">
                                            <select name="target_ids[]" multiple size="10" style="width: 100%; min-height: 200px;">
                                                <?php
                                                $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
                                                foreach ($categories as $category) {
                                                    $selected = in_array($category->term_id, $rule['target_ids']) ? 'selected' : '';
                                                    echo '<option value="' . $category->term_id . '" ' . $selected . '>';
                                                    echo esc_html($category->name) . ' (' . $category->count . ' productos)';
                                                    echo '</option>';
                                                }
                                                ?>
                                            </select>
                                            <p class="description">Mantén Ctrl/Cmd para seleccionar múltiples</p>
                                        </div>
                                        
                                        <!-- Tags -->
                                        <div class="target-option" data-type="tags" style="<?php echo $rule['apply_to'] === 'tags' ? '' : 'display:none;'; ?>">
                                            <select name="target_ids[]" multiple size="10" style="width: 100%; min-height: 200px;">
                                                <?php
                                                $tags = get_terms(['taxonomy' => 'product_tag', 'hide_empty' => false]);
                                                foreach ($tags as $tag) {
                                                    $selected = in_array($tag->term_id, $rule['target_ids']) ? 'selected' : '';
                                                    echo '<option value="' . $tag->term_id . '" ' . $selected . '>';
                                                    echo esc_html($tag->name) . ' (' . $tag->count . ' productos)';
                                                    echo '</option>';
                                                }
                                                ?>
                                            </select>
                                            <p class="description">Mantén Ctrl/Cmd para seleccionar múltiples</p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
                <!-- NUEVO: Configuración del Cupón -->
                <div class="postbox" style="border-left: 4px solid #7b1fa2;">
                    <div class="postbox-header" style="background: #f3e5f5;">
                        <h2>🎫 Configuración del Cupón</h2>
                    </div>
                    <div class="inside">
                        <p style="background: #f3e5f5; padding: 10px; border-radius: 4px; margin-bottom: 15px;">
                            <strong>ℹ️ Importante:</strong> Los cupones se generan automáticamente cuando los usuarios con el rol configurado hacen login.
                        </p>
                        
                        <table class="form-table">
                            <tr>
                                <th><label for="coupon_prefix">Prefijo del Cupón *</label></th>
                                <td>
                                    <input type="text" 
                                           id="coupon_prefix" 
                                           name="coupon_prefix" 
                                           value="<?php echo esc_attr($rule['coupon_config']['prefix']); ?>" 
                                           maxlength="10"
                                           pattern="[a-z0-9]+"
                                           style="width: 150px;"
                                           required>
                                    <p class="description">
                                        Solo letras minúsculas y números. Máximo 10 caracteres.<br>
                                        Ejemplo: <code>vip</code>, <code>bf</code>, <code>special</code>
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th><label for="coupon_name_length">Longitud del Nombre</label></th>
                                <td>
                                    <input type="number" 
                                           id="coupon_name_length" 
                                           name="coupon_name_length" 
                                           value="<?php echo esc_attr($rule['coupon_config']['name_length']); ?>" 
                                           min="3"
                                           max="15"
                                           style="width: 80px;">
                                    caracteres
                                    <p class="description">
                                        Longitud máxima del username en el cupón (recomendado: 7)
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th>Opciones del Cupón</th>
                                <td>
                                    <label>
                                        <input type="checkbox" 
                                               name="exclude_sale_items" 
                                               <?php checked($rule['coupon_config']['exclude_sale_items'], true); ?>>
                                        <strong>Excluir productos en oferta</strong>
                                    </label>
                                    <p class="description">El cupón NO se aplicará a productos que ya tienen precio de oferta en WooCommerce</p>
                                    
                                    <label style="display: block; margin-top: 10px;">
                                        <input type="checkbox" 
                                               name="individual_use" 
                                               <?php checked($rule['coupon_config']['individual_use'], true); ?>>
                                        <strong>Uso individual</strong>
                                    </label>
                                    <p class="description">El cupón no se puede combinar con otros cupones</p>
                                </td>
                            </tr>
                            <tr>
                                <th>Preview del Cupón</th>
                                <td>
                                    <div style="background: #fff; padding: 15px; border: 2px dashed #7b1fa2; border-radius: 4px;">
                                        <div style="font-family: monospace; font-size: 16px;">
                                            <span id="coupon-preview-prefix" style="color: #7b1fa2; font-weight: bold;">
                                                <?php echo esc_html($rule['coupon_config']['prefix']); ?>
                                            </span>
                                            _<span style="color: #666;">juanper</span>_<span style="color: #999;">123</span>
                                        </div>
                                        <div style="margin-top: 8px; color: #666; font-size: 12px;">
                                            <span style="color: #7b1fa2;">●</span> Prefijo de la regla<br>
                                            <span style="color: #666;">●</span> Username (primeros <span id="coupon-preview-length"><?php echo $rule['coupon_config']['name_length']; ?></span> chars)<br>
                                            <span style="color: #999;">●</span> ID del usuario
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
                
            </div>
            
            <!-- Columna lateral -->
            <div>
                
                <!-- Guardar -->
                <div class="postbox">
                    <div class="postbox-header">
                        <h2>💾 Guardar</h2>
                    </div>
                    <div class="inside">
                        <button type="submit" class="button button-primary button-large" style="width: 100%;">
                            <?php echo $is_new ? '➕ Crear Regla' : '💾 Guardar Cambios'; ?>
                        </button>
                        <a href="<?php echo add_query_arg(['page' => 'mad-private-shop'], admin_url('admin.php')); ?>" 
                           class="button button-link" 
                           style="width: 100%; text-align: center; margin-top: 10px; display: inline-block;">
                            ← Volver a la lista
                        </a>
                        
                        <?php if (!$is_new): ?>
                            <hr style="margin: 15px 0;">
                            <p style="color: #666; font-size: 12px; margin: 0;">
                                Al guardar cambios, se actualizarán automáticamente todos los cupones generados por esta regla.
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Roles -->
                <div class="postbox">
                    <div class="postbox-header">
                        <h2>👥 Roles de Usuario</h2>
                    </div>
                    <div class="inside">
                        <p style="margin-top: 0;">Selecciona los roles que tendrán acceso a este descuento:</p>
                        <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #fafafa;">
                            <?php foreach ($all_roles as $role_key => $role_data): ?>
                                <label style="display: block; margin: 5px 0;">
                                    <input type="checkbox" 
                                           name="roles[]" 
                                           value="<?php echo esc_attr($role_key); ?>"
                                           <?php checked(in_array($role_key, $rule['roles'])); ?>>
                                    <?php echo esc_html($role_data['name']); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description">Se recomienda que cada usuario tenga solo un rol</p>
                    </div>
                </div>
                
                <!-- Prioridad -->
                <div class="postbox">
                    <div class="postbox-header">
                        <h2>⚡ Prioridad</h2>
                    </div>
                    <div class="inside">
                        <label for="priority">Nivel de prioridad:</label>
                        <input type="number" 
                               id="priority" 
                               name="priority" 
                               value="<?php echo esc_attr($rule['priority']); ?>" 
                               min="1" 
                               max="999" 
                               style="width: 80px;">
                        <p class="description">
                            Menor número = mayor prioridad<br>
                            (1 es la máxima, 999 la mínima)
                        </p>
                    </div>
                </div>
                
                <!-- Fechas -->
                <div class="postbox">
                    <div class="postbox-header">
                        <h2>📅 Fechas (Opcional)</h2>
                    </div>
                    <div class="inside">
                        <label for="date_from">Desde:</label>
                        <input type="date" 
                               id="date_from" 
                               name="date_from" 
                               value="<?php echo esc_attr($rule['date_from']); ?>" 
                               style="width: 100%;">
                        
                        <label for="date_to" style="margin-top: 10px; display: block;">Hasta:</label>
                        <input type="date" 
                               id="date_to" 
                               name="date_to" 
                               value="<?php echo esc_attr($rule['date_to']); ?>" 
                               style="width: 100%;">
                        
                        <p class="description">
                            Déjalas vacías para que la regla esté siempre activa
                        </p>
                    </div>
                </div>
                
            </div>
            
        </div>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Cambiar selector de targets según apply_to
    $('#apply_to').on('change', function() {
        var type = $(this).val();
        $('.target-option').hide();
        $('.target-option[data-type="' + type + '"]').show();
        
        // Deseleccionar opciones de los otros selectores
        $('.target-option').not('[data-type="' + type + '"]').find('select').val([]);
    });
    
    // Preview del cupón
    $('#coupon_prefix').on('input', function() {
        var value = $(this).val().toLowerCase().replace(/[^a-z0-9]/g, '');
        $(this).val(value);
        $('#coupon-preview-prefix').text(value || 'ps');
    });
    
    $('#coupon_name_length').on('input', function() {
        $('#coupon-preview-length').text($(this).val());
    });
    
    // Validación del formulario
    $('#rule-form').on('submit', function(e) {
        var selectedTargets = $('.target-option:visible select').val();
        if (!selectedTargets || selectedTargets.length === 0) {
            e.preventDefault();
            alert('Debes seleccionar al menos un item (producto, categoría o etiqueta)');
            return false;
        }
    });
});
</script>

<style>
.postbox {
    margin-bottom: 20px;
}
.postbox-header h2 {
    font-size: 14px;
    padding: 12px;
    margin: 0;
}
.form-table th {
    width: 200px;
    padding: 15px 10px 15px 0;
}
.form-table td {
    padding: 15px 10px;
}
</style>