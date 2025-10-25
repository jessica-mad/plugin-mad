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
];

// Si es edición, cargar datos
if (!$is_new) {
    $rules = get_option('mad_private_shop_rules', []);
    if (isset($rules[$rule_id])) {
        $rule = array_merge($rule, $rules[$rule_id]);
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
                
                <!-- Información básica -->
                <div class="card">
                    <h2>📝 Información Básica</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="rule_name">Nombre de la Regla *</label></th>
                            <td>
                                <input type="text" 
                                       id="rule_name" 
                                       name="rule_name" 
                                       value="<?php echo esc_attr($rule['name']); ?>" 
                                       class="regular-text" 
                                       required
                                       placeholder="Ej: Black Friday VIP, Descuento Mayoristas...">
                                <p class="description">Un nombre descriptivo para identificar esta regla</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="rule_enabled">Estado</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           id="rule_enabled" 
                                           name="rule_enabled" 
                                           value="1" 
                                           <?php checked($rule['enabled']); ?>>
                                    <strong>Regla activa</strong>
                                </label>
                                <p class="description">Desactiva la regla sin eliminarla para usarla más tarde</p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Configuración del descuento -->
                <div class="card" style="margin-top: 20px;">
                    <h2>💰 Configuración del Descuento</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="discount_type">Tipo de Descuento *</label></th>
                            <td>
                                <select id="discount_type" name="discount_type" required>
                                    <option value="percentage" <?php selected($rule['discount_type'], 'percentage'); ?>>
                                        Porcentaje (%)
                                    </option>
                                    <option value="fixed" <?php selected($rule['discount_type'], 'fixed'); ?>>
                                        Cantidad Fija (<?php echo get_woocommerce_currency_symbol(); ?>)
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
                                       required
                                       style="width: 150px;">
                                <span id="discount_symbol"></span>
                                <p class="description">
                                    <span id="discount_help_percentage" style="display: none;">
                                        Ejemplo: 20 = 20% de descuento
                                    </span>
                                    <span id="discount_help_fixed" style="display: none;">
                                        Ejemplo: 10 = <?php echo get_woocommerce_currency_symbol(); ?>10 de descuento
                                    </span>
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
                
                <!-- Aplicar a -->
                <div class="card" style="margin-top: 20px;">
                    <h2>🎯 ¿A qué aplicar el descuento?</h2>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="apply_to">Aplicar a *</label></th>
                            <td>
                                <select id="apply_to" name="apply_to" required>
                                    <option value="products" <?php selected($rule['apply_to'], 'products'); ?>>
                                        📦 Productos Específicos
                                    </option>
                                    <option value="categories" <?php selected($rule['apply_to'], 'categories'); ?>>
                                        📁 Categorías de Productos
                                    </option>
                                    <option value="tags" <?php selected($rule['apply_to'], 'tags'); ?>>
                                        🏷️ Etiquetas de Productos
                                    </option>
                                </select>
                            </td>
                        </tr>
                        
                        <tr id="selector-products" style="display: none;">
                            <th><label for="target_products">Seleccionar Productos</label></th>
                            <td>
                                <select id="target_products" name="target_ids[]" multiple style="width: 100%; height: 200px;">
                                    <?php
                                    $products = wc_get_products(['limit' => -1, 'orderby' => 'title', 'order' => 'ASC']);
                                    foreach ($products as $product) {
                                        $selected = in_array($product->get_id(), $rule['target_ids']) ? 'selected' : '';
                                        echo sprintf(
                                            '<option value="%d" %s>%s (ID: %d)</option>',
                                            $product->get_id(),
                                            $selected,
                                            esc_html($product->get_name()),
                                            $product->get_id()
                                        );
                                    }
                                    ?>
                                </select>
                                <p class="description">
                                    Mantén presionado Ctrl (Windows) o Cmd (Mac) para seleccionar múltiples productos
                                </p>
                            </td>
                        </tr>
                        
                        <tr id="selector-categories" style="display: none;">
                            <th><label for="target_categories">Seleccionar Categorías</label></th>
                            <td>
                                <select id="target_categories" name="target_ids[]" multiple style="width: 100%; height: 200px;">
                                    <?php
                                    $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
                                    foreach ($categories as $category) {
                                        $selected = in_array($category->term_id, $rule['target_ids']) ? 'selected' : '';
                                        echo sprintf(
                                            '<option value="%d" %s>%s (%d productos)</option>',
                                            $category->term_id,
                                            $selected,
                                            esc_html($category->name),
                                            $category->count
                                        );
                                    }
                                    ?>
                                </select>
                                <p class="description">
                                    Mantén presionado Ctrl (Windows) o Cmd (Mac) para seleccionar múltiples categorías
                                </p>
                            </td>
                        </tr>
                        
                        <tr id="selector-tags" style="display: none;">
                            <th><label for="target_tags">Seleccionar Etiquetas</label></th>
                            <td>
                                <select id="target_tags" name="target_ids[]" multiple style="width: 100%; height: 200px;">
                                    <?php
                                    $tags = get_terms(['taxonomy' => 'product_tag', 'hide_empty' => false]);
                                    foreach ($tags as $tag) {
                                        $selected = in_array($tag->term_id, $rule['target_ids']) ? 'selected' : '';
                                        echo sprintf(
                                            '<option value="%d" %s>%s (%d productos)</option>',
                                            $tag->term_id,
                                            $selected,
                                            esc_html($tag->name),
                                            $tag->count
                                        );
                                    }
                                    ?>
                                </select>
                                <p class="description">
                                    Mantén presionado Ctrl (Windows) o Cmd (Mac) para seleccionar múltiples etiquetas
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>
                
            </div>
            
            <!-- Columna lateral -->
            <div>
                
                <!-- Roles -->
                <div class="card">
                    <h2>👥 Roles de Usuario</h2>
                    <p>Selecciona los roles que pueden ver y usar este descuento:</p>
                    
                    <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; background: #f9f9f9;">
                        <?php foreach ($all_roles as $role_key => $role_data): ?>
                            <label style="display: block; padding: 5px 0;">
                                <input type="checkbox" 
                                       name="roles[]" 
                                       value="<?php echo esc_attr($role_key); ?>"
                                       <?php checked(in_array($role_key, $rule['roles'])); ?>>
                                <?php echo esc_html($role_data['name']); ?>
                                <span style="color: #666; font-size: 12px;">
                                    (<?php echo count(get_users(['role' => $role_key])); ?> usuarios)
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    
                    <p class="description" style="margin-top: 10px;">
                        Si no seleccionas ninguno, se aplicará a todos los roles
                    </p>
                </div>
                
                <!-- Prioridad -->
                <div class="card" style="margin-top: 20px;">
                    <h2>⚡ Prioridad</h2>
                    <p>Cuando múltiples reglas aplican al mismo producto:</p>
                    
                    <input type="number" 
                           name="priority" 
                           value="<?php echo esc_attr($rule['priority']); ?>" 
                           min="1" 
                           max="999" 
                           style="width: 100px;">
                    
                    <p class="description">
                        <strong>Menor número = Mayor prioridad</strong><br>
                        Ejemplo: Prioridad 1 se aplica antes que prioridad 10
                    </p>
                </div>
                
                <!-- Fechas -->
                <div class="card" style="margin-top: 20px;">
                    <h2>📅 Fechas de Validez</h2>
                    <p>Opcional: Limita cuando esta regla está activa</p>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="date_from">Desde:</label></th>
                            <td>
                                <input type="date" 
                                       id="date_from" 
                                       name="date_from" 
                                       value="<?php echo esc_attr($rule['date_from']); ?>" 
                                       style="width: 100%;">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="date_to">Hasta:</label></th>
                            <td>
                                <input type="date" 
                                       id="date_to" 
                                       name="date_to" 
                                       value="<?php echo esc_attr($rule['date_to']); ?>" 
                                       style="width: 100%;">
                            </td>
                        </tr>
                    </table>
                    
                    <p class="description">
                        Deja en blanco para que la regla esté siempre activa
                    </p>
                </div>
                
            </div>
        </div>
        
        <!-- Botones de acción -->
        <p class="submit">
            <button type="submit" class="button button-primary button-large">
                💾 Guardar Regla
            </button>
            <a href="<?php echo admin_url('admin.php?page=mad-private-shop'); ?>" class="button button-large">
                ← Volver a la lista
            </a>
        </p>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    
    // Actualizar símbolo y ayuda según tipo de descuento
    function updateDiscountType() {
        const type = $('#discount_type').val();
        
        if (type === 'percentage') {
            $('#discount_symbol').text('%');
            $('#discount_help_percentage').show();
            $('#discount_help_fixed').hide();
        } else {
            $('#discount_symbol').text('<?php echo get_woocommerce_currency_symbol(); ?>');
            $('#discount_help_percentage').hide();
            $('#discount_help_fixed').show();
        }
    }
    
    // Mostrar selector correcto según "Aplicar a"
    function updateApplyTo() {
        const applyTo = $('#apply_to').val();
        
        // Ocultar todos
        $('#selector-products, #selector-categories, #selector-tags').hide();
        
        // Deshabilitar todos los selects
        $('#target_products, #target_categories, #target_tags').prop('disabled', true);
        
        // Mostrar y habilitar el correcto
        $('#selector-' + applyTo).show();
        $('#target_' + applyTo).prop('disabled', false);
    }
    
    // Inicializar
    updateDiscountType();
    updateApplyTo();
    
    // Eventos
    $('#discount_type').on('change', updateDiscountType);
    $('#apply_to').on('change', updateApplyTo);
    
    // Validación antes de enviar
    $('#rule-form').on('submit', function(e) {
        const applyTo = $('#apply_to').val();
        const selector = $('#target_' + applyTo);
        const selectedCount = selector.val() ? selector.val().length : 0;
        
        if (selectedCount === 0) {
            e.preventDefault();
            alert('⚠️ Debes seleccionar al menos un elemento para aplicar el descuento.');
            selector.focus();
            return false;
        }
        
        const ruleName = $('#rule_name').val().trim();
        if (!ruleName) {
            e.preventDefault();
            alert('⚠️ El nombre de la regla es obligatorio.');
            $('#rule_name').focus();
            return false;
        }
        
        const discountValue = parseFloat($('#discount_value').val());
        if (discountValue <= 0) {
            e.preventDefault();
            alert('⚠️ El valor del descuento debe ser mayor que 0.');
            $('#discount_value').focus();
            return false;
        }
        
        return true;
    });
});
</script>

<style>
.card h2 {
    margin-top: 0;
    border-bottom: 1px solid #ddd;
    padding-bottom: 10px;
}

select[multiple] {
    padding: 5px;
    border: 1px solid #ddd;
    border-radius: 3px;
}

select[multiple] option {
    padding: 5px 8px;
}

select[multiple] option:checked {
    background: linear-gradient(0deg, #2196F3 0%, #2196F3 100%);
    color: white;
}

#discount_symbol {
    font-weight: bold;
    font-size: 16px;
    margin-left: 5px;
    color: #2196F3;
}
</style>