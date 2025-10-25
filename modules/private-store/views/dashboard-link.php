<?php
/**
 * Dashboard Link - Vista para usuarios VIP en Mi Cuenta
 * 
 * Muestra información de la tienda privada y redirige a la tienda
 *
 * @package MAD_Suite
 * @subpackage Private_Store
 */

if (!defined('ABSPATH')) {
    exit;
}

use MAD_Suite\Modules\PrivateStore\UserRole;
use MAD_Suite\Modules\PrivateStore\ProductVisibility;
use MAD_Suite\Modules\PrivateStore\Logger;

// Verificar acceso
if (!UserRole::instance()->is_vip_user()) {
    echo '<div class="woocommerce-error">' . __('No tienes acceso a esta sección.', 'mad-suite') . '</div>';
    return;
}

// Log de acceso
$logger = new Logger('private-store');
$logger->info('Usuario VIP accedió al dashboard de tienda privada');

// Obtener configuración
$role_name = get_option('mads_ps_role_name', __('Tienda VIP', 'mad-suite'));

// Estadísticas del usuario (opcional - puedes agregar más)
$user_id = get_current_user_id();
$customer = new WC_Customer($user_id);

// Contar productos VIP disponibles
$vip_products_count = ProductVisibility::instance()->count_vip_products();

// URL de la tienda privada
$shop_url = add_query_arg('private_store', '1', wc_get_page_permalink('shop'));

?>

<div class="mads-ps-dashboard-welcome">
    
    <!-- Header con bienvenida -->
    <div class="mads-ps-welcome-header">
        <div class="welcome-icon">
            <span class="dashicons dashicons-star-filled"></span>
        </div>
        <div class="welcome-content">
            <h2><?php printf(__('¡Bienvenido a %s!', 'mad-suite'), esc_html($role_name)); ?></h2>
            <p><?php _e('Como cliente VIP tienes acceso a productos exclusivos y descuentos especiales.', 'mad-suite'); ?></p>
        </div>
    </div>
    
    <!-- Beneficios -->
    <div class="mads-ps-benefits">
        <h3><?php _e('Tus beneficios VIP', 'mad-suite'); ?></h3>
        
        <div class="benefits-grid">
            
            <div class="benefit-card">
                <span class="dashicons dashicons-products"></span>
                <h4><?php _e('Productos Exclusivos', 'mad-suite'); ?></h4>
                <p><?php printf(_n('Acceso a %d producto exclusivo', 'Acceso a %d productos exclusivos', $vip_products_count, 'mad-suite'), $vip_products_count); ?></p>
            </div>
            
            <div class="benefit-card">
                <span class="dashicons dashicons-tag"></span>
                <h4><?php _e('Descuentos Especiales', 'mad-suite'); ?></h4>
                <p><?php _e('Precios preferenciales en productos seleccionados', 'mad-suite'); ?></p>
            </div>
            
            <div class="benefit-card">
                <span class="dashicons dashicons-visibility"></span>
                <h4><?php _e('Acceso Anticipado', 'mad-suite'); ?></h4>
                <p><?php _e('Ve productos antes que nadie', 'mad-suite'); ?></p>
            </div>
            
            <div class="benefit-card">
                <span class="dashicons dashicons-awards"></span>
                <h4><?php _e('Prioridad VIP', 'mad-suite'); ?></h4>
                <p><?php _e('Atención prioritaria en tus pedidos', 'mad-suite'); ?></p>
            </div>
            
        </div>
    </div>
    
    <!-- Botón principal -->
    <div class="mads-ps-cta">
        <a href="<?php echo esc_url($shop_url); ?>" class="button button-vip">
            <span class="dashicons dashicons-cart"></span>
            <?php _e('Ir a la Tienda VIP', 'mad-suite'); ?>
        </a>
        
        <p class="cta-note">
            <?php _e('Al hacer clic accederás a la tienda con tus precios y productos exclusivos.', 'mad-suite'); ?>
        </p>
    </div>
    
    <!-- Información adicional -->
    <div class="mads-ps-info-box">
        <h4><?php _e('¿Necesitas ayuda?', 'mad-suite'); ?></h4>
        <p><?php _e('Si tienes alguna pregunta sobre tus beneficios VIP o productos exclusivos, no dudes en contactarnos.', 'mad-suite'); ?></p>
        
        <?php if (get_option('woocommerce_shop_page_id')): ?>
            <a href="<?php echo get_permalink(get_option('woocommerce_myaccount_page_id')); ?>" class="button button-secondary">
                <?php _e('Ir a Mi Cuenta', 'mad-suite'); ?>
            </a>
        <?php endif; ?>
    </div>
    
</div>

<style>
.mads-ps-dashboard-welcome {
    max-width: 800px;
    margin: 0 auto;
}

/* Header */
.mads-ps-welcome-header {
    background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
    padding: 40px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 30px;
    margin-bottom: 40px;
    box-shadow: 0 4px 15px rgba(255, 215, 0, 0.3);
}

.welcome-icon {
    flex-shrink: 0;
}

.welcome-icon .dashicons {
    font-size: 80px;
    width: 80px;
    height: 80px;
    color: #fff;
    filter: drop-shadow(0 2px 4px rgba(0,0,0,0.2));
}

.welcome-content h2 {
    margin: 0 0 10px 0;
    color: #fff;
    font-size: 28px;
    text-shadow: 0 2px 4px rgba(0,0,0,0.2);
}

.welcome-content p {
    margin: 0;
    color: rgba(255,255,255,0.95);
    font-size: 16px;
}

/* Beneficios */
.mads-ps-benefits {
    margin-bottom: 40px;
}

.mads-ps-benefits h3 {
    font-size: 22px;
    margin-bottom: 20px;
    color: #333;
}

.benefits-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 20px;
}

.benefit-card {
    background: #fff;
    padding: 25px;
    border-radius: 8px;
    text-align: center;
    border: 2px solid #f0f0f1;
    transition: all 0.3s ease;
}

.benefit-card:hover {
    border-color: #FFD700;
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(255, 215, 0, 0.2);
}

.benefit-card .dashicons {
    font-size: 48px;
    width: 48px;
    height: 48px;
    color: #FFD700;
    margin-bottom: 15px;
}

.benefit-card h4 {
    font-size: 16px;
    margin: 0 0 10px 0;
    color: #333;
}

.benefit-card p {
    font-size: 13px;
    color: #666;
    margin: 0;
    line-height: 1.5;
}

/* CTA */
.mads-ps-cta {
    text-align: center;
    margin-bottom: 40px;
    padding: 30px;
    background: #f9f9f9;
    border-radius: 8px;
}

.button-vip {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    background: linear-gradient(135deg, #FFD700 0%, #FFA500 100%);
    color: #000;
    font-size: 18px;
    font-weight: bold;
    padding: 15px 40px;
    border-radius: 50px;
    text-decoration: none;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(255, 215, 0, 0.4);
    border: none;
}

.button-vip:hover {
    background: linear-gradient(135deg, #FFA500 0%, #FFD700 100%);
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(255, 215, 0, 0.5);
    color: #000;
}

.button-vip .dashicons {
    font-size: 24px;
    width: 24px;
    height: 24px;
}

.cta-note {
    margin-top: 15px;
    font-size: 13px;
    color: #666;
}

/* Info box */
.mads-ps-info-box {
    background: #e7f3ff;
    border-left: 4px solid #2196F3;
    padding: 20px;
    border-radius: 4px;
}

.mads-ps-info-box h4 {
    margin: 0 0 10px 0;
    color: #2196F3;
}

.mads-ps-info-box p {
    margin: 0 0 15px 0;
    color: #666;
    line-height: 1.6;
}

.button-secondary {
    background: #fff;
    color: #2196F3;
    border: 2px solid #2196F3;
    padding: 10px 20px;
    border-radius: 4px;
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s ease;
}

.button-secondary:hover {
    background: #2196F3;
    color: #fff;
}

/* Responsive */
@media (max-width: 768px) {
    .mads-ps-welcome-header {
        flex-direction: column;
        text-align: center;
        padding: 30px 20px;
    }
    
    .welcome-icon .dashicons {
        font-size: 60px;
        width: 60px;
        height: 60px;
    }
    
    .welcome-content h2 {
        font-size: 24px;
    }
    
    .benefits-grid {
        grid-template-columns: 1fr;
    }
    
    .button-vip {
        font-size: 16px;
        padding: 12px 30px;
    }
}
</style>