<?php
/**
 * Vista de configuración de Private Shop
 * Formulario estándar sin AJAX
 */

defined('ABSPATH') || exit;

// Mensaje de éxito
if (isset($_GET['saved']) && $_GET['saved'] === 'true') {
    echo '<div class="notice notice-success is-dismissible">';
    echo '<p><strong>✓ Descuentos guardados correctamente</strong></p>';
    echo '</div>';
}
?>

<div class="wrap">
    <h1>🔒 Private Shop - Configuración de Descuentos</h1>
    
    <!-- Link a logs -->
    <div class="notice notice-info" style="margin: 20px 0;">
        <p>
            📋 <strong>Logs del sistema:</strong> 
            <a href="<?php echo esc_url($this->get_log_url()); ?>" target="_blank">
                Ver log de hoy
            </a>
            <span style="color: #666; margin-left: 10px;">
                (Descarga el archivo para ver el contenido completo)
            </span>
        </p>
    </div>
    
    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="private-shop-form">
        <?php wp_nonce_field('save_private_shop_discounts', 'private_shop_nonce'); ?>
        <input type="hidden" name="action" value="save_private_shop_discounts">
        
        <!-- Sección de roles -->
        <div class="card" style="max-width: 100%; margin-top: 20px;">
            <h2>👥 Roles con Acceso a Descuentos</h2>
            <p>Selecciona los roles de usuario que podrán ver y beneficiarse de los descuentos:</p>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 10px; margin: 15px 0;">
                <?php foreach ($all_roles as $role_key => $role_data): ?>
                    <label style="display: flex; align-items: center; padding: 8px; background: #f9f9f9; border-radius: 4px;">
                        <input 
                            type="checkbox" 
                            name="private_shop_roles[]" 
                            value="<?php echo esc_attr($role_key); ?>"
                            <?php checked(in_array($role_key, $selected_roles)); ?>
                            style="margin-right: 8px;"
                        >
                        <span><?php echo esc_html($role_data['name']); ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Tabla de descuentos -->
        <div class="card" style="max-width: 100%; margin-top: 20px;">
            <h2>💰 Descuentos por Producto</h2>
            <p>Define el porcentaje de descuento para cada producto. Los cambios se guardan al hacer clic en "Guardar Descuentos".</p>
            
            <!-- Buscador de productos -->
            <div style="margin: 15px 0;">
                <input 
                    type="text" 
                    id="product-search" 
                    placeholder="🔍 Buscar producto por nombre o SKU..." 
                    style="width: 100%; max-width: 500px; padding: 8px;"
                >
            </div>
            
            <!-- Contador de productos con descuento -->
            <div id="discount-counter" style="margin: 10px 0; padding: 10px; background: #e7f3ff; border-left: 4px solid #2196F3; display: none;">
                <strong>Productos con descuento:</strong> <span id="count-value">0</span>
            </div>
            
            <table class="wp-list-table widefat fixed striped" id="discounts-table">
                <thead>
                    <tr>
                        <th style="width: 80px;">ID</th>
                        <th style="width: 120px;">Imagen</th>
                        <th>Producto</th>
                        <th style="width: 150px;">SKU</th>
                        <th style="width: 120px;">Precio</th>
                        <th style="width: 150px;">Descuento (%)</th>
                        <th style="width: 120px;">Precio Final</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($products)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px;">
                                <p>No hay productos disponibles</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($products as $product): 
                            $product_id = $product->get_id();
                            $current_discount = isset($discounts[$product_id]) ? $discounts[$product_id] : 0;
                            $price = $product->get_regular_price();
                            $image = $product->get_image('thumbnail');
                        ?>
                            <tr class="product-row" data-product-name="<?php echo esc_attr(strtolower($product->get_name())); ?>" data-product-sku="<?php echo esc_attr(strtolower($product->get_sku())); ?>">
                                <td><?php echo $product_id; ?></td>
                                <td><?php echo $image; ?></td>
                                <td>
                                    <strong><?php echo esc_html($product->get_name()); ?></strong>
                                    <div style="color: #666; font-size: 12px;">
                                        <a href="<?php echo get_edit_post_link($product_id); ?>" target="_blank">Editar</a> |
                                        <a href="<?php echo get_permalink($product_id); ?>" target="_blank">Ver</a>
                                    </div>
                                </td>
                                <td><?php echo $product->get_sku() ?: '—'; ?></td>
                                <td class="original-price">
                                    <?php echo wc_price($price); ?>
                                </td>
                                <td>
                                    <input 
                                        type="number" 
                                        name="discounts[<?php echo $product_id; ?>]" 
                                        value="<?php echo esc_attr($current_discount); ?>" 
                                        min="0" 
                                        max="100" 
                                        step="0.01"
                                        class="discount-input"
                                        data-product-id="<?php echo $product_id; ?>"
                                        data-price="<?php echo esc_attr($price); ?>"
                                        style="width: 100%;"
                                        placeholder="0"
                                    >
                                </td>
                                <td class="final-price">
                                    <?php 
                                    if ($current_discount > 0) {
                                        $final_price = $price * (1 - ($current_discount / 100));
                                        echo wc_price($final_price);
                                    } else {
                                        echo '—';
                                    }
                                    ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Botón de guardar -->
        <p class="submit">
            <button type="submit" class="button button-primary button-large" id="save-discounts-btn">
                💾 Guardar Descuentos
            </button>
            <span id="save-status" style="margin-left: 15px; display: none;">
                Guardando cambios...
            </span>
        </p>
    </form>
</div>

<style>
#discounts-table {
    margin-top: 15px;
}

#discounts-table th {
    font-weight: 600;
}

#discounts-table td {
    vertical-align: middle;
}

.discount-input {
    padding: 6px 8px;
    border: 1px solid #ddd;
    border-radius: 3px;
}

.discount-input:focus {
    border-color: #2196F3;
    outline: none;
    box-shadow: 0 0 0 1px #2196F3;
}

.product-row.has-discount {
    background-color: #f0f9ff !important;
}

.private-shop-badge {
    background: #4CAF50;
    color: white;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
    margin-left: 5px;
}

.product-row.hidden {
    display: none;
}
</style>

<script>
jQuery(document).ready(function($) {
    
    // Calcular precio final en tiempo real
    $('.discount-input').on('input', function() {
        const $input = $(this);
        const $row = $input.closest('tr');
        const discount = parseFloat($input.val()) || 0;
        const originalPrice = parseFloat($input.data('price'));
        const $finalPriceCell = $row.find('.final-price');
        
        if (discount > 0 && discount <= 100) {
            const finalPrice = originalPrice * (1 - (discount / 100));
            $finalPriceCell.html('<?php echo get_woocommerce_currency_symbol(); ?>' + finalPrice.toFixed(2));
            $row.addClass('has-discount');
        } else {
            $finalPriceCell.html('—');
            $row.removeClass('has-discount');
        }
        
        updateDiscountCounter();
    });
    
    // Contador de productos con descuento
    function updateDiscountCounter() {
        const count = $('.discount-input').filter(function() {
            return parseFloat($(this).val()) > 0;
        }).length;
        
        if (count > 0) {
            $('#discount-counter').show();
            $('#count-value').text(count);
        } else {
            $('#discount-counter').hide();
        }
    }
    
    // Inicializar contador
    updateDiscountCounter();
    
    // Buscador de productos
    $('#product-search').on('keyup', function() {
        const searchTerm = $(this).val().toLowerCase();
        
        $('.product-row').each(function() {
            const $row = $(this);
            const productName = $row.data('product-name') || '';
            const productSku = $row.data('product-sku') || '';
            
            if (productName.includes(searchTerm) || productSku.includes(searchTerm)) {
                $row.removeClass('hidden');
            } else {
                $row.addClass('hidden');
            }
        });
    });
    
    // Indicador visual al guardar
    $('#private-shop-form').on('submit', function() {
        $('#save-discounts-btn').prop('disabled', true).text('⏳ Guardando...');
        $('#save-status').show();
    });
    
    // Mensaje de confirmación si hay cambios sin guardar
    let formModified = false;
    $('.discount-input, input[name="private_shop_roles[]"]').on('change', function() {
        formModified = true;
    });
    
    $(window).on('beforeunload', function() {
        if (formModified) {
            return '¿Estás seguro? Hay cambios sin guardar.';
        }
    });
    
    $('#private-shop-form').on('submit', function() {
        formModified = false;
    });
});
</script>