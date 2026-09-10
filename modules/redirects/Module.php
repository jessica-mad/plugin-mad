<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Redirecciones SEO — mantiene el posicionamiento después de una migración
 * redirigiendo (301/302/410) las URLs viejas hacia las nuevas.
 *
 * Tabla propia (no depende de RankMath/Yoast): si el sitio ya usa el
 * gestor de redirecciones de alguno de esos SEO plugins, evitá cargar la
 * misma URL en los dos sistemas — no hay conflicto técnico (el primero
 * que encuentra coincidencia redirige y corta la ejecución), pero si
 * apuntan a destinos distintos, gana el que se ejecute primero y es
 * confuso de mantener.
 */
return new class( MAD_Suite_Core::instance() ) implements MAD_Suite_Module {

    private $core;
    private $table;
    private $log_table;

    // Bump para disparar dbDelta si cambia el esquema.
    private const TABLE_VERSION = '1.0';
    private const WILDCARD_CACHE_KEY = 'mad_seo_redirects_wildcards';

    // Extensiones que no vale la pena registrar en el log de 404.
    private const IGNORED_404_EXTENSIONS = [
        'ico', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'css', 'js',
        'woff', 'woff2', 'ttf', 'eot', 'map', 'xml', 'txt',
    ];

    public function __construct( $core ) {
        $this->core = $core;
        global $wpdb;
        $this->table     = $wpdb->prefix . 'mad_seo_redirects';
        $this->log_table = $wpdb->prefix . 'mad_seo_redirects_404log';
    }

    public function slug()       { return 'redirects'; }
    public function title()      { return __( 'Redirecciones SEO (Migración)', 'mad-suite' ); }
    public function menu_label() { return __( 'Redirecciones SEO', 'mad-suite' ); }
    public function menu_slug()  { return 'mad-' . $this->slug(); }

    /* ================================================================ */
    /*  init()                                                           */
    /* ================================================================ */

    public function init() {
        $this->maybe_create_tables();

        // Prioridad baja (temprano) para que, si hay coincidencia, gane
        // antes de que cualquier otro plugin empiece a generar salida.
        add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 1 );

        add_action( 'admin_post_mad_redirect_save',        [ $this, 'handle_save' ] );
        add_action( 'admin_post_mad_redirect_delete',      [ $this, 'handle_delete' ] );
        add_action( 'admin_post_mad_redirect_toggle',      [ $this, 'handle_toggle' ] );
        add_action( 'admin_post_mad_redirect_export_csv',  [ $this, 'handle_export_csv' ] );
        add_action( 'admin_post_mad_redirect_clear_404log', [ $this, 'handle_clear_404_log' ] );
    }

    public function admin_init() {
        // Sin Settings API: los formularios postean directo a admin-post.php.
    }

    /* ================================================================ */
    /*  Base de datos                                                    */
    /* ================================================================ */

    private function maybe_create_tables() {
        $current = get_option( 'mad_seo_redirects_table_version', '' );
        if ( $current === self::TABLE_VERSION ) return;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $GLOBALS['wpdb']->get_charset_collate();

        dbDelta( "CREATE TABLE {$this->table} (
            id           bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_path  varchar(500)  NOT NULL,
            destination  varchar(500)  NOT NULL DEFAULT '',
            match_type   varchar(10)   NOT NULL DEFAULT 'exact',
            status_code  smallint(4)   NOT NULL DEFAULT 301,
            is_active    tinyint(1)    NOT NULL DEFAULT 1,
            hits         bigint(20) unsigned NOT NULL DEFAULT 0,
            last_hit_at  datetime      DEFAULT NULL,
            created_at   datetime      NOT NULL,
            updated_at   datetime      NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY   source_path (source_path(191))
        ) $charset;" );

        dbDelta( "CREATE TABLE {$this->log_table} (
            id          bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            url         varchar(500)  NOT NULL,
            referrer    varchar(500)  NOT NULL DEFAULT '',
            hits        bigint(20) unsigned NOT NULL DEFAULT 1,
            first_seen  datetime      NOT NULL,
            last_seen   datetime      NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY  url (url(191))
        ) $charset;" );

        update_option( 'mad_seo_redirects_table_version', self::TABLE_VERSION );
    }

    /**
     * Normaliza una URL o ruta a un path comparable: sin esquema/host,
     * sin query string (el matching ignora parámetros como utm_*),
     * decodificado, con slash inicial y sin slash final (salvo la raíz).
     * Funciona tanto con una URL completa como con un REQUEST_URI crudo
     * (que ya puede traer su propio "?query" pegado).
     */
    public function normalize_path( string $input ): string {
        $input = trim( $input );
        if ( '' === $input ) return '';

        $path = wp_parse_url( $input, PHP_URL_PATH );
        if ( ! is_string( $path ) || '' === $path ) {
            // Fallback defensivo si wp_parse_url no pudo interpretarlo.
            $path = preg_replace( '/[?#].*$/', '', $input );
        }

        $path = rawurldecode( $path );
        if ( '' === $path ) $path = '/';
        if ( '/' !== $path[0] ) $path = '/' . $path;
        if ( strlen( $path ) > 1 ) {
            $path = rtrim( $path, '/' );
            if ( '' === $path ) $path = '/';
        }

        return $path;
    }

    private function detect_match_type( string $source_path ): string {
        return ( false !== strpos( $source_path, '*' ) ) ? 'wildcard' : 'exact';
    }

    /**
     * Igual que normalize_path() pero sin recortar el slash final: en un
     * destino ese slash suele ser el permalink canónico de WP y perderlo
     * solo agrega un salto extra (WP corrige con su propio redirect canónico).
     */
    private function normalize_destination( string $destination ): string {
        $destination = trim( $destination );
        if ( '' === $destination ) return '';
        if ( preg_match( '#^https?://#i', $destination ) ) return $destination;
        if ( '/' !== $destination[0] ) $destination = '/' . $destination;
        return $destination;
    }

    /**
     * Inserta o actualiza (upsert por source_path). Devuelve true/false.
     */
    public function upsert_redirect( string $source, string $destination, int $status_code = 301, bool $is_active = true ) {
        global $wpdb;

        $source_path = $this->normalize_path( $source );
        if ( '' === $source_path ) return false;

        // 410 (Gone) no necesita destino; para el resto, sin destino no hay a dónde redirigir.
        $destination = $this->normalize_destination( $destination );
        if ( 410 !== $status_code && '' === $destination ) return false;

        // Evita loops obvios (origen y destino apuntan al mismo lugar).
        if ( $destination !== '' && $this->normalize_path( $destination ) === $source_path ) return false;

        $match_type = $this->detect_match_type( $source_path );
        $now        = current_time( 'mysql' );

        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$this->table} (source_path, destination, match_type, status_code, is_active, created_at, updated_at)
             VALUES (%s, %s, %s, %d, %d, %s, %s)
             ON DUPLICATE KEY UPDATE
                destination = VALUES(destination),
                match_type  = VALUES(match_type),
                status_code = VALUES(status_code),
                is_active   = VALUES(is_active),
                updated_at  = VALUES(updated_at)",
            $source_path, $destination, $match_type, $status_code, $is_active ? 1 : 0, $now, $now
        ) );

        delete_transient( self::WILDCARD_CACHE_KEY );

        return false !== $result;
    }

    /* ================================================================ */
    /*  Front-end: matching + redirección                                */
    /* ================================================================ */

    public function maybe_redirect() {
        if ( is_admin() ) return;
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) return;
        if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) return;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) return;

        $request_path = $this->normalize_path( $_SERVER['REQUEST_URI'] ?? '/' );
        if ( '' === $request_path ) return;

        $match = $this->find_match( $request_path );

        if ( $match ) {
            $this->record_hit( (int) $match['id'] );

            if ( 410 === (int) $match['status_code'] ) {
                status_header( 410 );
                nocache_headers();
                exit;
            }

            $location = preg_match( '#^https?://#i', $match['destination'] )
                ? $match['destination']
                : home_url( $match['destination'] );

            wp_safe_redirect( $location, (int) $match['status_code'] );
            exit;
        }

        if ( function_exists( 'is_404' ) && is_404() ) {
            $this->maybe_log_404( $request_path );
        }
    }

    /**
     * Busca una coincidencia activa: primero exacta (índice único, O(1)),
     * después wildcard (lista corta, cacheada en transient).
     */
    private function find_match( string $request_path ) {
        global $wpdb;

        $exact = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, destination, status_code FROM {$this->table}
             WHERE source_path = %s AND match_type = 'exact' AND is_active = 1 LIMIT 1",
            $request_path
        ), ARRAY_A );

        if ( $exact ) return $exact;

        $wildcards = get_transient( self::WILDCARD_CACHE_KEY );
        if ( false === $wildcards ) {
            $wildcards = $wpdb->get_results(
                "SELECT id, source_path, destination, status_code FROM {$this->table}
                 WHERE match_type = 'wildcard' AND is_active = 1",
                ARRAY_A
            );
            set_transient( self::WILDCARD_CACHE_KEY, $wildcards, DAY_IN_SECONDS );
        }

        foreach ( (array) $wildcards as $rule ) {
            $regex = '#^' . str_replace( '\*', '(.*)', preg_quote( $rule['source_path'], '#' ) ) . '$#i';
            if ( preg_match( $regex, $request_path, $captured ) ) {
                $destination = $rule['destination'];
                if ( isset( $captured[1] ) ) {
                    $destination = str_replace( '$1', $captured[1], $destination );
                }
                return [
                    'id'          => $rule['id'],
                    'destination' => $destination,
                    'status_code' => $rule['status_code'],
                ];
            }
        }

        return null;
    }

    private function record_hit( int $id ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table} SET hits = hits + 1, last_hit_at = %s WHERE id = %d",
            current_time( 'mysql' ), $id
        ) );
    }

    private function maybe_log_404( string $request_path ) {
        $ext = strtolower( pathinfo( $request_path, PATHINFO_EXTENSION ) );
        if ( $ext && in_array( $ext, self::IGNORED_404_EXTENSIONS, true ) ) return;
        if ( '/favicon.ico' === $request_path || '/robots.txt' === $request_path ) return;

        global $wpdb;
        $now      = current_time( 'mysql' );
        $referrer = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$this->log_table} (url, referrer, hits, first_seen, last_seen)
             VALUES (%s, %s, 1, %s, %s)
             ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen)",
            $request_path, $referrer, $now, $now
        ) );

        // Poda ocasional para que el log no crezca sin límite.
        if ( 1 === wp_rand( 1, 30 ) ) {
            $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->log_table}" );
            if ( $count > 2000 ) {
                $wpdb->query( "DELETE FROM {$this->log_table} ORDER BY last_seen ASC LIMIT " . ( $count - 2000 ) );
            }
        }
    }

    /* ================================================================ */
    /*  Admin: acciones (admin-post.php)                                 */
    /* ================================================================ */

    public function handle_save() {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) wp_die( __( 'No tienes permisos suficientes.', 'mad-suite' ) );
        check_admin_referer( 'mad_redirect_save' );

        $id          = absint( $_POST['id'] ?? 0 );
        $source      = sanitize_text_field( wp_unslash( $_POST['source_path'] ?? '' ) );
        $destination = sanitize_text_field( wp_unslash( $_POST['destination'] ?? '' ) );
        $status_code = absint( $_POST['status_code'] ?? 301 );
        $is_active   = ! empty( $_POST['is_active'] );

        if ( ! in_array( $status_code, [ 301, 302, 307, 410 ], true ) ) {
            $status_code = 301;
        }

        $saved = $this->upsert_redirect( $source, $destination, $status_code, $is_active );

        $redirect_args = [ 'page' => $this->menu_slug() ];
        $redirect_args[ $saved ? 'saved' : 'error' ] = 1;
        wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_delete() {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) wp_die( __( 'No tienes permisos suficientes.', 'mad-suite' ) );
        check_admin_referer( 'mad_redirect_delete' );

        global $wpdb;
        $id = absint( $_GET['id'] ?? 0 );
        if ( $id ) {
            $wpdb->delete( $this->table, [ 'id' => $id ], [ '%d' ] );
            delete_transient( self::WILDCARD_CACHE_KEY );
        }

        wp_safe_redirect( add_query_arg( [ 'page' => $this->menu_slug(), 'deleted' => 1 ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_toggle() {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) wp_die( __( 'No tienes permisos suficientes.', 'mad-suite' ) );
        check_admin_referer( 'mad_redirect_toggle' );

        global $wpdb;
        $id = absint( $_GET['id'] ?? 0 );
        if ( $id ) {
            $current = $wpdb->get_var( $wpdb->prepare( "SELECT is_active FROM {$this->table} WHERE id = %d", $id ) );
            $wpdb->update( $this->table, [ 'is_active' => $current ? 0 : 1 ], [ 'id' => $id ], [ '%d' ], [ '%d' ] );
            delete_transient( self::WILDCARD_CACHE_KEY );
        }

        wp_safe_redirect( add_query_arg( [ 'page' => $this->menu_slug() ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_export_csv() {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) wp_die( __( 'No tienes permisos suficientes.', 'mad-suite' ) );
        check_admin_referer( 'mad_redirect_export' );

        global $wpdb;
        $rows = $wpdb->get_results( "SELECT source_path, destination, status_code, is_active FROM {$this->table} ORDER BY id ASC", ARRAY_A );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=redirecciones-' . gmdate( 'Y-m-d' ) . '.csv' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'old_url', 'new_url', 'status_code', 'active' ] );
        foreach ( $rows as $row ) {
            fputcsv( $out, [ $row['source_path'], $row['destination'], $row['status_code'], $row['is_active'] ? 1 : 0 ] );
        }
        fclose( $out );
        exit;
    }

    public function handle_clear_404_log() {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) wp_die( __( 'No tienes permisos suficientes.', 'mad-suite' ) );
        check_admin_referer( 'mad_redirect_clear_404log' );

        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE {$this->log_table}" );

        wp_safe_redirect( add_query_arg( [ 'page' => $this->menu_slug(), 'action' => 'log404', 'cleared' => 1 ], admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Procesa el CSV importado. Devuelve un resumen ['imported'=>,'updated'=>,'skipped'=>,'errors'=>,'details'=>[]].
     * Columnas aceptadas (por nombre, si hay encabezado, o por posición si no):
     * old_url / url_antigua / source -> new_url / url_nueva / destination -> status_code (opcional, default 301).
     */
    public function import_csv( string $tmp_path ): array {
        $results = [ 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'details' => [] ];

        $handle = fopen( $tmp_path, 'r' );
        if ( ! $handle ) {
            $results['errors']++;
            $results['details'][] = __( 'No se pudo abrir el archivo.', 'mad-suite' );
            return $results;
        }

        global $wpdb;
        $header_aliases = [ 'old_url', 'url_antigua', 'source', 'source_url', 'url vieja', 'antigua' ];
        $row_num        = 0;
        $wpdb->query( 'START TRANSACTION' );

        while ( ( $data = fgetcsv( $handle ) ) !== false ) {
            $row_num++;

            if ( 1 === $row_num ) {
                $first_cell = strtolower( trim( $data[0] ?? '' ) );
                if ( in_array( $first_cell, $header_aliases, true ) ) {
                    continue; // fila de encabezado, se salta
                }
            }

            $old_url     = trim( $data[0] ?? '' );
            $new_url     = trim( $data[1] ?? '' );
            $status_raw  = trim( $data[2] ?? '' );
            $status_code = ( $status_raw !== '' && ctype_digit( $status_raw ) ) ? (int) $status_raw : 301;

            if ( '' === $old_url ) {
                $results['skipped']++;
                $results['details'][] = sprintf( __( 'Fila %d: URL antigua vacía, omitida.', 'mad-suite' ), $row_num );
                continue;
            }
            if ( 410 !== $status_code && '' === $new_url ) {
                $results['skipped']++;
                $results['details'][] = sprintf( __( 'Fila %d: falta la URL nueva ("%s").', 'mad-suite' ), $row_num, $old_url );
                continue;
            }

            $source_path = $this->normalize_path( $old_url );
            if ( '' !== $new_url && $this->normalize_path( $new_url ) === $source_path ) {
                $results['skipped']++;
                $results['details'][] = sprintf( __( 'Fila %d: origen y destino son la misma URL ("%s"), omitida para evitar un loop.', 'mad-suite' ), $row_num, $old_url );
                continue;
            }

            $existed = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table} WHERE source_path = %s", $source_path ) );

            $ok = $this->upsert_redirect( $old_url, $new_url, $status_code, true );

            if ( ! $ok ) {
                $results['errors']++;
                $results['details'][] = sprintf( __( 'Fila %d: error al guardar "%s".', 'mad-suite' ), $row_num, $old_url );
                continue;
            }

            if ( $existed ) {
                $results['updated']++;
            } else {
                $results['imported']++;
            }
        }

        $wpdb->query( 'COMMIT' );
        fclose( $handle );
        delete_transient( self::WILDCARD_CACHE_KEY );

        return $results;
    }

    /* ================================================================ */
    /*  Admin: vistas                                                    */
    /* ================================================================ */

    public function render_settings_page(): void {
        if ( ! current_user_can( MAD_Suite_Core::CAPABILITY ) ) {
            wp_die( __( 'No tienes permisos suficientes.', 'mad-suite' ) );
        }

        $action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';

        switch ( $action ) {
            case 'edit':
            case 'new':
                include __DIR__ . '/views/edit.php';
                break;
            case 'import':
                include __DIR__ . '/views/import.php';
                break;
            case 'log404':
                include __DIR__ . '/views/logs-404.php';
                break;
            default:
                include __DIR__ . '/views/list.php';
                break;
        }
    }

    /** Helpers de datos para las vistas. */
    public function get_redirect( int $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
    }

    public function get_redirects( string $search = '', int $per_page = 20, int $paged = 1 ): array {
        global $wpdb;
        $offset = max( 0, ( $paged - 1 ) * $per_page );

        $where  = '';
        $params = [];
        if ( '' !== $search ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where    = 'WHERE source_path LIKE %s OR destination LIKE %s';
            $params[] = $like;
            $params[] = $like;
        }

        $count_sql = "SELECT COUNT(*) FROM {$this->table} {$where}";
        $total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

        $rows_sql    = "SELECT * FROM {$this->table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
        $rows_params = array_merge( $params, [ $per_page, $offset ] );
        $rows        = $wpdb->get_results( $wpdb->prepare( $rows_sql, $rows_params ), ARRAY_A );

        return [ 'rows' => $rows, 'total' => $total ];
    }

    public function get_404_log( int $per_page = 20, int $paged = 1 ): array {
        global $wpdb;
        $offset = max( 0, ( $paged - 1 ) * $per_page );

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->log_table}" );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->log_table} ORDER BY last_seen DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ), ARRAY_A );

        return [ 'rows' => $rows, 'total' => $total ];
    }

    public function count_redirects(): int {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table}" );
    }
};
