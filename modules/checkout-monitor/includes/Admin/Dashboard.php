<?php
namespace MAD_Suite\CheckoutMonitor\Admin;

use MAD_Suite\CheckoutMonitor\Database;
use MAD_Suite\CheckoutMonitor\Analyzers\LogAnalyzer;

if ( ! defined('ABSPATH') ) exit;

class Dashboard {

    private $database;
    private $log_analyzer;

    public function __construct(Database $database){
        $this->database = $database;
        $this->log_analyzer = new LogAnalyzer($database);
    }

    public function render(){
        ?>
        <div class="wrap checkout-monitor-dashboard">
            <h1>
                <?php _e('Checkout Monitor', 'mad-suite'); ?>
                <span class="checkout-monitor-subtitle"><?php _e('Monitorización completa del proceso de checkout', 'mad-suite'); ?></span>
            </h1>

            <!-- Estadísticas -->
            <div class="checkout-monitor-stats">
                <?php $this->render_statistics(); ?>
            </div>

            <!-- Tabs -->
            <div class="checkout-monitor-tabs">
                <nav class="nav-tab-wrapper">
                    <a href="#sessions" class="nav-tab nav-tab-active"><?php _e('Sesiones de Checkout', 'mad-suite'); ?></a>
                    <a href="#logs" class="nav-tab"><?php _e('Archivos de Log', 'mad-suite'); ?></a>
                    <a href="#settings" class="nav-tab"><?php _e('Configuración', 'mad-suite'); ?></a>
                </nav>

                <div class="tab-content">
                    <!-- Tab: Sesiones -->
                    <div id="sessions" class="tab-panel active">
                        <?php $this->render_sessions_table(); ?>
                    </div>

                    <!-- Tab: Logs -->
                    <div id="logs" class="tab-panel">
                        <?php $this->render_logs_table(); ?>
                    </div>

                    <!-- Tab: Settings -->
                    <div id="settings" class="tab-panel">
                        <?php $this->render_settings(); ?>
                    </div>
                </div>
            </div>

            <!-- Modal para detalles de sesión -->
            <div id="session-detail-modal" class="checkout-monitor-modal" style="display: none;">
                <div class="modal-content">
                    <span class="close">&times;</span>
                    <div id="session-detail-content"></div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_statistics(){
        $stats = $this->database->get_statistics(7);
        ?>
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">📊</div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($stats['total_checkouts']); ?></div>
                    <div class="stat-label"><?php _e('Total Checkouts (7d)', 'mad-suite'); ?></div>
                </div>
            </div>

            <div class="stat-card stat-success">
                <div class="stat-icon">✅</div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($stats['successful_checkouts']); ?></div>
                    <div class="stat-label"><?php _e('Exitosos', 'mad-suite'); ?></div>
                </div>
            </div>

            <div class="stat-card stat-error">
                <div class="stat-icon">❌</div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($stats['failed_checkouts']); ?></div>
                    <div class="stat-label"><?php _e('Fallidos', 'mad-suite'); ?></div>
                </div>
            </div>

            <div class="stat-card <?php echo $stats['error_rate'] > 10 ? 'stat-warning' : ''; ?>">
                <div class="stat-icon">📈</div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($stats['error_rate'], 2); ?>%</div>
                    <div class="stat-label"><?php _e('Tasa de Error', 'mad-suite'); ?></div>
                </div>
            </div>

            <div class="stat-card">
                <div class="stat-icon">⏱️</div>
                <div class="stat-content">
                    <div class="stat-value"><?php echo number_format($stats['avg_duration_ms']); ?>ms</div>
                    <div class="stat-label"><?php _e('Duración Promedio', 'mad-suite'); ?></div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_sessions_table(){
        ?>
        <div class="sessions-filters">
            <input type="text" id="session-search" placeholder="<?php _e('Buscar por Session ID, Order ID...', 'mad-suite'); ?>">
            <select id="session-status-filter">
                <option value=""><?php _e('Todos los estados', 'mad-suite'); ?></option>
                <option value="initiated"><?php _e('Iniciado', 'mad-suite'); ?></option>
                <option value="processing"><?php _e('Procesando', 'mad-suite'); ?></option>
                <option value="completed"><?php _e('Completado', 'mad-suite'); ?></option>
                <option value="failed"><?php _e('Fallido', 'mad-suite'); ?></option>
            </select>
            <select id="session-errors-filter">
                <option value=""><?php _e('Todos', 'mad-suite'); ?></option>
                <option value="1"><?php _e('Solo con errores', 'mad-suite'); ?></option>
                <option value="0"><?php _e('Sin errores', 'mad-suite'); ?></option>
            </select>
            <input type="date" id="session-date-from" placeholder="<?php _e('Desde', 'mad-suite'); ?>">
            <input type="date" id="session-date-to" placeholder="<?php _e('Hasta', 'mad-suite'); ?>">
            <button id="session-apply-filters" class="button"><?php _e('Aplicar Filtros', 'mad-suite'); ?></button>
        </div>

        <div id="sessions-table-container">
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Session ID', 'mad-suite'); ?></th>
                        <th><?php _e('Order ID', 'mad-suite'); ?></th>
                        <th><?php _e('Estado', 'mad-suite'); ?></th>
                        <th><?php _e('Fecha/Hora', 'mad-suite'); ?></th>
                        <th><?php _e('Duración', 'mad-suite'); ?></th>
                        <th><?php _e('Método Pago', 'mad-suite'); ?></th>
                        <th><?php _e('Total', 'mad-suite'); ?></th>
                        <th><?php _e('Hooks', 'mad-suite'); ?></th>
                        <th><?php _e('Errores', 'mad-suite'); ?></th>
                        <th><?php _e('Dispositivo', 'mad-suite'); ?></th>
                        <th><?php _e('Acciones', 'mad-suite'); ?></th>
                    </tr>
                </thead>
                <tbody id="sessions-tbody">
                    <tr>
                        <td colspan="11" style="text-align: center;">
                            <span class="spinner is-active" style="float: none;"></span>
                            <?php _e('Cargando sesiones...', 'mad-suite'); ?>
                        </td>
                    </tr>
                </tbody>
            </table>

            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num" id="sessions-count"></span>
                    <span class="pagination-links" id="sessions-pagination"></span>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_logs_table(){
        $log_files = $this->log_analyzer->get_all_log_files();
        ?>
        <div class="logs-table-container">
            <h2><?php _e('Archivos de Log del Servidor', 'mad-suite'); ?></h2>
            <p class="description"><?php _e('Estos son todos los archivos de log detectados en el servidor que se analizan durante el checkout.', 'mad-suite'); ?></p>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Fuente', 'mad-suite'); ?></th>
                        <th><?php _e('Archivo', 'mad-suite'); ?></th>
                        <th><?php _e('Ubicación', 'mad-suite'); ?></th>
                        <th><?php _e('Tamaño', 'mad-suite'); ?></th>
                        <th><?php _e('Última Modificación', 'mad-suite'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty($log_files) ): ?>
                        <tr>
                            <td colspan="5" style="text-align: center;">
                                <?php _e('No se encontraron archivos de log.', 'mad-suite'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ( $log_files as $log ): ?>
                            <tr>
                                <td><strong><?php echo esc_html($log['source']); ?></strong></td>
                                <td><code><?php echo esc_html($log['file']); ?></code></td>
                                <td><code><?php echo esc_html($log['path']); ?></code></td>
                                <td><?php echo size_format($log['size']); ?></td>
                                <td><?php echo date('Y-m-d H:i:s', $log['modified']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function render_settings(){
        ?>
        <div class="settings-container">
            <h2><?php _e('Configuración del Monitor', 'mad-suite'); ?></h2>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="cleanup_days"><?php _e('Retención de Datos', 'mad-suite'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="cleanup_days" name="cleanup_days" value="30" min="1" max="365">
                        <p class="description"><?php _e('Días que se mantendrán los logs antes de ser eliminados automáticamente.', 'mad-suite'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php _e('Limpieza Manual', 'mad-suite'); ?>
                    </th>
                    <td>
                        <button id="cleanup-old-logs" class="button button-secondary">
                            <?php _e('Eliminar logs antiguos ahora', 'mad-suite'); ?>
                        </button>
                        <p class="description"><?php _e('Elimina manualmente los logs más antiguos según los días de retención configurados.', 'mad-suite'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php _e('Estado del Sistema', 'mad-suite'); ?>
                    </th>
                    <td>
                        <?php
                        global $wpdb;
                        $sessions_table = $wpdb->prefix . 'checkout_monitor_sessions';
                        $events_table = $wpdb->prefix . 'checkout_monitor_events';
                        $logs_table = $wpdb->prefix . 'checkout_monitor_server_logs';

                        $total_sessions = $wpdb->get_var("SELECT COUNT(*) FROM $sessions_table");
                        $total_events = $wpdb->get_var("SELECT COUNT(*) FROM $events_table");
                        $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM $logs_table");
                        ?>
                        <ul>
                            <li><?php printf(__('Sesiones registradas: <strong>%s</strong>', 'mad-suite'), number_format($total_sessions)); ?></li>
                            <li><?php printf(__('Eventos capturados: <strong>%s</strong>', 'mad-suite'), number_format($total_events)); ?></li>
                            <li><?php printf(__('Entradas de log: <strong>%s</strong>', 'mad-suite'), number_format($total_logs)); ?></li>
                        </ul>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /* ==== AJAX Data Methods ==== */
    public function get_sessions_data($page = 1, $per_page = 20, $filters = []){
        $args = [
            'page' => $page,
            'per_page' => $per_page,
        ];

        if ( isset($filters['status']) && !empty($filters['status']) ) {
            $args['status'] = $filters['status'];
        }

        if ( isset($filters['has_errors']) && $filters['has_errors'] !== '' ) {
            $args['has_errors'] = intval($filters['has_errors']);
        }

        if ( isset($filters['date_from']) && !empty($filters['date_from']) ) {
            $args['date_from'] = $filters['date_from'];
        }

        if ( isset($filters['date_to']) && !empty($filters['date_to']) ) {
            $args['date_to'] = $filters['date_to'];
        }

        if ( isset($filters['search']) && !empty($filters['search']) ) {
            $args['search'] = $filters['search'];
        }

        return $this->database->get_sessions($args);
    }

    public function get_session_detail($session_id){
        $session = $this->database->get_session_by_id($session_id);

        if ( !$session ) {
            return ['error' => 'Session not found'];
        }

        $events = $this->database->get_events_by_session($session_id);
        $server_logs = $this->database->get_server_logs_by_session($session_id);

        // Parse browser data
        $browser_data = null;
        if ( $session->browser_data ) {
            $browser_data = json_decode($session->browser_data, true);
        }

        return [
            'session' => $session,
            'events' => $events,
            'server_logs' => $server_logs,
            'browser_data' => $browser_data,
        ];
    }
}
