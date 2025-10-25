<?php
/**
 * Settings Page - Panel de administración completo
 * 
 * Panel principal con todas las tabs integradas
 *
 * @package MAD_Suite
 * @subpackage Private_Store
 */

if (!defined('ABSPATH')) {
    exit;
}

use MAD_Suite\Modules\PrivateStore\UserRole;
use MAD_Suite\Modules\PrivateStore\ProductVisibility;
use MAD_Suite\Modules\PrivateStore\PricingEngine;
use MAD_Suite\Modules\PrivateStore\Logger;

// Variables globales
$active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';

// Configuración
$role_name = get_option('mads_ps_role_name', __('Cliente VIP', 'mad-suite'));
$enable_logging = get_option('mads_ps_enable_logging', 1);
$redirect_after_login = get_option('mads_ps_redirect_after_login', 0);
$show_vip_badge = get_option('mads_ps_show_vip_badge', 1);
$custom_css = get_option('mads_ps_custom_css', '');

// Estadísticas
$vip_count = UserRole::instance()->count_vip_users();
$vip_products_count = ProductVisibility::instance()->count_vip_products();
$discounts = get_option('mads_ps_discounts', []);

// Logs
$logger = new Logger('private-store');
$log_stats = $logger->get_log_stats();
$available_logs = Logger::get_available_logs();

// Usuarios VIP
$vip_users = UserRole::instance()->get_vip_users(['number' => 20]);

// Productos VIP
$vip_products = ProductVisibility::instance()->get_vip_products(['posts_per_page' => 20]);

// Categorías y etiquetas para descuentos
$categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
$tags = get_terms(['taxonomy' => 'product_tag', 'hide_empty' => false]);

?>

<div class="wrap mads-private-store-settings">
    <h1><?php _e('Tienda Privada - Configuración', 'mad-suite'); ?></h1>
    
    <!-- Estadísticas rápidas -->
    <div class="mads-ps-stats-cards">
        
        <div class="mads-ps-stat-card stat-vip">
            <div class="stat-content">
                <span class="dashicons dashicons-star-filled"></span>
                <div class="stat-info">
                    <div class="stat-number"><?php echo esc_html($vip_count); ?></div>
                    <div class="stat-label"><?php _e('Clientes VIP', 'mad-suite'); ?></div>
                </div>
            </div>
        </div>
        
        <div class="mads-ps-stat-card stat-products">
            <div class="stat-content">
                <span class="dashicons dashicons-products"></span>
                <div class="stat-info">
                    <div class="stat-number"><?php echo esc_html($vip_products_count); ?></div>
                    <div class="stat-label"><?php _e('Productos Exclusivos', 'mad-suite'); ?></div>
                </div>
            </div>
        </div>
        
        <div class="mads-ps-stat-card stat-discounts">
            <div class="stat-content">
                <span class="dashicons dashicons-tag"></span>
                <div class="stat-info">
                    <div class="stat-number"><?php echo esc_html(count($discounts)); ?></div>
                    <div class="stat-label"><?php _e('Descuentos Activos', 'mad-suite'); ?></div>
                </div>
            </div>
        </div>
        
        <div class="mads-ps-stat-card stat-logs">
            <div class="stat-content">
                <span class="dashicons dashicons-admin-settings"></span>
                <div class="stat-info">
                    <div class="stat-number-small"><?php echo esc_html($log_stats['size_formatted'] ?? '0 KB'); ?></div>
                    <div class="stat-label"><?php _e('Tamaño de Logs', 'mad-suite'); ?></div>
                </div>
            </div>
        </div>
        
    </div>
    
    <!-- Tabs -->
    <nav class="nav-tab-wrapper woo-nav-tab-wrapper">
        <a href="?page=mad-suite-private-store&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-admin-settings"></span>
            <?php _e('General', 'mad-suite'); ?>
        </a>
        <a href="?page=mad-suite-private-store&tab=discounts" class="nav-tab <?php echo $active_tab === 'discounts' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-tag"></span>
            <?php _e('Descuentos', 'mad-suite'); ?>
        </a>
        <a href="?page=mad-suite-private-store&tab=users" class="nav-tab <?php echo $active_tab === 'users' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-groups"></span>
            <?php _e('Usuarios VIP', 'mad-suite'); ?>
        </a>
        <a href="?page=mad-suite-private-store&tab=products" class="nav-tab <?php echo $active_tab === 'products' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-products"></span>
            <?php _e('Productos', 'mad-suite'); ?>
        </a>
        <a href="?page=mad-suite-private-store&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
            <span class="dashicons dashicons-media-text"></span>
            <?php _e('Logs', 'mad-suite'); ?>
            <?php if (($log_stats['errors'] ?? 0) > 0): ?>
                <span class="mads-ps-badge-error"><?php echo esc_html($log_stats['errors']); ?></span>
            <?php endif; ?>
        </a>
    </nav>
    
    <div class="mads-ps-tab-content">
        
        <?php
        // ==========================================
        // TAB: GENERAL
        // ==========================================
        if ($active_tab === 'general'): ?>
        
        <h2><?php _e('Configuración General', 'mad-suite'); ?></h2>
        
        <form id="mads-ps-general-form">
            <table class="form-table">
                
                <tr>
                    <th scope="row">
                        <label for="role_name">
                            <?php _e('Nombre del rol VIP', 'mad-suite'); ?>
                            <span class="dashicons dashicons-info-outline tooltip" title="<?php esc_attr_e('Este nombre aparecerá en el menú de Mi Cuenta', 'mad-suite'); ?>"></span>
                        </label>
                    </th>
                    <td>
                        <input type="text" id="role_name" name="role_name" value="<?php echo esc_attr($role_name); ?>" class="regular-text">
                        <p class="description">
                            <?php _e('Ejemplo: "Tienda VIP", "Acceso Exclusivo", "Club Premium"', 'mad-suite'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Redirección automática', 'mad-suite'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="redirect_after_login" value="1" <?php checked($redirect_after_login, 1); ?>>
                            <?php _e('Redirigir usuarios VIP a la tienda privada después de iniciar sesión', 'mad-suite'); ?>
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Badges visuales', 'mad-suite'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="show_vip_badge" value="1" <?php checked($show_vip_badge, 1); ?>>
                            <?php _e('Mostrar badges de "Producto VIP" y "Descuento VIP" en productos', 'mad-suite'); ?>
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Sistema de Logs', 'mad-suite'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enable_logging" value="1" <?php checked($enable_logging, 1); ?>>
                            <?php _e('Habilitar sistema de logs', 'mad-suite'); ?>
                        </label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="custom_css"><?php _e('CSS Personalizado', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <textarea id="custom_css" name="custom_css" rows="8" class="large-text code"><?php echo esc_textarea($custom_css); ?></textarea>
                        <p class="description"><?php _e('CSS que se aplicará solo en la tienda privada', 'mad-suite'); ?></p>
                    </td>
                </tr>
                
            </table>
            
            <hr>
            
            <h3><?php _e('Información del Sistema', 'mad-suite'); ?></h3>
            <table class="form-table">
                <tr>
                    <th><?php _e('Endpoint de tienda privada', 'mad-suite'); ?></th>
                    <td>
                        <code><?php echo esc_html(home_url('/my-account/private-store/')); ?></code>
                        <a href="<?php echo esc_url(home_url('/my-account/private-store/')); ?>" target="_blank" class="button button-small">
                            <?php _e('Probar', 'mad-suite'); ?>
                        </a>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Directorio de logs', 'mad-suite'); ?></th>
                    <td><code><?php echo esc_html(wp_upload_dir()['basedir'] . '/mad-suite-logs/'); ?></code></td>
                </tr>
            </table>
            
            <hr>
            
            <h3 class="danger-title">
                <span class="dashicons dashicons-warning"></span>
                <?php _e('Zona Peligrosa', 'mad-suite'); ?>
            </h3>
            <table class="form-table">
                <tr>
                    <th><?php _e('Limpiar datos', 'mad-suite'); ?></th>
                    <td>
                        <button type="button" class="button" id="clear-discounts">
                            <?php _e('Eliminar todos los descuentos', 'mad-suite'); ?>
                        </button>
                        <button type="button" class="button" id="reset-settings">
                            <?php _e('Restablecer configuración', 'mad-suite'); ?>
                        </button>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <button type="submit" class="button button-primary button-hero">
                    <span class="dashicons dashicons-yes"></span>
                    <?php _e('Guardar Configuración', 'mad-suite'); ?>
                </button>
            </p>
        </form>
        
        <?php
        // ==========================================
        // TAB: DESCUENTOS
        // ==========================================
        elseif ($active_tab === 'discounts'): ?>
        
        <div class="mads-ps-discounts-section">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2><?php _e('Gestión de Descuentos VIP', 'mad-suite'); ?></h2>
                <button type="button" class="button button-primary" id="add-discount-btn">
                    <span class="dashicons dashicons-plus-alt"></span>
                    <?php _e('Agregar Descuento', 'mad-suite'); ?>
                </button>
            </div>
            
            <!-- Tabla de descuentos -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Tipo', 'mad-suite'); ?></th>
                        <th><?php _e('Aplica a', 'mad-suite'); ?></th>
                        <th><?php _e('Descuento', 'mad-suite'); ?></th>
                        <th><?php _e('Productos afectados', 'mad-suite'); ?></th>
                        <th><?php _e('Acciones', 'mad-suite'); ?></th>
                    </tr>
                </thead>
                <tbody id="discounts-list">
                    <?php if (empty($discounts)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 40px;">
                                <span class="dashicons dashicons-tag" style="font-size: 48px; color: #ccc;"></span>
                                <p><?php _e('No hay descuentos configurados', 'mad-suite'); ?></p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($discounts as $index => $discount): ?>
                            <tr data-discount-id="<?php echo esc_attr($index); ?>">
                                <td>
                                    <strong>
                                        <?php 
                                        if ($discount['type'] === 'category') {
                                            echo '<span class="dashicons dashicons-category"></span> ' . __('Categoría', 'mad-suite');
                                        } else {
                                            echo '<span class="dashicons dashicons-tag"></span> ' . __('Etiqueta', 'mad-suite');
                                        }
                                        ?>
                                    </strong>
                                </td>
                                <td>
                                    <?php
                                    $term = get_term($discount['target']);
                                    echo $term && !is_wp_error($term) ? esc_html($term->name) : __('(Eliminado)', 'mad-suite');
                                    ?>
                                </td>
                                <td>
                                    <span class="discount-amount">
                                        <?php 
                                        if ($discount['amount_type'] === 'percentage') {
                                            echo esc_html($discount['amount']) . '%';
                                        } else {
                                            echo wc_price($discount['amount']);
                                        }
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $count = 0;
                                    if ($discount['type'] === 'category') {
                                        $count = get_term($discount['target'])->count ?? 0;
                                    } else {
                                        $count = get_term($discount['target'])->count ?? 0;
                                    }
                                    printf(_n('%d producto', '%d productos', $count, 'mad-suite'), $count);
                                    ?>
                                </td>
                                <td>
                                    <button type="button" class="button button-small edit-discount" data-discount-id="<?php echo esc_attr($index); ?>">
                                        <?php _e('Editar', 'mad-suite'); ?>
                                    </button>
                                    <button type="button" class="button button-small button-link-delete delete-discount" data-discount-id="<?php echo esc_attr($index); ?>">
                                        <?php _e('Eliminar', 'mad-suite'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <!-- Modal para agregar/editar descuento -->
        <div id="discount-modal" class="mads-ps-modal" style="display: none;">
            <div class="mads-ps-modal-content">
                <span class="mads-ps-modal-close">&times;</span>
                <h2 id="modal-title"><?php _e('Agregar Descuento', 'mad-suite'); ?></h2>
                
                <form id="discount-form">
                    <input type="hidden" id="discount-id" name="discount_id" value="">
                    
                    <p>
                        <label><?php _e('Tipo de descuento', 'mad-suite'); ?></label>
                        <select name="type" id="discount-type" class="regular-text">
                            <option value="category"><?php _e('Por Categoría', 'mad-suite'); ?></option>
                            <option value="tag"><?php _e('Por Etiqueta', 'mad-suite'); ?></option>
                        </select>
                    </p>
                    
                    <p id="category-select-wrapper">
                        <label><?php _e('Categoría', 'mad-suite'); ?></label>
                        <select name="target_category" id="target-category" class="regular-text">
                            <option value=""><?php _e('Selecciona una categoría', 'mad-suite'); ?></option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo esc_attr($cat->term_id); ?>">
                                    <?php echo esc_html($cat->name); ?> (<?php echo esc_html($cat->count); ?> productos)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    
                    <p id="tag-select-wrapper" style="display: none;">
                        <label><?php _e('Etiqueta', 'mad-suite'); ?></label>
                        <select name="target_tag" id="target-tag" class="regular-text">
                            <option value=""><?php _e('Selecciona una etiqueta', 'mad-suite'); ?></option>
                            <?php foreach ($tags as $tag): ?>
                                <option value="<?php echo esc_attr($tag->term_id); ?>">
                                    <?php echo esc_html($tag->name); ?> (<?php echo esc_html($tag->count); ?> productos)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    
                    <p>
                        <label><?php _e('Cantidad de descuento', 'mad-suite'); ?></label>
                        <div style="display: flex; gap: 10px;">
                            <input type="number" name="amount" id="discount-amount" value="" step="0.01" min="0" style="width: 120px;" required>
                            <select name="amount_type" id="discount-amount-type" style="width: auto;">
                                <option value="percentage">%</option>
                                <option value="fixed"><?php echo get_woocommerce_currency_symbol(); ?></option>
                            </select>
                        </div>
                    </p>
                    
                    <p class="submit">
                        <button type="submit" class="button button-primary">
                            <?php _e('Guardar Descuento', 'mad-suite'); ?>
                        </button>
                        <button type="button" class="button cancel-discount">
                            <?php _e('Cancelar', 'mad-suite'); ?>
                        </button>
                    </p>
                </form>
            </div>
        </div>
        
      <?php
elseif ($active_tab === 'users'): 
    echo "TEST: Tab usuarios cargando...";
    if (file_exists(__DIR__ . '/users-tab.php')) {
        echo "Archivo existe!";
        include __DIR__ . '/users-tab.php';
    } else {
        echo "ERROR: Archivo NO existe en: " . __DIR__ . '/users-tab.php';
    }
?>
        
        <?php
        // ==========================================
        // TAB: PRODUCTOS
        // ==========================================
        elseif ($active_tab === 'products'): ?>
        
        <h2><?php _e('Productos Exclusivos VIP', 'mad-suite'); ?></h2>
        
        <div class="mads-ps-products-info">
            <p><?php printf(__('Hay %d productos exclusivos VIP.', 'mad-suite'), $vip_products_count); ?>
               <a href="<?php echo admin_url('edit.php?post_type=product&vip_visibility=vip_only'); ?>" class="button button-small">
                   <?php _e('Ver todos los productos VIP', 'mad-suite'); ?>
               </a>
            </p>
        </div>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Producto', 'mad-suite'); ?></th>
                    <th><?php _e('Precio', 'mad-suite'); ?></th>
                    <th><?php _e('Stock', 'mad-suite'); ?></th>
                    <th><?php _e('Acciones', 'mad-suite'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vip_products)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 40px;">
                            <span class="dashicons dashicons-products" style="font-size: 48px; color: #ccc;"></span>
                            <p><?php _e('No hay productos exclusivos VIP', 'mad-suite'); ?></p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($vip_products as $product_post): 
                        $product = wc_get_product($product_post->ID);
                        if (!$product) continue;
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($product->get_name()); ?></strong>
                                <br><small>ID: <?php echo esc_html($product->get_id()); ?></small>
                            </td>
                            <td><?php echo $product->get_price_html(); ?></td>
                            <td>
                                <?php 
                                if ($product->is_in_stock()) {
                                    echo '<span style="color: #27ae60;">✓ ' . __('En stock', 'mad-suite') . '</span>';
                                } else {
                                    echo '<span style="color: #e74c3c;">✗ ' . __('Agotado', 'mad-suite') . '</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <a href="<?php echo get_edit_post_link($product->get_id()); ?>" class="button button-small">
                                    <?php _e('Editar', 'mad-suite'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <?php
        // ==========================================
        // TAB: LOGS
        // ==========================================
        elseif ($active_tab === 'logs'): ?>
        
        <h2><?php _e('Sistema de Logs', 'mad-suite'); ?></h2>
        
        <div class="mads-ps-log-stats">
            <div class="log-stat-box">
                <h3><?php _e('Estadísticas del Log Actual', 'mad-suite'); ?></h3>
                <ul>
                    <li><strong><?php _e('Tamaño:', 'mad-suite'); ?></strong> <?php echo esc_html($log_stats['size_formatted'] ?? '0 KB'); ?></li>
                    <li><strong><?php _e('Líneas:', 'mad-suite'); ?></strong> <?php echo esc_html($log_stats['lines'] ?? 0); ?></li>
                    <li class="error-count"><strong><?php _e('Errores:', 'mad-suite'); ?></strong> <?php echo esc_html($log_stats['errors'] ?? 0); ?></li>
                    <li class="warning-count"><strong><?php _e('Advertencias:', 'mad-suite'); ?></strong> <?php echo esc_html($log_stats['warnings'] ?? 0); ?></li>
                    <li class="info-count"><strong><?php _e('Info:', 'mad-suite'); ?></strong> <?php echo esc_html($log_stats['info'] ?? 0); ?></li>
                </ul>
                
                <p>
                    <button type="button" class="button" id="download-log"><?php _e('Descargar Log', 'mad-suite'); ?></button>
                    <button type="button" class="button" id="clear-log"><?php _e('Limpiar Log', 'mad-suite'); ?></button>
                </p>
            </div>
        </div>
        
        <h3><?php _e('Logs Disponibles', 'mad-suite'); ?></h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Archivo', 'mad-suite'); ?></th>
                    <th><?php _e('Fecha', 'mad-suite'); ?></th>
                    <th><?php _e('Tamaño', 'mad-suite'); ?></th>
                    <th><?php _e('Acciones', 'mad-suite'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($available_logs)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 40px;">
                            <span class="dashicons dashicons-media-text" style="font-size: 48px; color: #ccc;"></span>
                            <p><?php _e('No hay logs disponibles', 'mad-suite'); ?></p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($available_logs as $log): ?>
                        <tr>
                            <td><code><?php echo esc_html($log['filename']); ?></code></td>
                            <td><?php echo esc_html($log['date_formatted']); ?></td>
                            <td><?php echo esc_html($log['size_formatted']); ?></td>
                            <td>
                                <a href="<?php echo esc_url($log['url']); ?>" target="_blank" class="button button-small">
                                    <?php _e('Ver', 'mad-suite'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        
        <h3><?php _e('Últimas Entradas', 'mad-suite'); ?></h3>
        <div class="mads-ps-log-viewer">
            <pre><?php echo esc_html($logger->read_last_lines(50)); ?></pre>
        </div>
        
        <?php endif; ?>
        
    </div>
</div>

<?php include __DIR__ . '/settings-style.php'; ?>
<?php include __DIR__ . '/settings-scripts.php'; ?>