<?php
if (! defined('ABSPATH')) exit;

return new class ($core ?? null) implements MAD_Suite_Module {

    private const OPTION_KEY     = 'madsuite_role_password_settings';
    private const NONCE_SETTINGS = 'mads_rp_save_settings';

    private array $wc_product_queries = [];

    public function __construct($core) {}

    public function slug()        { return 'role-password'; }
    public function title()       { return __('Acceso por Rol (Contraseña)', 'mad-suite'); }
    public function menu_label()  { return __('Acceso por Rol', 'mad-suite'); }
    public function menu_slug()   { return 'mad-suite-role-password'; }
    public function description() { return __('Otorga acceso automático a productos protegidos con contraseña a usuarios con roles específicos.', 'mad-suite'); }
    public function required_plugins() { return ['WooCommerce' => 'woocommerce/woocommerce.php']; }

    // ── Helpers ─────────────────────────────────────────────────────────────
    private function get_settings(): array {
        return get_option(self::OPTION_KEY, []);
    }

    private function get_allowed_roles(): array {
        $s = $this->get_settings();
        return isset($s['allowed_roles']) ? (array) $s['allowed_roles'] : [];
    }

    private function get_master_password(): string {
        $s = $this->get_settings();
        return $s['master_password'] ?? '';
    }

    private function ensure_master_password(): string {
        $s = $this->get_settings();
        if (empty($s['master_password'])) {
            $s['master_password'] = wp_generate_password(32, false);
            update_option(self::OPTION_KEY, $s);
        }
        return $s['master_password'];
    }

    private function current_user_has_access(): bool {
        static $cache = null;
        if ($cache !== null) return $cache;

        if (current_user_can('manage_options')) return $cache = true;

        $user    = wp_get_current_user();
        $allowed = $this->get_allowed_roles();

        if (! $user || ! $user->ID || empty($allowed)) return $cache = false;

        return $cache = (bool) array_intersect((array) $user->roles, $allowed);
    }

    private function is_product_query(\WP_Query $query): bool {
        $post_type = $query->get('post_type');
        if ($post_type === 'product'
            || (is_array($post_type) && in_array('product', $post_type, true))) {
            return true;
        }
        if ($query->is_tax('product_tag') || $query->is_tax('product_cat')) return true;
        return isset($this->wc_product_queries[spl_object_hash($query)]);
    }

    private function is_module_password(string $password): bool {
        $master = $this->get_master_password();
        return $master !== '' && $password === $master;
    }

    // ── Hooks públicos ──────────────────────────────────────────────────────
    public function init(): void {
        // Bypass automático de contraseña para roles permitidos
        add_filter('post_password_required', [$this, 'bypass_password_for_role'], 9999, 2);

        // Ocultar productos con la contraseña maestra del catálogo para usuarios sin acceso
        add_action('pre_get_posts',             [$this, 'exclude_protected_from_query'],    999);
        add_action('woocommerce_product_query', [$this, 'exclude_protected_from_wc_query']);
        add_filter('woocommerce_product_is_visible', [$this, 'hide_protected_product'], 9999, 2);
    }

    public function bypass_password_for_role(bool $required, $post): bool {
        if (! $required) return false;
        if (! $post || $post->post_type !== 'product') return $required;
        if (empty($post->post_password)) return $required;
        if (! $this->is_module_password($post->post_password)) return $required;
        if ($this->current_user_has_access()) return false;
        return $required;
    }

    public function exclude_protected_from_query(\WP_Query $query): void {
        if (is_admin()) return;
        if ($this->current_user_has_access()) return;
        if (! $this->is_product_query($query)) return;
        $query->set('has_password', false);
    }

    public function exclude_protected_from_wc_query(\WP_Query $query): void {
        if ($this->current_user_has_access()) return;
        $query->set('has_password', false);
        $this->wc_product_queries[spl_object_hash($query)] = true;
    }

    public function hide_protected_product(bool $visible, int $product_id): bool {
        if (! $visible) return false;
        $post = get_post($product_id);
        if (! $post || empty($post->post_password)) return $visible;
        if (! $this->is_module_password($post->post_password)) return $visible;
        return $this->current_user_has_access();
    }

    // ── Admin ───────────────────────────────────────────────────────────────
    public function admin_init(): void {
        add_action('admin_post_mads_rp_save_settings', [$this, 'handle_save_settings']);
    }

    public function handle_save_settings(): void {
        if (! current_user_can(MAD_Suite_Core::CAPABILITY)) wp_die('Sin permisos.');
        check_admin_referer(self::NONCE_SETTINGS, 'mads_rp_nonce');

        $allowed_roles = isset($_POST['allowed_roles'])
            ? array_map('sanitize_key', (array) $_POST['allowed_roles'])
            : [];
        $valid_roles   = array_keys(wp_roles()->roles);
        $allowed_roles = array_values(array_intersect($allowed_roles, $valid_roles));

        $s = $this->get_settings();
        $s['allowed_roles'] = $allowed_roles;

        $new_password = isset($_POST['master_password']) ? trim($_POST['master_password']) : '';
        if ($new_password !== '') {
            $s['master_password'] = sanitize_text_field($new_password);
        }

        update_option(self::OPTION_KEY, $s);

        wp_safe_redirect(
            add_query_arg(['page' => $this->menu_slug(), 'saved' => '1'], admin_url('admin.php'))
        );
        exit;
    }

    // ── Página de ajustes ──────────────────────────────────────────────────
    public function render_settings_page(): void {
        if (! current_user_can(MAD_Suite_Core::CAPABILITY)) wp_die('Sin permisos.');

        $password      = $this->ensure_master_password();
        $roles         = wp_roles()->roles;
        $allowed_roles = $this->get_allowed_roles();
        $saved         = isset($_GET['saved']) && $_GET['saved'] === '1';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Acceso por Rol (Contraseña)', 'mad-suite'); ?></h1>

            <?php if ($saved) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Configuración guardada.', 'mad-suite'); ?></p>
                </div>
            <?php endif; ?>

            <p><?php esc_html_e('Los productos de WooCommerce con la contraseña maestra serán accesibles automáticamente para los roles seleccionados, sin que el usuario tenga que introducirla manualmente. Para el resto de visitantes, el producto no aparece en la tienda.', 'mad-suite'); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_SETTINGS, 'mads_rp_nonce'); ?>
                <input type="hidden" name="action" value="mads_rp_save_settings">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Roles con acceso', 'mad-suite'); ?></th>
                        <td>
                            <?php foreach ($roles as $role_slug => $role_data) : ?>
                                <?php if ($role_slug === 'administrator') continue; ?>
                                <label style="display:block;margin-bottom:6px;">
                                    <input type="checkbox"
                                           name="allowed_roles[]"
                                           value="<?php echo esc_attr($role_slug); ?>"
                                           <?php checked(in_array($role_slug, $allowed_roles, true)); ?>>
                                    <?php echo esc_html(translate_user_role($role_data['name'])); ?>
                                    <code style="font-size:11px;color:#666;">(<?php echo esc_html($role_slug); ?>)</code>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">
                                <?php esc_html_e('Los administradores siempre tienen acceso.', 'mad-suite'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row"><?php esc_html_e('Contraseña maestra', 'mad-suite'); ?></th>
                        <td>
                            <input type="text"
                                   id="mads-rp-password"
                                   name="master_password"
                                   value="<?php echo esc_attr($password); ?>"
                                   class="regular-text"
                                   style="font-family:monospace;">
                            <button type="button"
                                    class="button button-secondary"
                                    style="vertical-align:middle;"
                                    onclick="
                                        var c = '<?php echo esc_js(substr(str_shuffle('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'), 0, 32)); ?>';
                                        var chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                                        var pass = '';
                                        if (window.crypto && window.crypto.getRandomValues) {
                                            var arr = new Uint8Array(32);
                                            window.crypto.getRandomValues(arr);
                                            arr.forEach(function(b){ pass += chars[b % chars.length]; });
                                        } else {
                                            for(var i=0;i<32;i++) pass += chars[Math.floor(Math.random()*chars.length)];
                                        }
                                        document.getElementById('mads-rp-password').value = pass;
                                    ">
                                <?php esc_html_e('Generar aleatoria', 'mad-suite'); ?>
                            </button>
                            <p class="description" style="margin-top:8px;">
                                <?php esc_html_e('Pega esta contraseña en el campo "Contraseña" de cada producto de WooCommerce que quieras proteger (Editar producto → Visibilidad del catálogo → Contraseña). Si dejas el campo vacío al guardar, se mantiene la contraseña actual.', 'mad-suite'); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Guardar cambios', 'mad-suite')); ?>
            </form>
        </div>
        <?php
    }
};
