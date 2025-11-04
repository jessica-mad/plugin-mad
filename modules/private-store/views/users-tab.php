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
            <?php if ($vip_count > 0): ?>
               <a href="<?php echo admin_url('users.php?role=vip_customer'); ?>">
                   <?php _e('Ver todos', 'mad-suite'); ?>
               </a>
            <?php endif; ?>
        </p>
    </div>
    
    <!-- Tabla de usuarios VIP -->
    <?php if ($vip_count > 0): ?>
        <div class="mads-ps-users-table-wrapper">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Usuario', 'mad-suite'); ?></th>
                        <th><?php _e('Email', 'mad-suite'); ?></th>
                        <th><?php _e('VIP desde', 'mad-suite'); ?></th>
                        <th><?php _e('Acciones', 'mad-suite'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vip_users as $user): 
                        $vip_since = get_user_meta($user->ID, '_mads_ps_vip_since', true);
                        $vip_date = $vip_since ? date_i18n(get_option('date_format'), $vip_since) : __('N/A', 'mad-suite');
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($user->display_name); ?></strong><br>
                                <small><?php echo esc_html($user->user_login); ?></small>
                            </td>
                            <td><?php echo esc_html($user->user_email); ?></td>
                            <td><?php echo esc_html($vip_date); ?></td>
                            <td>
                                <a href="<?php echo get_edit_user_link($user->ID); ?>" class="button button-small">
                                    <?php _e('Editar', 'mad-suite'); ?>
                                </a>
                                <a href="<?php 
                                    echo wp_nonce_url(
                                        admin_url('admin-post.php?action=mads_toggle_vip_access&user_id=' . $user->ID . '&toggle=remove'),
                                        'mads_toggle_vip_' . $user->ID
                                    ); 
                                ?>" class="button button-small button-link-delete">
                                    <?php _e('Quitar VIP', 'mad-suite'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="mads-ps-empty-state">
            <div class="empty-icon">
                <span class="dashicons dashicons-groups"></span>
            </div>
            <h3><?php _e('No hay usuarios VIP todavía', 'mad-suite'); ?></h3>
            <p><?php _e('Agrega tu primer usuario VIP para comenzar', 'mad-suite'); ?></p>
            <button type="button" class="button button-primary" id="add-first-user-btn">
                <span class="dashicons dashicons-plus"></span>
                <?php _e('Agregar Primer Usuario VIP', 'mad-suite'); ?>
            </button>
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

<!-- Estilos inline para los modales y tabla -->
<style>
/* Estilos básicos */
.mads-ps-users-tab {
    max-width: 1200px;
}

.users-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.users-actions {
    display: flex;
    gap: 10px;
}

.mads-ps-users-info {
    background: #fff;
    border-left: 4px solid #2271b1;
    padding: 12px;
    margin-bottom: 20px;
}

.mads-ps-users-info p {
    margin: 0;
}

/* Empty state */
.mads-ps-empty-state {
    text-align: center;
    padding: 60px 20px;
    background: #fff;
    border: 2px dashed #ddd;
    border-radius: 8px;
}

.empty-icon .dashicons {
    font-size: 64px;
    width: 64px;
    height: 64px;
    color: #ddd;
}

.mads-ps-empty-state h3 {
    margin: 20px 0 10px;
    color: #666;
}

/* Modal general */
.mads-ps-modal {
    display: none;
    position: fixed;
    z-index: 100000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.mads-ps-modal-content {
    position: relative;
    background-color: #fff;
    margin: 5% auto;
    padding: 0;
    border-radius: 8px;
    max-width: 600px;
    max-height: 80vh;
    display: flex;
    flex-direction: column;
}

.mads-ps-modal-close {
    position: absolute;
    right: 15px;
    top: 15px;
    font-size: 28px;
    font-weight: bold;
    color: #aaa;
    cursor: pointer;
    z-index: 1;
}

.mads-ps-modal-close:hover {
    color: #000;
}

.mads-ps-modal-content h2 {
    margin: 0;
    padding: 20px;
    border-bottom: 1px solid #f0f0f1;
}

.modal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
}

.modal-footer {
    padding: 15px 20px;
    border-top: 1px solid #f0f0f1;
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

/* User search */
.user-search-wrapper {
    position: relative;
    margin-bottom: 15px;
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

.user-search-results {
    max-height: 300px;
    overflow-y: auto;
    border: 1px solid #ddd;
    border-radius: 4px;
    background: #fff;
}

.user-result-item {
    padding: 12px;
    border-bottom: 1px solid #f0f0f1;
    cursor: pointer;
    transition: background 0.2s;
}

.user-result-item:hover {
    background: #f9f9f9;
}

.user-result-item.selected {
    background: #e3f2fd;
}

.user-result-item.is-vip {
    background: #fff3cd;
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
