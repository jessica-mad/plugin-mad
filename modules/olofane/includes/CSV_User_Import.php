<?php
/**
 * CSV User Import — sube un CSV de contactos y crea usuarios de WordPress
 * en lote, con el rol elegido y algunos metadatos de contacto.
 *
 * Pensada para importaciones puntuales (listas de profesionales, etc.)
 * sin depender de WP-CLI ni de un plugin externo.
 *
 * @package MAD_Suite/Olofane
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class MAD_Olofane_CSV_User_Import {

    private const NONCE_ACTION = 'mad_csv_user_import';

    /** Columnas del CSV que se guardan como meta de WooCommerce (billing). */
    private const WC_BILLING_COLUMNS = [
        'billing_phone'   => 'billing_phone',
        'billing_company' => 'billing_company',
    ];

    /** Columnas del CSV que se guardan como meta propio (sin uso conocido fuera de esta pantalla). */
    private const EXTRA_META_COLUMNS = [
        'telefono_secundario' => 'mad_telefono_secundario',
        'email_secundario'    => 'mad_email_secundario',
        'notas'                => 'mad_notas',
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

        $results = null;

        if (
            isset( $_POST['mad_csv_import_submit'] ) &&
            check_admin_referer( self::NONCE_ACTION, 'mad_csv_import_nonce' )
        ) {
            $results = $this->handle_upload();
        }

        $all_roles = wp_roles()->get_names();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Importar usuarios desde CSV', 'mad-suite' ); ?></h1>

            <p><?php esc_html_e( 'Sube un CSV para crear usuarios en lote. Columnas reconocidas: user_login, user_email (obligatorias), first_name, last_name, display_name, role, user_pass, billing_phone, billing_company, telefono_secundario, email_secundario, notas. Cualquier fila con la columna "REVISAR" rellena se omite automáticamente.', 'mad-suite' ); ?></p>

            <?php if ( is_array( $results ) ) : $this->render_results( $results ); endif; ?>

            <form method="post" enctype="multipart/form-data" style="margin-top:20px;">
                <?php wp_nonce_field( self::NONCE_ACTION, 'mad_csv_import_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="mad_csv_file"><?php esc_html_e( 'Archivo CSV', 'mad-suite' ); ?></label></th>
                        <td><input type="file" name="mad_csv_file" id="mad_csv_file" accept=".csv" required></td>
                    </tr>
                    <tr>
                        <th><label for="mad_default_role"><?php esc_html_e( 'Rol por defecto', 'mad-suite' ); ?></label></th>
                        <td>
                            <select name="mad_default_role" id="mad_default_role">
                                <?php foreach ( $all_roles as $slug => $label ) : ?>
                                    <option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label . ' (' . $slug . ')' ); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description"><?php esc_html_e( 'Se usa solo para las filas donde la columna "role" del CSV venga vacía. Si el CSV trae un valor en "role", ese valor manda (debe coincidir con el slug exacto de un rol existente).', 'mad-suite' ); ?></p>
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
                <?php submit_button( __( 'Importar', 'mad-suite' ), 'primary', 'mad_csv_import_submit' ); ?>
            </form>
        </div>
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

    // ── Processing ────────────────────────────────────────────────────────────

    private function handle_upload(): array {
        $results = [];

        if ( empty( $_FILES['mad_csv_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['mad_csv_file']['tmp_name'] ) ) {
            return [ [ 'line' => 0, 'login' => '', 'email' => '', 'status' => 'error', 'detail' => __( 'No se recibió ningún archivo.', 'mad-suite' ) ] ];
        }

        $default_role = sanitize_key( wp_unslash( $_POST['mad_default_role'] ?? '' ) );
        $send_email   = ! empty( $_POST['mad_send_email'] );
        $valid_roles  = wp_roles()->roles;

        $handle = fopen( $_FILES['mad_csv_file']['tmp_name'], 'r' );
        if ( ! $handle ) {
            return [ [ 'line' => 0, 'login' => '', 'email' => '', 'status' => 'error', 'detail' => __( 'No se pudo leer el archivo.', 'mad-suite' ) ] ];
        }

        $header = fgetcsv( $handle );
        if ( ! $header ) {
            fclose( $handle );
            return [ [ 'line' => 0, 'login' => '', 'email' => '', 'status' => 'error', 'detail' => __( 'El CSV está vacío.', 'mad-suite' ) ] ];
        }

        // Quita un posible BOM de UTF-8 en la primera columna del encabezado.
        $header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
        $header    = array_map( 'trim', $header );

        if ( ! in_array( 'user_login', $header, true ) || ! in_array( 'user_email', $header, true ) ) {
            fclose( $handle );
            return [ [ 'line' => 0, 'login' => '', 'email' => '', 'status' => 'error', 'detail' => __( 'El CSV debe tener columnas "user_login" y "user_email".', 'mad-suite' ) ] ];
        }

        $line = 1;
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            $line++;

            if ( 1 === count( $row ) && null === $row[0] ) {
                continue; // línea vacía
            }

            $data = [];
            foreach ( $header as $i => $col_name ) {
                $data[ $col_name ] = isset( $row[ $i ] ) ? trim( (string) $row[ $i ] ) : '';
            }

            $login = sanitize_user( $data['user_login'] ?? '', true );
            $email = sanitize_email( $data['user_email'] ?? '' );

            if ( '' === $login || '' === $email ) {
                $results[] = [ 'line' => $line, 'login' => $login, 'email' => $email, 'status' => 'error', 'detail' => __( 'Faltan user_login o user_email.', 'mad-suite' ) ];
                continue;
            }

            if ( ! is_email( $email ) ) {
                $results[] = [ 'line' => $line, 'login' => $login, 'email' => $email, 'status' => 'error', 'detail' => __( 'Email inválido.', 'mad-suite' ) ];
                continue;
            }

            if ( ! empty( $data['REVISAR'] ) ) {
                $results[] = [ 'line' => $line, 'login' => $login, 'email' => $email, 'status' => 'omitido', 'detail' => sprintf( /* translators: %s: contenido de la columna REVISAR */ __( 'Marcada para revisar: %s', 'mad-suite' ), $data['REVISAR'] ) ];
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

            foreach ( self::WC_BILLING_COLUMNS as $csv_col => $meta_key ) {
                if ( ! empty( $data[ $csv_col ] ) ) {
                    update_user_meta( $user_id, $meta_key, sanitize_text_field( $data[ $csv_col ] ) );
                }
            }

            foreach ( self::EXTRA_META_COLUMNS as $csv_col => $meta_key ) {
                if ( ! empty( $data[ $csv_col ] ) ) {
                    update_user_meta( $user_id, $meta_key, sanitize_text_field( $data[ $csv_col ] ) );
                }
            }

            if ( $send_email ) {
                wp_new_user_notification( $user_id, null, 'user' );
            }

            $results[] = [ 'line' => $line, 'login' => $login, 'email' => $email, 'status' => 'creado', 'detail' => sprintf( /* translators: %s: rol asignado */ __( 'Rol asignado: %s', 'mad-suite' ), $role ) ];
        }

        fclose( $handle );

        return $results;
    }
}
