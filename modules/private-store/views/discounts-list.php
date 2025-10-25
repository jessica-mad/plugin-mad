<?php
/**
 * Discounts List - Componente reutilizable para mostrar lista de descuentos
 * 
 * Este archivo puede ser incluido en diferentes partes del admin
 *
 * @package MAD_Suite
 * @subpackage Private_Store
 */

if (!defined('ABSPATH')) {
    exit;
}

// Variables que pueden ser pasadas al incluir este archivo
$show_actions = isset($show_actions) ? $show_actions : true;
$limit = isset($limit) ? $limit : -1;
$compact_mode = isset($compact_mode) ? $compact_mode : false;

// Obtener descuentos
$all_discounts = get_option('mads_ps_discounts', []);

// Aplicar límite si existe
if ($limit > 0) {
    $discounts = array_slice($all_discounts, 0, $limit);
} else {
    $discounts = $all_discounts;
}

// Contar total
$total_discounts = count($all_discounts);

?>

<div class="mads-ps-discounts-list <?php echo $compact_mode ? 'compact-mode' : ''; ?>">
    
    <?php if (!$compact_mode): ?>
        <div class="discounts-header">
            <h3><?php _e('Descuentos Activos', 'mad-suite'); ?></h3>
            <?php if ($total_discounts > 0): ?>
                <span class="discounts-count"><?php printf(_n('%d descuento', '%d descuentos', $total_discounts, 'mad-suite'), $total_discounts); ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    
    <?php if (empty($discounts)): ?>
        
        <!-- Estado vacío -->
        <div class="discounts-empty-state">
            <span class="dashicons dashicons-tag"></span>
            <p><?php _e('No hay descuentos configurados', 'mad-suite'); ?></p>
            <?php if ($show_actions): ?>
                <a href="?page=mad-suite-private-store&tab=discounts" class="button button-primary">
                    <?php _e('Agregar primer descuento', 'mad-suite'); ?>
                </a>
            <?php endif; ?>
        </div>
        
    <?php else: ?>
        
        <!-- Tabla de descuentos -->
        <table class="wp-list-table widefat fixed striped discounts-table">
            <thead>
                <tr>
                    <?php if (!$compact_mode): ?>
                        <th class="column-type"><?php _e('Tipo', 'mad-suite'); ?></th>
                    <?php endif; ?>
                    <th class="column-target"><?php _e('Aplica a', 'mad-suite'); ?></th>
                    <th class="column-amount"><?php _e('Descuento', 'mad-suite'); ?></th>
                    <?php if (!$compact_mode): ?>
                        <th class="column-products"><?php _e('Productos', 'mad-suite'); ?></th>
                    <?php endif; ?>
                    <?php if ($show_actions): ?>
                        <th class="column-actions"><?php _e('Acciones', 'mad-suite'); ?></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($discounts as $index => $discount): 
                    $term = get_term($discount['target']);
                    $term_exists = $term && !is_wp_error($term);
                    $products_count = $term_exists ? $term->count : 0;
                ?>
                    <tr class="discount-row <?php echo !$term_exists ? 'discount-invalid' : ''; ?>" 
                        data-discount-id="<?php echo esc_attr($index); ?>"
                        data-discount-type="<?php echo esc_attr($discount['type']); ?>">
                        
                        <?php if (!$compact_mode): ?>
                            <td class="column-type">
                                <?php if ($discount['type'] === 'category'): ?>
                                    <span class="discount-type-badge category">
                                        <span class="dashicons dashicons-category"></span>
                                        <?php _e('Categoría', 'mad-suite'); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="discount-type-badge tag">
                                        <span class="dashicons dashicons-tag"></span>
                                        <?php _e('Etiqueta', 'mad-suite'); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        
                        <td class="column-target">
                            <?php if ($term_exists): ?>
                                <strong><?php echo esc_html($term->name); ?></strong>
                                <?php if ($compact_mode): ?>
                                    <br><small class="discount-type-label">
                                        <?php echo $discount['type'] === 'category' ? __('Categoría', 'mad-suite') : __('Etiqueta', 'mad-suite'); ?>
                                    </small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="discount-invalid-label">
                                    <?php _e('(Eliminado)', 'mad-suite'); ?>
                                    <span class="dashicons dashicons-warning" title="<?php esc_attr_e('Esta categoría o etiqueta ya no existe', 'mad-suite'); ?>"></span>
                                </span>
                            <?php endif; ?>
                        </td>
                        
                        <td class="column-amount">
                            <span class="discount-amount-badge <?php echo esc_attr($discount['amount_type']); ?>">
                                <?php if ($discount['amount_type'] === 'percentage'): ?>
                                    <span class="amount-value"><?php echo esc_html($discount['amount']); ?>%</span>
                                <?php else: ?>
                                    <span class="amount-value"><?php echo wc_price($discount['amount']); ?></span>
                                <?php endif; ?>
                            </span>
                        </td>
                        
                        <?php if (!$compact_mode): ?>
                            <td class="column-products">
                                <?php if ($term_exists): ?>
                                    <span class="products-count">
                                        <?php printf(_n('%d producto', '%d productos', $products_count, 'mad-suite'), $products_count); ?>
                                    </span>
                                    <?php if ($products_count > 0): ?>
                                        <a href="<?php echo admin_url('edit.php?post_type=product&' . ($discount['type'] === 'category' ? 'product_cat' : 'product_tag') . '=' . $term->slug); ?>" 
                                           class="view-products-link" 
                                           target="_blank">
                                            <?php _e('Ver', 'mad-suite'); ?>
                                        </a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="products-count-na">—</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        
                        <?php if ($show_actions): ?>
                            <td class="column-actions">
                                <div class="discount-actions">
                                    <button type="button" 
                                            class="button button-small edit-discount" 
                                            data-discount-id="<?php echo esc_attr($index); ?>"
                                            title="<?php esc_attr_e('Editar descuento', 'mad-suite'); ?>">
                                        <span class="dashicons dashicons-edit"></span>
                                        <?php if (!$compact_mode): ?>
                                            <?php _e('Editar', 'mad-suite'); ?>
                                        <?php endif; ?>
                                    </button>
                                    
                                    <button type="button" 
                                            class="button button-small button-link-delete delete-discount" 
                                            data-discount-id="<?php echo esc_attr($index); ?>"
                                            title="<?php esc_attr_e('Eliminar descuento', 'mad-suite'); ?>">
                                        <span class="dashicons dashicons-trash"></span>
                                        <?php if (!$compact_mode): ?>
                                            <?php _e('Eliminar', 'mad-suite'); ?>
                                        <?php endif; ?>
                                    </button>
                                </div>
                            </td>
                        <?php endif; ?>
                        
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if ($limit > 0 && $total_discounts > $limit): ?>
            <div class="discounts-footer">
                <a href="?page=mad-suite-private-store&tab=discounts" class="button">
                    <?php printf(__('Ver todos los %d descuentos', 'mad-suite'), $total_discounts); ?>
                </a>
            </div>
        <?php endif; ?>
        
    <?php endif; ?>
    
</div>

<style>
/* ==========================================
   LISTA DE DESCUENTOS
   ========================================== */
.mads-ps-discounts-list {
    margin: 20px 0;
}

.discounts-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #f0f0f1;
}

.discounts-header h3 {
    margin: 0;
    font-size: 18px;
}

.discounts-count {
    background: #E74C3C;
    color: #fff;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: bold;
}

/* Estado vacío */
.discounts-empty-state {
    text-align: center;
    padding: 60px 20px;
    background: #f9f9f9;
    border-radius: 8px;
    border: 2px dashed #ddd;
}

.discounts-empty-state .dashicons {
    font-size: 64px;
    width: 64px;
    height: 64px;
    color: #ccc;
    margin-bottom: 15px;
}

.discounts-empty-state p {
    color: #666;
    font-size: 16px;
    margin: 0 0 20px 0;
}

/* Tabla */
.discounts-table {
    border: 1px solid #ddd;
    border-radius: 8px;
    overflow: hidden;
}

.discounts-table thead {
    background: #f9f9f9;
}

.discounts-table th {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    padding: 12px;
}

.discounts-table td {
    padding: 12px;
    vertical-align: middle;
}

/* Columnas */
.column-type {
    width: 130px;
}

.column-target {
    width: auto;
}

.column-amount {
    width: 140px;
}

.column-products {
    width: 120px;
}

.column-actions {
    width: 150px;
    text-align: right;
}

/* Badges de tipo */
.discount-type-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: 600;
}

.discount-type-badge.category {
    background: #E3F2FD;
    color: #1976D2;
}

.discount-type-badge.tag {
    background: #F3E5F5;
    color: #7B1FA2;
}

.discount-type-badge .dashicons {
    font-size: 14px;
    width: 14px;
    height: 14px;
}

/* Badge de cantidad */
.discount-amount-badge {
    display: inline-block;
    padding: 6px 14px;
    border-radius: 20px;
    font-weight: bold;
    font-size: 14px;
}

.discount-amount-badge.percentage {
    background: #E74C3C;
    color: #fff;
}

.discount-amount-badge.fixed {
    background: #27AE60;
    color: #fff;
}

.amount-value {
    display: inline-block;
}

/* Productos */
.products-count {
    color: #666;
    font-size: 13px;
}

.view-products-link {
    margin-left: 8px;
    font-size: 12px;
    text-decoration: none;
}

.products-count-na {
    color: #ccc;
}

/* Acciones */
.discount-actions {
    display: flex;
    gap: 5px;
    justify-content: flex-end;
}

.discount-actions .button-small {
    padding: 4px 8px;
    font-size: 12px;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}

.discount-actions .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

/* Descuento inválido */
.discount-invalid {
    background: #fff3cd;
}

.discount-invalid-label {
    color: #856404;
    display: flex;
    align-items: center;
    gap: 5px;
}

.discount-invalid-label .dashicons {
    color: #f56e00;
}

/* Footer */
.discounts-footer {
    text-align: center;
    padding: 15px;
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-top: none;
    border-radius: 0 0 8px 8px;
}

/* Modo compacto */
.compact-mode .discounts-table th,
.compact-mode .discounts-table td {
    padding: 8px;
    font-size: 12px;
}

.compact-mode .discount-amount-badge {
    padding: 4px 10px;
    font-size: 12px;
}

.compact-mode .discount-actions .button-small {
    padding: 2px 6px;
}

.discount-type-label {
    color: #666;
    font-size: 11px;
}

/* Responsive */
@media (max-width: 782px) {
    .discounts-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .discount-actions {
        flex-direction: column;
        gap: 5px;
    }
    
    .column-actions {
        text-align: left;
    }
}
</style>