<?php
/**
 * Users Tab - Gestión avanzada de usuarios VIP
 * 
 * Incluye importación CSV, selector múltiple y exportación
 *
 * @package MAD_Suite
 * @subpackage Private_Store
 */

if (!defined('ABSPATH')) {
    exit;
}

use MAD_Suite\Modules\PrivateStore\UserRole;
use MAD_Suite\Modules\PrivateStore\Logger;

// Variables
$vip_count = UserRole::instance()->count_vip_users();
$vip_users = UserRole::instance()->get_vip_users(['number' => 50]);

add_action('admin_enqueue_scripts', function($hook) {
    // Solo cargar en nuestra página del módulo
    $page  = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
    $tab   = isset($_GET['tab'])  ? sanitize_text_field($_GET['tab'])  : '';

    if ($page !== 'mads-private-store') {
        return;
    }

    // CSS/JS comunes del admin del módulo
    wp_enqueue_style(
        'mads-private-store-admin',
        $this->module_url . 'assets/admin.css',
        [],
        MAD_SUITE_VERSION
    );

    wp_enqueue_script(
        'mads-private-store-admin',
        $this->module_url . 'assets/admin.js',
        ['jquery', 'wp-util'],
        MAD_SUITE_VERSION,
        true
    );

    // Solo en la pestaña de usuarios, carga users-tab.js
    if ($tab === 'users') {
        wp_enqueue_script(
            'mads-private-store-users-tab',
            $this->module_url . 'assets/users-tab.js',
            ['jquery', 'wp-util'],
            MAD_SUITE_VERSION,
            true
        );

        wp_localize_script('mads-private-store-users-tab', 'madsPrivateStore', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('mads_private_store'),
            'i18n'    => [
                'importing' => __('Importando…', 'mad-suite'),
                'done'      => __('Listo', 'mad-suite'),
            ],
        ]);
    }
});


?>

<div class="mads-ps-users-tab">
    
    <!-- Header con acciones -->
    <div class="users-header">
        <h2><?php _e('Gestión de Usuarios VIP', 'mad-suite'); ?></h2>
        
        <div class="users-actions">
            <button type="button" class="button" id="add-users-btn">
                <span class="dashicons dashicons-plus"></span>
                <?php _e('Agregar Usuarios', 'mad-suite'); ?>
            </button>
            
            <button type="button" class="button" id="import-csv-btn">
                <span class="dashicons dashicons-upload"></span>
                <?php _e('Importar CSV', 'mad-suite'); ?>
            </button>
            
            <button type="button" class="button" id="export-csv-btn">
                <span class="dashicons dashicons-download"></span>
                <?php _e('Exportar CSV', 'mad-suite'); ?>
            </button>
        </div>
    </div>
    
    <!-- Info box -->
    <div class="mads-ps-users-info">
        <p>
            <span class="dashicons dashicons-info"></span>
            <?php printf(__('Hay %d usuarios VIP activos.', 'mad-suite'), $vip_count); ?>
            <a href="<?php echo admin_url('users.php'); ?>" class="button button-small">
                <?php _e('Ver todos los usuarios', 'mad-suite'); ?>
            </a>
        </p>
    </div>
    
    <!-- Tabla de usuarios VIP -->
    <table class="wp-list-table widefat fixed striped users-vip-table">
        <thead>
            <tr>
                <th class="column-avatar"><?php _e('Avatar', 'mad-suite'); ?></th>
                <th class="column-user"><?php _e('Usuario', 'mad-suite'); ?></th>
                <th class="column-email"><?php _e('Email', 'mad-suite'); ?></th>
                <th class="column-vip-since"><?php _e('VIP desde', 'mad-suite'); ?></th>
                <th class="column-registered"><?php _e('Registro', 'mad-suite'); ?></th>
                <th class="column-actions"><?php _e('Acciones', 'mad-suite'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($vip_users)): ?>
                <tr>
                    <td colspan="6" class="empty-state">
                        <span class="dashicons dashicons-star-empty"></span>
                        <p><?php _e('No hay usuarios VIP todavía', 'mad-suite'); ?></p>
                        <button type="button" class="button button-primary" id="add-first-user-btn">
                            <?php _e('Agregar primer usuario VIP', 'mad-suite'); ?>
                        </button>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($vip_users as $user): 
                    $vip_since = get_user_meta($user->ID, '_mads_ps_vip_since', true);
                ?>
                    <tr data-user-id="<?php echo esc_attr($user->ID); ?>">
                        <td class="column-avatar">
                            <?php echo get_avatar($user->ID, 40); ?>
                        </td>
                        <td class="column-user">
                            <strong><?php echo esc_html($user->display_name); ?></strong>
                            <br><small class="username">@<?php echo esc_html($user->user_login); ?></small>
                        </td>
                        <td class="column-email">
                            <a href="mailto:<?php echo esc_attr($user->user_email); ?>">
                                <?php echo esc_html($user->user_email); ?>
                            </a>
                        </td>
                        <td class="column-vip-since">
                            <?php if ($vip_since): ?>
                                <span class="vip-badge">
                                    <span class="dashicons dashicons-star-filled"></span>
                                    <?php echo date_i18n(get_option('date_format'), $vip_since); ?>
                                </span>
                            <?php else: ?>
                                <span class="no-date">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="column-registered">
                            <?php echo date_i18n(get_option('date_format'), strtotime($user->user_registered)); ?>
                        </td>
                        <td class="column-actions">
                            <a href="<?php echo admin_url('user-edit.php?user_id=' . $user->ID); ?>" 
                               class="button button-small" 
                               title="<?php esc_attr_e('Editar usuario', 'mad-suite'); ?>">
                                <span class="dashicons dashicons-edit"></span>
                            </a>
                            <button type="button" 
                                    class="button button-small button-link-delete remove-vip-user" 
                                    data-user-id="<?php echo esc_attr($user->ID); ?>"
                                    data-username="<?php echo esc_attr($user->display_name); ?>"
                                    title="<?php esc_attr_e('Quitar acceso VIP', 'mad-suite'); ?>">
                                <span class="dashicons dashicons-dismiss"></span>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    
    <?php if (count($vip_users) >= 50): ?>
        <div class="users-footer">
            <p><?php _e('Mostrando los primeros 50 usuarios VIP.', 'mad-suite'); ?>
               <a href="<?php echo admin_url('users.php?role=vip_customer'); ?>">
                   <?php _e('Ver todos', 'mad-suite'); ?>
               </a>
            </p>
        </div>
    <?php endif; ?>
    
</div>

<!-- Modal: Agregar usuarios -->
<div id="add-users-modal" class="mads-ps-modal" style="display: none;">
    <div class="mads-ps-modal-content">
        <span class="mads-ps-modal-close">&times;</span>
        <h2><?php _e('Agregar Usuarios VIP', 'mad-suite'); ?></h2>
        
        <div class="modal-body">
            <p class="description">
                <?php _e('Busca y selecciona los usuarios que deseas convertir en VIP', 'mad-suite'); ?>
            </p>
            
            <div class="user-search-wrapper">
                <input type="text" 
                       id="user-search-input" 
                       class="regular-text" 
                       placeholder="<?php esc_attr_e('Buscar por nombre, usuario o email...', 'mad-suite'); ?>">
                <span class="search-loading" style="display: none;">
                    <span class="dashicons dashicons-update-alt spin"></span>
                </span>
            </div>
            
            <div id="user-search-results" class="user-search-results"></div>
            
            <div class="selected-users-wrapper" style="display: none;">
                <h4><?php _e('Usuarios seleccionados:', 'mad-suite'); ?></h4>
                <div id="selected-users-list" class="selected-users-list"></div>
            </div>
        </div>
        
        <div class="modal-footer">
            <button type="button" class="button button-primary" id="confirm-add-users" disabled>
                <?php _e('Agregar Usuarios VIP', 'mad-suite'); ?>
            </button>
            <button type="button" class="button cancel-modal">
                <?php _e('Cancelar', 'mad-suite'); ?>
            </button>
        </div>
    </div>
</div>

<!-- Modal: Importar CSV -->
<div id="import-csv-modal" class="mads-ps-modal" style="display: none;">
    <div class="mads-ps-modal-content">
        <span class="mads-ps-modal-close">&times;</span>
        <h2><?php _e('Importar Usuarios desde CSV', 'mad-suite'); ?></h2>
        
        <div class="modal-body">
            <div class="csv-instructions">
                <h4><?php _e('Formato del archivo CSV:', 'mad-suite'); ?></h4>
                <ul>
                    <li><?php _e('Primera columna: Email o nombre de usuario', 'mad-suite'); ?></li>
                    <li><?php _e('Una línea por usuario', 'mad-suite'); ?></li>
                    <li><?php _e('La primera fila se considera encabezado y se ignora', 'mad-suite'); ?></li>
                </ul>
                
                <div class="csv-example">
                    <strong><?php _e('Ejemplo:', 'mad-suite'); ?></strong>
                    <pre>email
usuario1@ejemplo.com
usuario2@ejemplo.com
nombreusuario3</pre>
                </div>
                
                <a href="#" id="download-csv-template" class="button button-small">
                    <span class="dashicons dashicons-download"></span>
                    <?php _e('Descargar plantilla CSV', 'mad-suite'); ?>
                </a>
            </div>
            
            <div class="csv-upload-wrapper">
                <input type="file" 
                       id="csv-file-input" 
                       accept=".csv" 
                       style="display: none;">
                <button type="button" class="button button-large" id="select-csv-file">
                    <span class="dashicons dashicons-upload"></span>
                    <?php _e('Seleccionar archivo CSV', 'mad-suite'); ?>
                </button>
                <p id="selected-file-name" style="display: none; margin-top: 10px;"></p>
            </div>
            
            <div id="csv-import-results" style="display: none; margin-top: 20px;"></div>
        </div>
        
        <div class="modal-footer">
            <button type="button" class="button button-primary" id="confirm-import-csv" disabled>
                <?php _e('Importar Usuarios', 'mad-suite'); ?>
            </button>
            <button type="button" class="button cancel-modal">
                <?php _e('Cancelar', 'mad-suite'); ?>
            </button>
        </div>
    </div>
</div>

<style>
/* ==========================================
   USERS TAB STYLES
   ========================================== */

.mads-ps-users-tab {
    margin: 20px 0;
}

/* Header */
.users-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f0f0f1;
}

.users-header h2 {
    margin: 0;
}

.users-actions {
    display: flex;
    gap: 10px;
}

/* Info box */
.mads-ps-users-info {
    background: #e7f3ff;
    border-left: 4px solid #2196F3;
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 4px;
}

.mads-ps-users-info p {
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.mads-ps-users-info .dashicons {
    color: #2196F3;
}

/* Tabla */
.users-vip-table {
    border: 1px solid #ddd;
    border-radius: 8px;
    overflow: hidden;
}

.users-vip-table thead {
    background: #f9f9f9;
}

.users-vip-table th {
    font-weight: 600;
    text-transform: uppercase;
    font-size: 11px;
    letter-spacing: 0.5px;
    padding: 12px;
}

.users-vip-table td {
    padding: 12px;
    vertical-align: middle;
}

/* Columnas */
.column-avatar {
    width: 60px;
    text-align: center;
}

.column-avatar img {
    border-radius: 50%;
}

.column-user {
    width: auto;
}

.column-user .username {
    color: #666;
    font-size: 12px;
}

.column-email {
    width: 250px;
}

.column-vip-since,
.column-registered {
    width: 140px;
}

.column-actions {
    width: 100px;
    text-align: right;
}

/* VIP Badge */
.vip-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #fff3cd;
    color: #856404;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.vip-badge .dashicons {
    color: #FFD700;
    font-size: 14px;
    width: 14px;
    height: 14px;
}

.no-date {
    color: #ccc;
}

/* Empty state */
.empty-state {
    text-align: center;
    padding: 60px 20px !important;
}

.empty-state .dashicons {
    font-size: 64px;
    width: 64px;
    height: 64px;
    color: #ccc;
    margin-bottom: 15px;
}

.empty-state p {
    color: #666;
    font-size: 16px;
    margin: 0 0 20px 0;
}

/* Actions */
.column-actions .button-small {
    padding: 4px 8px;
    margin-right: 5px;
}

.column-actions .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

/* Footer */
.users-footer {
    text-align: center;
    padding: 15px;
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-top: none;
    border-radius: 0 0 8px 8px;
}

.users-footer p {
    margin: 0;
}

/* ==========================================
   MODAL ESPECÍFICO
   ========================================== */

.modal-body {
    min-height: 200px;
    max-height: 500px;
    overflow-y: auto;
}

.modal-footer {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #f0f0f1;
    text-align: right;
}

/* User search */
.user-search-wrapper {
    position: relative;
    margin-bottom: 15px;
}

.user-search-wrapper input {
    width: 100%;
    padding-right: 40px;
}

.search-loading {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
}

.spin {
    animation: spin 1s linear infinite;
}

@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Search results */
.user-search-results {
    margin-top: 15px;
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.user-result-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px;
    border-bottom: 1px solid #f0f0f1;
    cursor: pointer;
    transition: background 0.2s ease;
}

.user-result-item:hover {
    background: #f9f9f9;
}

.user-result-item:last-child {
    border-bottom: none;
}

.user-result-item.is-vip {
    background: #fff3cd;
    opacity: 0.6;
    cursor: not-allowed;
}

.user-result-item img {
    border-radius: 50%;
}

.user-result-info {
    flex: 1;
}

.user-result-name {
    font-weight: 600;
    margin-bottom: 3px;
}

.user-result-email {
    font-size: 12px;
    color: #666;
}

.user-result-status {
    font-size: 11px;
    color: #999;
}

.user-result-status.is-vip {
    color: #856404;
}

/* Selected users */
.selected-users-wrapper {
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #f0f0f1;
}

.selected-users-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}

.selected-user-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #2196F3;
    color: #fff;
    padding: 5px 10px;
    border-radius: 16px;
    font-size: 12px;
}

.selected-user-chip .remove-chip {
    cursor: pointer;
    font-size: 14px;
    line-height: 1;
    opacity: 0.8;
    transition: opacity 0.2s;
}

.selected-user-chip .remove-chip:hover {
    opacity: 1;
}

/* CSV */
.csv-instructions {
    background: #f9f9f9;
    padding: 15px;
    border-radius: 4px;
    margin-bottom: 20px;
}

.csv-instructions h4 {
    margin-top: 0;
}

.csv-instructions ul {
    margin: 10px 0;
}

.csv-example {
    margin: 15px 0;
}

.csv-example pre {
    background: #fff;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 12px;
    font-family: monospace;
}

.csv-upload-wrapper {
    text-align: center;
    padding: 30px;
    border: 2px dashed #ddd;
    border-radius: 8px;
    margin-top: 20px;
}

#selected-file-name {
    font-weight: 600;
    color: #2196F3;
}

#csv-import-results {
    background: #f9f9f9;
    padding: 15px;
    border-radius: 4px;
    border: 1px solid #ddd;
}

.import-result-success {
    color: #27ae60;
}

.import-result-error {
    color: #e74c3c;
}

.import-result-skipped {
    color: #f39c12;
}

/* Responsive */
@media (max-width: 782px) {
    .users-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 15px;
    }
    
    .users-actions {
        width: 100%;
        flex-direction: column;
    }
    
    .users-actions .button {
        width: 100%;
        justify-content: center;
    }
}
</style>