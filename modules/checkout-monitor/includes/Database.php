<?php
namespace MAD_Suite\CheckoutMonitor;

if ( ! defined('ABSPATH') ) exit;

class Database {

    private $sessions_table;
    private $events_table;
    private $logs_table;

    public function __construct(){
        global $wpdb;
        $this->sessions_table = $wpdb->prefix . 'checkout_monitor_sessions';
        $this->events_table = $wpdb->prefix . 'checkout_monitor_events';
        $this->logs_table = $wpdb->prefix . 'checkout_monitor_server_logs';
    }

    /* ==== Sesiones ==== */
    public function create_session($data){
        global $wpdb;

        $defaults = [
            'session_id' => uniqid('cm_', true),
            'status' => 'initiated',
            'started_at' => current_time('mysql', true),
            'has_errors' => 0,
            'error_count' => 0,
            'hook_count' => 0,
        ];

        $data = wp_parse_args($data, $defaults);

        $wpdb->insert($this->sessions_table, $data);

        return $wpdb->insert_id;
    }

    public function update_session($session_id, $data){
        global $wpdb;

        $data['updated_at'] = current_time('mysql', true);

        return $wpdb->update(
            $this->sessions_table,
            $data,
            ['session_id' => $session_id],
            null,
            ['%s']
        );
    }

    public function get_session_by_id($session_id){
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->sessions_table} WHERE session_id = %s",
            $session_id
        ));
    }

    public function complete_session($session_id, $status = 'completed'){
        $session = $this->get_session_by_id($session_id);

        if ( !$session ) return false;

        $started = strtotime($session->started_at);
        $completed = time();
        $duration_ms = ($completed - $started) * 1000;

        return $this->update_session($session_id, [
            'status' => $status,
            'completed_at' => current_time('mysql', true),
            'duration_ms' => $duration_ms,
        ]);
    }

    public function increment_hook_count($session_id){
        global $wpdb;

        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->sessions_table} SET hook_count = hook_count + 1 WHERE session_id = %s",
            $session_id
        ));
    }

    public function increment_error_count($session_id){
        global $wpdb;

        return $wpdb->query($wpdb->prepare(
            "UPDATE {$this->sessions_table} SET error_count = error_count + 1, has_errors = 1 WHERE session_id = %s",
            $session_id
        ));
    }

    /* ==== Eventos ==== */
    public function create_event($data){
        global $wpdb;

        $defaults = [
            'event_type' => 'hook',
            'started_at' => current_time('mysql', true),
            'has_error' => 0,
        ];

        $data = wp_parse_args($data, $defaults);

        $wpdb->insert($this->events_table, $data);

        return $wpdb->insert_id;
    }

    public function update_event($event_id, $data){
        global $wpdb;

        return $wpdb->update(
            $this->events_table,
            $data,
            ['id' => $event_id],
            null,
            ['%d']
        );
    }

    public function get_events_by_session($session_id, $order_by = 'started_at ASC'){
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->events_table} WHERE session_id = %s ORDER BY $order_by",
            $session_id
        ));
    }

    public function get_error_events_by_session($session_id){
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->events_table} WHERE session_id = %s AND has_error = 1 ORDER BY started_at ASC",
            $session_id
        ));
    }

    /* ==== Server Logs ==== */
    public function create_server_log($data){
        global $wpdb;

        $defaults = [
            'log_type' => 'unknown',
            'log_source' => 'unknown',
        ];

        $data = wp_parse_args($data, $defaults);

        $wpdb->insert($this->logs_table, $data);

        return $wpdb->insert_id;
    }

    public function get_server_logs_by_session($session_id){
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->logs_table} WHERE session_id = %s ORDER BY timestamp ASC",
            $session_id
        ));
    }

    /* ==== Queries para Dashboard ==== */
    public function get_sessions($args = []){
        global $wpdb;

        $defaults = [
            'page' => 1,
            'per_page' => 20,
            'status' => null,
            'has_errors' => null,
            'date_from' => null,
            'date_to' => null,
            'search' => null,
        ];

        $args = wp_parse_args($args, $defaults);

        $where = ['1=1'];
        $prepare_args = [];

        if ( $args['status'] ) {
            $where[] = 'status = %s';
            $prepare_args[] = $args['status'];
        }

        if ( $args['has_errors'] !== null ) {
            $where[] = 'has_errors = %d';
            $prepare_args[] = intval($args['has_errors']);
        }

        if ( $args['date_from'] ) {
            $where[] = 'started_at >= %s';
            $prepare_args[] = $args['date_from'];
        }

        if ( $args['date_to'] ) {
            $where[] = 'started_at <= %s';
            $prepare_args[] = $args['date_to'];
        }

        if ( $args['search'] ) {
            $where[] = '(session_id LIKE %s OR order_uid LIKE %s OR payment_method LIKE %s)';
            $search = '%' . $wpdb->esc_like($args['search']) . '%';
            $prepare_args[] = $search;
            $prepare_args[] = $search;
            $prepare_args[] = $search;
        }

        $where_sql = implode(' AND ', $where);

        // Total count
        if ( !empty($prepare_args) ) {
            $total = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->sessions_table} WHERE $where_sql",
                ...$prepare_args
            ));
        } else {
            $total = $wpdb->get_var("SELECT COUNT(*) FROM {$this->sessions_table} WHERE $where_sql");
        }

        // Paginación
        $offset = ($args['page'] - 1) * $args['per_page'];
        $limit = $args['per_page'];

        // Query con paginación
        if ( !empty($prepare_args) ) {
            $sessions = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->sessions_table} WHERE $where_sql ORDER BY started_at DESC LIMIT %d OFFSET %d",
                array_merge($prepare_args, [$limit, $offset])
            ));
        } else {
            $sessions = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->sessions_table} WHERE $where_sql ORDER BY started_at DESC LIMIT %d OFFSET %d",
                $limit,
                $offset
            ));
        }

        return [
            'sessions' => $sessions,
            'total' => intval($total),
            'page' => $args['page'],
            'per_page' => $args['per_page'],
            'total_pages' => ceil($total / $args['per_page']),
        ];
    }

    public function get_statistics($days = 7){
        global $wpdb;

        $date_from = date('Y-m-d H:i:s', strtotime("-$days days"));

        $stats = [
            'total_checkouts' => 0,
            'successful_checkouts' => 0,
            'failed_checkouts' => 0,
            'error_rate' => 0,
            'avg_duration_ms' => 0,
            'most_common_errors' => [],
            'plugins_with_errors' => [],
        ];

        // Total checkouts
        $stats['total_checkouts'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->sessions_table} WHERE started_at >= %s",
            $date_from
        ));

        // Successful checkouts
        $stats['successful_checkouts'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->sessions_table} WHERE started_at >= %s AND status = 'completed' AND has_errors = 0",
            $date_from
        ));

        // Failed checkouts
        $stats['failed_checkouts'] = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->sessions_table} WHERE started_at >= %s AND (status = 'failed' OR has_errors = 1)",
            $date_from
        ));

        // Error rate
        if ( $stats['total_checkouts'] > 0 ) {
            $stats['error_rate'] = ($stats['failed_checkouts'] / $stats['total_checkouts']) * 100;
        }

        // Avg duration
        $stats['avg_duration_ms'] = $wpdb->get_var($wpdb->prepare(
            "SELECT AVG(duration_ms) FROM {$this->sessions_table} WHERE started_at >= %s AND duration_ms IS NOT NULL",
            $date_from
        ));

        return $stats;
    }
}
