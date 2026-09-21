<?php
/**
 * CSV User Import — sube un CSV de contactos, permite mapear cada columna
 * a un campo de usuario/WooCommerce, y crea los usuarios en lote.
 *
 * Pensada para importaciones puntuales (listas de profesionales, etc.)
 * sin depender de WP-CLI ni de un plugin externo.
 *
 * Flujo en dos pasos:
 *  1) Subir CSV → se guarda temporalmente y se muestra un mapeo de columnas.
 *  2) Confirmar mapeo → se procesa el archivo temporal y se crean los usuarios.
 *
 * @package MAD_Suite/Olofane
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Olofane_CSV_User_Import {

    private const NONCE_UPLOAD  = 'mad_csv_user_import_upload';
    private const NONCE_CONFIRM = 'mad_csv_user_import_confirm';
    private const TMP_PREFIX    = 'mad-csv-import-';
    private const TMP_MAX_AGE   = 3600; // 1 hora

    /** Campos disponibles para mapear cada columna del CSV. */
    private const TARGET_FIELDS = [
        ''                        => '— No importar —',
        'user_login'              => 'Usuario (login)',
        'user_email'              => 'Email',
        'first_name'              => 'Nombre',
        'last_name'               => 'Apellidos',
        'display_name'            => 'Nombre para mostrar',
        'role'                    => 'Rol (debe coincidir con un rol existente)',
        'user_pass'               => 'Contraseña',
        'billing_phone'           => 'Teléfono',
        'billing_company'         => 'Empresa',
        'mad_telefono_secundario' => 'Teléfono secundario',
        'mad_email_secundario'    => 'Email secundario',
        'mad_notas'               => 'Notas',
        'revisar'                 => 'Marcar para revisar (se omite si no está vacía)',
    ];

    /** Meta keys de usuario a las que se guardan algunos campos mapeados. */
    private const META_TARGETS = [
        'billing_phone'           => 'billing_phone',
        'billing_company'         => 'billing_company',
        'mad_telefono_secundario' => 'mad_telefono_secundario',
        'mad_email_secundario'    => 'mad_email_secundario',
        'mad_notas'               => 'mad_notas',
    ];

    /** Sinónimos de encabezado para pre-seleccionar el mapeo automáticamente. */
    private const AUTO_MAP_GUESSES = [
        'user_login'              => [ 'user_login', 'login', 'usuario' ],
        'user_email'              => [ 'user_email', 'email', 'correo', 'e-mail' ],
        'first_name'              => [ 'first_name', 'nombre' ],
        'last_name'               => [ 'last_name', 'apellido', 'apellidos' ],
        'display_name'            => [ 'display_name', 'nombre_completo', 'nombre completo' ],
        'role'                    => [ 'role', 'rol' ],
        'user_pass'               => [ 'user_pass', 'password', 'contraseña', 'clave' ],
        'billing_phone'           => [ 'billing_phone', 'telefono', 'teléfono', 'phone' ],
        'billing_company'         => [ 'billing_company', 'empresa', 'company' ],
        'mad_telefono_secundario' => [ 'telefono_secundario', 'teléfono_secundario' ],
        'mad_email_secundario'    => [ 'email_secundario' ],
        'mad_notas'               => [ 'notas', 'notes' ],
        'revisar'                 => [ 'revisar', 'review' ],
    ];

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'add_submenu' ] );
    }

    // ── Admin page ────────────────────────────────────────────────────────────

    public function add_submenu(): void {
        add_submenu_page(
            'users.php',
            __( 'Importar usuarios (CSV)', 'mad-suite' ),
            __( 'Importar CSV', 'mad-suite' ),
            'create_users',
            'mad-csv-user-import',
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'create_users' ) ) {
            wp_die( esc_html__( 'No tienes permisos suficientes.', 'mad-suite' ) );
        }

        $this->cleanup_old_tmp_files();

        $results = null;
        $mapping_view = null;

        if ( isset( $_POST['mad_csv_upload_submit'] ) && check_admin_referer( self::NONCE_UPLOAD, 'mad_csv_upload_nonce' ) ) {
            $mapping_view = $this->handle_upload();
        } elseif ( isset( $_POST['mad_csv_confirm_submit'] ) && check_admin_referer( self::NONCE_CONFIRM, 'mad_csv_confirm_nonce' ) ) {
            $outcome = $this->handle_confirm();
            if ( isset( $outcome['mapping_view'] ) ) {
                $mapping_view = $outcome['mapping_view']; // volvió a la vista de mapeo por un error de validación
            } else {
                $results = $outcome['results'];
            }
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Importar usuarios desde CSV', 'mad-suite' ); ?></h1>

            <?php if ( is_array( $results ) ) : $this->render_results( $results ); ?>
                <p><a href="<?php echo esc_url( admin_url( 'users.php?page=mad-csv-user-import' ) ); ?>" class="button"><?php esc_html_e( '← Importar otro archivo', 'mad-suite' ); ?></a></p>
            <?php elseif ( is_array( $mapping_view ) ) :
                $this->render_mapping_form( $mapping_view );
            else :
                $this->render_upload_form();
            endif; ?>
        </div>
        <?php
    }

    private function render_upload_form(): void {
        ?>
        <p><?php esc_html_e( 'Paso 1 de 2: subí el CSV. En el paso siguiente vas a poder elegir qué columna corresponde a cada dato antes de importar nada.', 'mad-suite' ); ?></p>

        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field( self::NONCE_UPLOAD, 'mad_csv_upload_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="mad_csv_file"><?php esc_html_e( 'Archivo CSV', 'mad-suite' ); ?></label></th>
                    <td><input type="file" name="mad_csv_file" id="mad_csv_file" accept=".csv" required></td>
                </tr>
                <tr>
                    <th><label for="mad_default_role"><?php esc_html_e( 'Rol por defecto', 'mad-suite' ); ?></label></th>
                    <td>
                        <select name="mad_default_role" id="mad_default_role">
                            <?php foreach ( wp_roles()->get_names() as $slug => $label ) : ?>
                                <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label . ' (' . $slug . ')' ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Se usa para las filas donde no mapees ninguna columna a "Rol", o esa columna venga vacía.', 'mad-suite' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Aviso por email', 'mad-suite' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="mad_send_email" value="1">
                            <?php esc_html_e( 'Enviar el email de bienvenida de WordPress (para definir contraseña) a cada usuario creado', 'mad-suite' ); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button( __( 'Subir y elegir columnas →', 'mad-suite' ), 'primary', 'mad_csv_upload_submit' ); ?>
        </form>
        <?php
    }

    private function render_mapping_form( array $view ): void {
        ?>
        <p>
            <?php
            printf(
                /* translators: %d: número de columnas detectadas */
                esc_html__( 'Paso 2 de 2: se detectaron %d columnas en el archivo. Elegí a qué campo corresponde cada una (o "No importar" para ignorarla) y confirmá.', 'mad-suite' ),
                count( $view['headers'] )
            );
            ?>
        </p>
        <?php if ( ! empty( $view['error'] ) ) : ?>
            <div class="notice notice-error"><p><?php echo esc_html( $view['error'] ); ?></p></div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field( self::NONCE_CONFIRM, 'mad_csv_confirm_nonce' ); ?>
            <input type="hidden" name="mad_token" value="<?php echo esc_attr( $view['token'] ); ?>">
            <input type="hidden" name="mad_default_role" value="<?php echo esc_attr( $view['default_role'] ); ?>">
            <input type="hidden" name="mad_send_email" value="<?php echo esc_attr( $view['send_email'] ? '1' : '0' ); ?>">

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Columna del CSV', 'mad-suite' ); ?></th>
                        <th><?php esc_html_e( 'Ejemplo (primera fila)', 'mad-suite' ); ?></th>
                        <th><?php esc_html_e( 'Se importa como…', 'mad-suite' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $view['headers'] as $i => $header_name ) :
                    $current = $view['mapping'][ $i ] ?? '';
                ?>
                    <tr>
                        <td><strong><?php echo esc_html( $header_name ); ?></strong></td>
                        <td><code><?php echo esc_html( $view['sample'][ $i ] ?? '' ); ?></code></td>
                        <td>
                            <select name="mad_col_map[<?php echo (int) $i; ?>]">
                                <?php foreach ( self::TARGET_FIELDS as $key => $label ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <p class="description"><?php esc_html_e( 'Tenés que mapear exactamente una columna a "Usuario (login)" y una a "Email" — son obligatorias para crear la cuenta.', 'mad-suite' ); ?></p>

            <?php submit_button( __( 'Importar con este mapeo', 'mad-suite' ), 'primary', 'mad_csv_confirm_submit' ); ?>
        </form>
        <?php
    }

    private function render_results( array $results ): void {
        $created = count( array_filter( $results, fn( $r ) => 'creado' === $r['status'] ) );
        $skipped = count( array_filter( $results, fn( $r ) => 'omitido' === $r['status'] ) );
        $errors  = count( array_filter( $results, fn( $r ) => 'error' === $r['status'] ) );
        ?>
        <div class="notice notice-<?php echo $errors ? 'warning' : 'success'; ?>">
            <p>
                <?php
                printf(
                    /* translators: 1: creados 2: omitidos 3: errores */
                    esc_html__( 'Importación terminada: %1$d creados, %2$d omitidos, %3$d con error.', 'mad-suite' ),
                    (int) $created,
                    (int) $skipped,
                    (int) $errors
                );
                ?>
            </p>
        </div>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:60px;"><?php esc_html_e( 'Fila', 'mad-suite' ); ?></th>
                    <th><?php esc_html_e( 'Usuario', 'mad-suite' ); ?></th>
                    <th><?php esc_html_e( 'Email', 'mad-suite' ); ?></th>
                    <th style="width:100px;"><?php esc_html_e( 'Estado', 'mad-suite' ); ?></th>
                    <th><?php esc_html_e( 'Detalle', 'mad-suite' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $results as $r ) : ?>
                <tr>
                    <td><?php echo (int) $r['line']; ?></td>
                    <td><?php echo esc_html( $r['login'] ); ?></td>
                    <td><?php echo esc_html( $r['email'] ); ?></td>
                    <td>
                        <?php if ( 'creado' === $r['status'] ) : ?>
                            <span style="color:green;">✔ <?php esc_html_e( 'Creado', 'mad-suite' ); ?></span>
                        <?php elseif ( 'omitido' === $r['status'] ) : ?>
                            <span style="color:#b26a00;">— <?php esc_html_e( 'Omitido', 'mad-suite' ); ?></span>
                        <?php else : ?>
                            <span style="color:#c00;">✘ <?php esc_html_e( 'Error', 'mad-suite' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $r['detail'] ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    // ── Paso 1: subir y proponer mapeo ───────────────────────────────────────

    private function handle_upload(): array {
        if ( empty( $_FILES['mad_csv_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['mad_csv_file']['tmp_name'] ) ) {
            return [ 'headers' => [], 'sample' => [], 'mapping' => [], 'token' => '', 'default_role' => '', 'send_email' => false, 'error' => __( 'No se recibió ningún archivo.', 'mad-suite' ) ];
        }

        $token     = wp_generate_uuid4();
        $tmp_path  = $this->tmp_path( $token );

        if ( ! move_uploaded_file( $_FILES['mad_csv_file']['tmp_name'], $tmp_path ) ) {
            return [ 'headers' => [], 'sample' => [], 'mapping' => [], 'token' => '', 'default_role' => '', 'send_email' => false, 'error' => __( 'No se pudo guardar el archivo temporalmente.', 'mad-suite' ) ];
        }

        $handle = fopen( $tmp_path, 'r' );
        $header = $handle ? fgetcsv( $handle ) : false;
        $sample = $handle ? fgetcsv( $handle ) : false;
        if ( $handle ) fclose( $handle );

        if ( ! $header ) {
            unlink( $tmp_path );
            return [ 'headers' => [], 'sample' => [], 'mapping' => [], 'token' => '', 'default_role' => '', 'send_email' => false, 'error' => __( 'El CSV está vacío o no se pudo leer.', 'mad-suite' ) ];
        }

        $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
        $header    = array_map( 'trim', $header );

        return [
            'headers'      => $header,
            'sample'       => is_array( $sample ) ? $sample : [],
            'mapping'      => $this->guess_mapping( $header ),
            'token'        => $token,
            'default_role' => sanitize_key( wp_unslash( $_POST['mad_default_role'] ?? '' ) ),
            'send_email'   => ! empty( $_POST['mad_send_email'] ),
        ];
    }

    private function guess_mapping( array $header ): array {
        $mapping = [];
        foreach ( $header as $i => $name ) {
            $needle = strtolower( trim( $name ) );
            foreach ( self::AUTO_MAP_GUESSES as $target => $synonyms ) {
                if ( in_array( $needle, $synonyms, true ) ) {
                    $mapping[ $i ] = $target;
                    break;
                }
            }
        }
        return $mapping;
    }

    // ── Paso 2: confirmar mapeo y procesar ───────────────────────────────────

    private function handle_confirm(): array {
        $token        = preg_replace( '/[^a-f0-9-]/', '', wp_unslash( $_POST['mad_token'] ?? '' ) );
        $default_role = sanitize_key( wp_unslash( $_POST['mad_default_role'] ?? '' ) );
        $send_email   = '1' === ( $_POST['mad_send_email'] ?? '0' );
        $col_map_raw  = (array) ( $_POST['mad_col_map'] ?? [] );

        $tmp_path = $this->tmp_path( $token );
        if ( '' === $token || ! file_exists( $tmp_path ) ) {
            return [ 'results' => [ [ 'line' => 0, 'login' => '', 'email' => '', 'status' => 'error', 'detail' => __( 'El archivo temporal expiró o no existe. Subí el CSV de nuevo.', 'mad-suite' ) ] ] ];
        }

        $handle = fopen( $tmp_path, 'r' );
        $header = $handle ? fgetcsv( $handle ) : false;
        if ( ! $handle || ! $header ) {
            if ( $handle ) fclose( $handle );
            @unlink( $tmp_path );
            return [ 'results' => [ [ 'line' => 0, 'login' => '', 'email' => '', 'status' => 'error', 'detail' => __( 'No se pudo volver a leer el archivo.', 'mad-suite' ) ] ] ];
        }

        // col index => target field
        $col_map = [];
        foreach ( $col_map_raw as $i => $target ) {
            $target = sanitize_key( wp_unslash( $target ) );
            if ( '' !== $target ) {
                $col_map[ (int) $i ] = $target;
            }
        }

        $login_cols = array_keys( $col_map, 'user_login', true );
        $email_cols = array_keys( $col_map, 'user_email', true );

        if ( 1 !== count( $login_cols ) || 1 !== count( $email_cols ) ) {
            fclose( $handle );
            $sample = [];
            $sample_handle = fopen( $tmp_path, 'r' );
            if ( $sample_handle ) {
                fgetcsv( $sample_handle );
                $sample = fgetcsv( $sample_handle ) ?: [];
                fclose( $sample_handle );
            }
            return [
                'mapping_view' => [
                    'headers'      => $header,
                    'sample'       => $sample,
                    'mapping'      => $col_map,
                    'token'        => $token,
                    'default_role' => $default_role,
                    'send_email'   => $send_email,
                    'error'        => __( 'Tenés que mapear exactamente una columna a "Usuario (login)" y una a "Email".', 'mad-suite' ),
                ],
            ];
        }

        $valid_roles = wp_roles()->roles;
        $results     = [];
        $line        = 1;

        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $line++;

            if ( 1 === count( $row ) && null === $row[0] ) {
                continue; // línea vacía
            }

            // target field => valor de esta fila, según el mapeo elegido
            $data = [];
            foreach ( $col_map as $i => $target ) {
                $data[ $target ] = isset( $row[ $i ] ) ? trim( (string) $row[ $i ] ) : '';
            }

            $login = sanitize_user( $data['user_login'] ?? '', true );
            $email = sanitize_email( $data['user_email'] ?? '' );

            if ( '' === $login || '' === $email ) {
                $results[] = [ 'line' => $line, 'login' => $login, 'email' => $email, 'status' => 'error', 'detail' => __( 'Faltan usuario o email.', 'mad-suite' ) ];
                continue;
            }

            if ( ! is_email( $email ) ) {
                $results[] = [ 'line' => $line, 'login' => $login, 'email' => $email, 'status' => 'error', 'detail' => __( 'Email inválido.', 'mad-suite' ) ];
                continue;
            }

            if ( ! empty( $data['revisar'] ) ) {
                $results[] = [ 'line' => $line, 'login' => $login, 'email' => $email, 'status' => 'omitido', 'detail' => sprintf( /* translators: %s: contenido de la columna marcada como "revisar" */ __( 'Marcada para revisar: %s', 'mad-suite' ), $data['revisar'] ) ];
                continue;
            }

            if ( username_exists( $login ) || email_exists( $email ) ) {
                $results[] = [ 'line' => $line, 'login' => $login, 'email' => $email, 'status' => 'omitido', 'detail' => __( 'Ya existe un usuario con ese usuario o email.', 'mad-suite' ) ];
                continue;
            }

            $role_raw = $data['role'] ?? '';
            $role     = sanitize_key( '' !== $role_raw ? $role_raw : $default_role );

            if ( ! isset( $valid_roles[ $role ] ) ) {
                $results[] = [
                    'line'   => $line,
                    'login'  => $login,
                    'email'  => $email,
                    'status' => 'error',
                    'detail' => sprintf(
                        /* translators: 1: rol pedido, 2: lista de roles válidos */
                        __( 'El rol "%1$s" no existe en este sitio. Roles válidos: %2$s', 'mad-suite' ),
                        $role_raw,
                        implode( ', ', array_keys( $valid_roles ) )
                    ),
                ];
                continue;
            }

            $password = $data['user_pass'] ?? '';
            if ( '' === $password ) {
                $password = wp_generate_password( 20, true );
            }

            $userdata = [
                'user_login'   => $login,
                'user_email'   => $email,
                'user_pass'    => $password,
                'first_name'   => sanitize_text_field( $data['first_name'] ?? '' ),
                'last_name'    => sanitize_text_field( $data['last_name'] ?? '' ),
                'display_name' => sanitize_text_field( $data['display_name'] ?? '' ),
                'role'         => $role,
            ];

            $user_id = wp_insert_user( $userdata );

            if ( is_wp_error( $user_id ) ) {
                $results[] = [ 'line' => $line, 'login' => $login, 'email' => $email, 'status' => 'error', 'detail' => $user_id->get_error_message() ];
                continue;
            }

            foreach ( self::META_TARGETS as $target => $meta_key ) {
                if ( ! empty( $data[ $target ] ) ) {
                    update_user_meta( $user_id, $meta_key, sanitize_text_field( $data[ $target ] ) );
                }
            }

            if ( $send_email ) {
                wp_new_user_notification( $user_id, null, 'user' );
            }

            $results[] = [ 'line' => $line, 'login' => $login, 'email' => $email, 'status' => 'creado', 'detail' => sprintf( /* translators: %s: rol asignado */ __( 'Rol asignado: %s', 'mad-suite' ), $role ) ];
        }

        fclose( $handle );
        @unlink( $tmp_path );

        return [ 'results' => $results ];
    }

    // ── Temp file helpers ─────────────────────────────────────────────────────

    private function tmp_path( string $token ): string {
        return trailingslashit( get_temp_dir() ) . self::TMP_PREFIX . $token . '.csv';
    }

    private function cleanup_old_tmp_files(): void {
        $files = glob( trailingslashit( get_temp_dir() ) . self::TMP_PREFIX . '*.csv' );
        if ( ! $files ) return;

        foreach ( $files as $file ) {
            if ( time() - filemtime( $file ) > self::TMP_MAX_AGE ) {
                @unlink( $file );
            }
        }
    }
}
