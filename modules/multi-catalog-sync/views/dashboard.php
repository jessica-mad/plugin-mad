<?php
/**
 * Dashboard View
 * Displays sync status for all destinations
 */

if ( ! defined('ABSPATH') ) exit;

$settings = $this->get_settings();

// Get sync statistics
$total_products = wp_count_posts('product')->publish;
$total_variations = $this->count_variations();
$synced_count = $this->get_synced_count();
$excluded_count = $this->get_excluded_count();
$error_count = $this->get_error_count();

$last_sync = get_option('mcs_last_full_sync', 0);
$next_sync = $this->get_next_scheduled_sync();
?>

<div class="mcs-dashboard">
    <!-- Summary Section -->
    <div class="mcs-summary">
        <h3><?php esc_html_e('Resumen General', 'mad-suite'); ?></h3>

        <div class="mcs-summary-grid">
            <div class="mcs-stat">
                <span class="mcs-stat-value" data-stat="total_products"><?php echo esc_html($total_products); ?></span>
                <span class="mcs-stat-label"><?php esc_html_e('Productos Totales', 'mad-suite'); ?></span>
            </div>

            <div class="mcs-stat">
                <span class="mcs-stat-value" data-stat="total_variations"><?php echo esc_html($total_variations); ?></span>
                <span class="mcs-stat-label"><?php esc_html_e('Variaciones Totales', 'mad-suite'); ?></span>
            </div>

            <div class="mcs-stat">
                <span class="mcs-stat-value" data-stat="synced_count"><?php echo esc_html($synced_count); ?></span>
                <span class="mcs-stat-label"><?php esc_html_e('Sincronizados', 'mad-suite'); ?></span>
            </div>

            <div class="mcs-stat">
                <span class="mcs-stat-value" data-stat="excluded_count"><?php echo esc_html($excluded_count); ?></span>
                <span class="mcs-stat-label"><?php esc_html_e('Excluidos', 'mad-suite'); ?></span>
            </div>

            <?php if ($error_count > 0): ?>
            <div class="mcs-stat">
                <span class="mcs-stat-value" style="color: #d63638;" data-stat="error_count"><?php echo esc_html($error_count); ?></span>
                <span class="mcs-stat-label"><?php esc_html_e('Con Errores', 'mad-suite'); ?></span>
            </div>
            <?php endif; ?>
        </div>

        <p style="margin-top: 15px; margin-bottom: 0;">
            <strong><?php esc_html_e('Última sincronización:', 'mad-suite'); ?></strong>
            <span class="mcs-last-sync">
                <?php
                if ($last_sync) {
                    echo esc_html(sprintf(
                        __('Hace %s', 'mad-suite'),
                        human_time_diff($last_sync, current_time('timestamp'))
                    ));
                } else {
                    esc_html_e('Nunca', 'mad-suite');
                }
                ?>
            </span>
            <br>
            <strong><?php esc_html_e('Próxima sincronización:', 'mad-suite'); ?></strong>
            <?php
            if ($next_sync) {
                echo esc_html(sprintf(
                    __('En %s', 'mad-suite'),
                    human_time_diff(current_time('timestamp'), $next_sync)
                ));
            } else {
                esc_html_e('No programada', 'mad-suite');
            }
            ?>
        </p>
    </div>

    <!-- Destination Cards -->
    <div class="mcs-destination-cards">
        <!-- Google Merchant Center -->
        <?php if ($settings['google_enabled']): ?>
        <div class="mcs-card" data-destination="google">
            <div class="mcs-card-header">
                <h3 class="mcs-card-title">Google Merchant Center</h3>
                <span class="mcs-card-status <?php echo $this->is_google_connected() ? 'connected' : 'disconnected'; ?>">
                    <?php echo $this->is_google_connected() ? __('Conectado', 'mad-suite') : __('Desconectado', 'mad-suite'); ?>
                </span>
            </div>

            <div class="mcs-card-stats">
                <div class="mcs-stat">
                    <span class="mcs-stat-value"><?php echo esc_html($this->get_destination_item_count('google')); ?></span>
                    <span class="mcs-stat-label"><?php esc_html_e('Items', 'mad-suite'); ?></span>
                </div>
                <div class="mcs-stat">
                    <span class="mcs-stat-value" style="color: #d63638;"><?php echo esc_html($this->get_destination_error_count('google')); ?></span>
                    <span class="mcs-stat-label"><?php esc_html_e('Errores', 'mad-suite'); ?></span>
                </div>
            </div>

            <div class="mcs-card-actions">
                <button class="button button-primary mcs-manual-sync" data-destination="google" data-original-text="<?php esc_attr_e('Sincronizar Ahora', 'mad-suite'); ?>">
                    <?php esc_html_e('Sincronizar Ahora', 'mad-suite'); ?>
                </button>
                <a href="https://merchants.google.com/mc/products?a=<?php echo esc_attr($settings['google_merchant_id']); ?>" class="button" target="_blank">
                    <?php esc_html_e('Ver en GMC', 'mad-suite'); ?>
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="mcs-card" data-destination="google">
            <div class="mcs-card-header">
                <h3 class="mcs-card-title">Google Merchant Center</h3>
                <span class="mcs-card-status disconnected"><?php esc_html_e('Deshabilitado', 'mad-suite'); ?></span>
            </div>
            <p><?php esc_html_e('Habilita Google Merchant Center en la configuración para comenzar a sincronizar.', 'mad-suite'); ?></p>
            <a href="#tab-google" class="button nav-tab-link"><?php esc_html_e('Configurar', 'mad-suite'); ?></a>
        </div>
        <?php endif; ?>

        <!-- Facebook Catalog -->
        <?php if ($settings['facebook_enabled']): ?>
        <div class="mcs-card" data-destination="facebook">
            <div class="mcs-card-header">
                <h3 class="mcs-card-title">Facebook Catalog</h3>
                <span class="mcs-card-status <?php echo $this->is_facebook_connected() ? 'connected' : 'disconnected'; ?>">
                    <?php echo $this->is_facebook_connected() ? __('Conectado', 'mad-suite') : __('Desconectado', 'mad-suite'); ?>
                </span>
            </div>

            <div class="mcs-card-stats">
                <div class="mcs-stat">
                    <span class="mcs-stat-value"><?php echo esc_html($this->get_destination_item_count('facebook')); ?></span>
                    <span class="mcs-stat-label"><?php esc_html_e('Items', 'mad-suite'); ?></span>
                </div>
                <div class="mcs-stat">
                    <span class="mcs-stat-value" style="color: #d63638;"><?php echo esc_html($this->get_destination_error_count('facebook')); ?></span>
                    <span class="mcs-stat-label"><?php esc_html_e('Errores', 'mad-suite'); ?></span>
                </div>
            </div>

            <div class="mcs-card-actions">
                <button class="button button-primary mcs-manual-sync" data-destination="facebook" data-original-text="<?php esc_attr_e('Sincronizar Ahora', 'mad-suite'); ?>">
                    <?php esc_html_e('Sincronizar Ahora', 'mad-suite'); ?>
                </button>
                <a href="https://business.facebook.com/commerce/catalogs/<?php echo esc_attr($settings['facebook_catalog_id']); ?>" class="button" target="_blank">
                    <?php esc_html_e('Ver en Facebook', 'mad-suite'); ?>
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="mcs-card" data-destination="facebook">
            <div class="mcs-card-header">
                <h3 class="mcs-card-title">Facebook Catalog</h3>
                <span class="mcs-card-status disconnected"><?php esc_html_e('Deshabilitado', 'mad-suite'); ?></span>
            </div>
            <p><?php esc_html_e('Habilita Facebook Catalog en la configuración para comenzar a sincronizar.', 'mad-suite'); ?></p>
            <a href="#tab-facebook" class="button nav-tab-link"><?php esc_html_e('Configurar', 'mad-suite'); ?></a>
        </div>
        <?php endif; ?>

        <!-- Pinterest Catalog -->
        <?php if ($settings['pinterest_enabled']): ?>
        <div class="mcs-card" data-destination="pinterest">
            <div class="mcs-card-header">
                <h3 class="mcs-card-title">Pinterest Catalog</h3>
                <span class="mcs-card-status <?php echo $this->is_pinterest_connected() ? 'connected' : 'disconnected'; ?>">
                    <?php echo $this->is_pinterest_connected() ? __('Conectado', 'mad-suite') : __('Desconectado', 'mad-suite'); ?>
                </span>
            </div>

            <div class="mcs-card-stats">
                <div class="mcs-stat">
                    <span class="mcs-stat-value"><?php echo esc_html($this->get_destination_item_count('pinterest')); ?></span>
                    <span class="mcs-stat-label"><?php esc_html_e('Items', 'mad-suite'); ?></span>
                </div>
                <div class="mcs-stat">
                    <span class="mcs-stat-value" style="color: #d63638;"><?php echo esc_html($this->get_destination_error_count('pinterest')); ?></span>
                    <span class="mcs-stat-label"><?php esc_html_e('Errores', 'mad-suite'); ?></span>
                </div>
            </div>

            <div class="mcs-card-actions">
                <button class="button button-primary mcs-manual-sync" data-destination="pinterest" data-original-text="<?php esc_attr_e('Sincronizar Ahora', 'mad-suite'); ?>">
                    <?php esc_html_e('Sincronizar Ahora', 'mad-suite'); ?>
                </button>
                <a href="https://www.pinterest.com/business/catalogs/<?php echo esc_attr($settings['pinterest_catalog_id']); ?>" class="button" target="_blank">
                    <?php esc_html_e('Ver en Pinterest', 'mad-suite'); ?>
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="mcs-card" data-destination="pinterest">
            <div class="mcs-card-header">
                <h3 class="mcs-card-title">Pinterest Catalog</h3>
                <span class="mcs-card-status disconnected"><?php esc_html_e('Deshabilitado', 'mad-suite'); ?></span>
            </div>
            <p><?php esc_html_e('Habilita Pinterest Catalog en la configuración para comenzar a sincronizar.', 'mad-suite'); ?></p>
            <a href="#tab-pinterest" class="button nav-tab-link"><?php esc_html_e('Configurar', 'mad-suite'); ?></a>
        </div>
        <?php endif; ?>
    </div>

    <!-- Errors Section -->
    <?php
    $errors = $this->get_recent_errors();
    if (!empty($errors)):
    ?>
    <div class="mcs-errors">
        <h3><?php esc_html_e('⚠️ Productos con errores', 'mad-suite'); ?></h3>
        <ul class="mcs-error-list">
            <?php foreach (array_slice($errors, 0, 5) as $error): ?>
            <li>
                <strong><?php echo esc_html($error['product_name']); ?></strong> -
                <?php echo esc_html($error['message']); ?>
                <a href="<?php echo esc_url(get_edit_post_link($error['product_id'])); ?>"><?php esc_html_e('Editar', 'mad-suite'); ?></a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($errors) > 5): ?>
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=mad-multi-catalog-sync&tab=errors')); ?>">
                <?php printf(esc_html__('Ver todos los errores (%d) →', 'mad-suite'), count($errors)); ?>
            </a>
        </p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Actions -->
    <div style="margin-top: 30px;">
        <button class="button button-large button-primary mcs-sync-all" style="margin-right: 10px;">
            <?php esc_html_e('🔄 Sincronizar Todo Ahora', 'mad-suite'); ?>
        </button>
        <a href="<?php echo esc_url(admin_url('admin.php?page=wc-status&tab=logs&log_file=' . $this->get_log_file_name())); ?>" class="button button-large">
            <?php esc_html_e('📥 Ver Logs', 'mad-suite'); ?>
        </a>
    </div>
</div>

<script type="text/javascript">
jQuery(document).ready(function($) {
    // Handle "Configure" links in dashboard
    $('.nav-tab-link').on('click', function(e) {
        e.preventDefault();
        const targetTab = $(this).attr('href');
        $('.mcs-tabs .nav-tab[href="' + targetTab + '"]').trigger('click');
    });

    // Handle "Sync All" button
    $('.mcs-sync-all').on('click', function(e) {
        e.preventDefault();

        const $button = $(this);
        if ($button.prop('disabled')) return;

        if (!confirm('<?php esc_html_e('¿Estás seguro de que deseas sincronizar todos los productos? Esto puede tardar varios minutos.', 'mad-suite'); ?>')) {
            return;
        }

        $button.prop('disabled', true);
        $button.html('<span class="mcs-loading"></span> <?php esc_html_e('Sincronizando...', 'mad-suite'); ?>');

        // Trigger sync for all enabled destinations
        $('.mcs-manual-sync:not(:disabled)').each(function() {
            $(this).trigger('click');
        });

        setTimeout(function() {
            $button.prop('disabled', false);
            $button.html('🔄 <?php esc_html_e('Sincronizar Todo Ahora', 'mad-suite'); ?>');
        }, 2000);
    });
});
</script>
