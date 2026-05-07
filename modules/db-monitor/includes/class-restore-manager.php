<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_DBM_RestoreManager {

    private MAD_DBM_Analyzer $analyzer;
    private MAD_DBM_AuditLogger $audit;

    /** Tables that require extra confirmation to restore (never auto-restored) */
    private array $extra_protected = [];

    public function __construct( MAD_DBM_Analyzer $analyzer, MAD_DBM_AuditLogger $audit ) {
        $this->analyzer = $analyzer;
        $this->audit    = $audit;
    }

    /**
     * Validate an uploaded file from $_FILES.
     * Returns path to saved temp file on success, WP_Error on failure.
     */
    public function handle_upload( array $file_entry ) {
        if ( empty( $file_entry['tmp_name'] ) || ! is_uploaded_file( $file_entry['tmp_name'] ) ) {
            return new WP_Error( 'invalid_upload', 'Archivo no válido o no se subió correctamente.' );
        }

        $name = $file_entry['name'] ?? '';
        if ( ! preg_match( '/^dbm_.+\.sql\.gz$/', $name ) ) {
            return new WP_Error( 'invalid_filename', 'El archivo debe ser un .sql.gz exportado por este plugin (nombre: dbm_*.sql.gz).' );
        }

        $size = (int) ( $file_entry['size'] ?? 0 );
        if ( $size === 0 ) {
            return new WP_Error( 'empty_file', 'El archivo subido está vacío.' );
        }

        // Verify it's actually a gzip file
        $magic = $this->read_bytes( $file_entry['tmp_name'], 2 );
        if ( $magic !== "\x1f\x8b" ) {
            return new WP_Error( 'not_gzip', 'El archivo no parece ser un .gz válido.' );
        }

        // Move to a private temp location
        $upload  = wp_upload_dir();
        $tmp_dir = trailingslashit( $upload['basedir'] ) . 'mad-db-exports/tmp';
        if ( ! is_dir( $tmp_dir ) ) wp_mkdir_p( $tmp_dir );

        $tmp_name = $tmp_dir . '/' . sanitize_file_name( $name );
        if ( ! move_uploaded_file( $file_entry['tmp_name'], $tmp_name ) ) {
            return new WP_Error( 'move_failed', 'No se pudo mover el archivo a la carpeta temporal.' );
        }

        return $tmp_name;
    }

    /**
     * Parse the MAD-DB-EXPORT header from a .sql.gz file.
     * Returns array with metadata or WP_Error.
     */
    public function parse_header( string $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_not_found', 'Archivo temporal no encontrado.' );
        }

        $gz = gzopen( $file_path, 'rb' );
        if ( ! $gz ) {
            return new WP_Error( 'gz_open_failed', 'No se pudo abrir el archivo comprimido.' );
        }

        $header_lines = [];
        $found_marker = false;
        $lines_read   = 0;

        while ( ! gzeof( $gz ) && $lines_read < 20 ) {
            $line = gzgets( $gz, 1024 );
            $lines_read++;

            if ( trim( $line ) === '-- MAD-DB-EXPORT' ) {
                $found_marker = true;
                continue;
            }

            if ( $found_marker ) {
                if ( strpos( $line, '--' ) !== 0 ) break;
                $header_lines[] = trim( ltrim( $line, "- \t" ) );
            }
        }

        gzclose( $gz );

        if ( ! $found_marker ) {
            return new WP_Error( 'not_mad_export', 'Este archivo no fue generado por MAD DB Monitor. Solo se pueden restaurar archivos exportados por este plugin.' );
        }

        $meta = [];
        foreach ( $header_lines as $line ) {
            if ( strpos( $line, ': ' ) !== false ) {
                [ $key, $val ] = explode( ': ', $line, 2 );
                $meta[ strtolower( trim( $key ) ) ] = trim( $val );
            }
        }

        if ( empty( $meta['table'] ) ) {
            return new WP_Error( 'no_table_in_header', 'No se encontró el nombre de tabla en la cabecera del archivo.' );
        }

        return $meta;
    }

    /**
     * Check if the table in the export can be restored.
     * Returns true on success, WP_Error describing the specific reason on failure.
     */
    public function is_restorable( string $table ) {
        // Absolute restriction: user credential tables can never be imported.
        if ( ! $this->analyzer->is_table_exportable( $table ) ) {
            return new WP_Error(
                'user_table_restricted',
                sprintf( "La tabla '%s' contiene credenciales de usuario y nunca puede importarse desde este panel.", $table )
            );
        }
        // Protected core/WooCommerce tables cannot be restored through this UI.
        if ( $this->analyzer->is_table_protected( $table ) ) {
            return new WP_Error(
                'table_protected',
                sprintf( "La tabla '%s' está protegida y no puede restaurarse desde este panel.", $table )
            );
        }
        // Table must exist in DB (we only restore to existing tables to avoid schema injection).
        if ( ! $this->analyzer->table_exists( $table ) ) {
            return new WP_Error(
                'table_not_found',
                sprintf( "La tabla '%s' no existe en la base de datos actual.", $table )
            );
        }
        return true;
    }

    /**
     * Execute restore of a .sql.gz file to the specified table.
     * Returns summary array or WP_Error.
     */
    public function execute_restore( string $tmp_file, string $expected_table ) {
        global $wpdb;

        // Re-validate header
        $meta = $this->parse_header( $tmp_file );
        if ( is_wp_error( $meta ) ) {
            $this->cleanup_temp( $tmp_file );
            return $meta;
        }

        $table_in_file = $meta['table'] ?? '';
        if ( $table_in_file !== $expected_table ) {
            $this->cleanup_temp( $tmp_file );
            return new WP_Error( 'table_mismatch', "La tabla en el archivo ({$table_in_file}) no coincide con la esperada ({$expected_table})." );
        }

        $restorable = $this->is_restorable( $expected_table );
        if ( is_wp_error( $restorable ) ) {
            $this->cleanup_temp( $tmp_file );
            return $restorable;
        }

        // Read and decompress
        $gz  = gzopen( $tmp_file, 'rb' );
        if ( ! $gz ) {
            $this->cleanup_temp( $tmp_file );
            return new WP_Error( 'gz_open_failed', 'No se pudo leer el archivo de restauración.' );
        }

        $sql_content = '';
        while ( ! gzeof( $gz ) ) {
            $sql_content .= gzread( $gz, 65536 );
        }
        gzclose( $gz );

        // Execute statements
        $statements = $this->split_sql( $sql_content );
        $executed   = 0;
        $errors     = [];

        foreach ( $statements as $stmt ) {
            $stmt = trim( $stmt );
            if ( $stmt === '' ) continue;

            // Only allow statements related to $expected_table; block anything else
            if ( ! $this->is_safe_statement( $stmt, $expected_table ) ) {
                $errors[] = 'Sentencia omitida (tabla no autorizada): ' . substr( $stmt, 0, 80 );
                continue;
            }

            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $result = $wpdb->query( $stmt );
            if ( $result === false ) {
                $errors[] = $wpdb->last_error . ' → ' . substr( $stmt, 0, 100 );
            } else {
                $executed++;
            }
        }

        $this->cleanup_temp( $tmp_file );

        $detail = "Sentencias ejecutadas: {$executed}";
        if ( ! empty( $errors ) ) {
            $detail .= ', Errores: ' . implode( ' | ', array_slice( $errors, 0, 3 ) );
        }

        $result_status = empty( $errors ) ? 'success' : 'partial';
        $this->audit->log( $expected_table, 'restore', $detail, $result_status );

        return [
            'executed' => $executed,
            'errors'   => $errors,
            'table'    => $expected_table,
            'meta'     => $meta,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function split_sql( string $sql ): array {
        // Split on ; at end of line, handling multi-line INSERT VALUES
        $statements = [];
        $current    = '';
        $in_string  = false;
        $str_char   = '';
        $len        = strlen( $sql );

        for ( $i = 0; $i < $len; $i++ ) {
            $char = $sql[ $i ];

            if ( $in_string ) {
                $current .= $char;
                if ( $char === '\\' ) {
                    $current .= $sql[ ++$i ] ?? '';
                } elseif ( $char === $str_char ) {
                    $in_string = false;
                }
            } elseif ( $char === "'" || $char === '"' ) {
                $in_string = true;
                $str_char  = $char;
                $current  .= $char;
            } elseif ( $char === ';' ) {
                $statements[] = trim( $current );
                $current      = '';
            } else {
                $current .= $char;
            }
        }

        if ( trim( $current ) !== '' ) {
            $statements[] = trim( $current );
        }

        return $statements;
    }

    private function is_safe_statement( string $stmt, string $allowed_table ): bool {
        $upper = strtoupper( ltrim( $stmt ) );

        // Allow SET statements (e.g. SET FOREIGN_KEY_CHECKS=0)
        if ( str_starts_with( $upper, 'SET ' ) ) return true;

        // Comments
        if ( str_starts_with( $stmt, '--' ) ) return true;

        // Allow only statements that reference the allowed table
        // Simple check: table name must appear in statement
        if ( strpos( $stmt, "`{$allowed_table}`" ) !== false || strpos( $stmt, $allowed_table ) !== false ) {
            // Block potentially dangerous keywords
            $blocked = [ 'DROP DATABASE', 'DROP SCHEMA', 'GRANT ', 'REVOKE ', 'CREATE USER', 'ALTER USER' ];
            foreach ( $blocked as $kw ) {
                if ( str_contains( $upper, $kw ) ) return false;
            }
            return true;
        }

        return false;
    }

    private function cleanup_temp( string $file ): void {
        if ( file_exists( $file ) ) {
            @unlink( $file );
        }
    }

    private function read_bytes( string $file, int $n ): string {
        $fp = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        if ( ! $fp ) return '';
        $bytes = fread( $fp, $n ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
        return $bytes ?: '';
    }
}
