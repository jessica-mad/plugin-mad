<?php
if (! defined('ABSPATH')) exit;

return new class ($core ?? null) implements MAD_Suite_Module {

    private const OPTION_KEY     = 'madsuite_role_visibility_settings';
    private const NONCE_SETTINGS = 'mads_rv_save_settings';

    public function __construct($core) {}

    public function slug()        { return 'role-visibility'; }
    public function title()       { return __('Visibilidad por Rol', 'mad-suite'); }
    public function menu_label()  { return __('Visibilidad por Rol', 'mad-suite'); }
    public function menu_slug()   { return 'mad-suite-role-visibility'; }
    public function description() { return __('Permite que roles específicos vean los productos privados de WooCommerce.', 'mad-suite'); }
    public function required_plugins() { return ['WooCommerce' => 'woocommerce/woocommerce.php']; }

    private function get_allowed_roles(): array {
        $settings = get_option(self::OPTION_KEY, []);
        return isset($settings['allowed_roles']) ? (array) $settings['allowed_roles'] : [];
    }

    private function current_user_has_access(): bool {
        if (current_user_can('manage_options')) return true;
        $user = wp_get_current_user();
        if (! $user || ! $user->ID) return false;
        $allowed = $this->get_allowed_roles();
        if (empty($allowed)) return false;
        return (bool) array_intersect((array) $user->roles, $allowed);
    }

    // ── Hooks públicos ──────────────────────────────────────────────────────
    public function init(): void {
        // Capacidad nativa de WordPress para acceso directo por URL
        add_filter('user_has_cap', [$this, 'grant_private_product_cap'], 10, 4);

        // Intento 1: modificar los args antes de que WP construya el SQL
        add_action('pre_get_posts', [$this, 'include_private_products_in_query'], 999);
        add_action('woocommerce_product_query', [$this, 'include_private_products_in_wc_query']);

        // Safety net: modifica el SQL ya construido (funciona incluso si WooCommerce
        // fuerza post_status='publish' después de nuestro pre_get_posts)
        add_filter('posts_where', [$this, 'ensure_private_in_where'], PHP_INT_MAX, 2);

        // WooCommerce marca productos privados como no comprables por defecto
        add_filter('woocommerce_is_purchasable', [$this, 'allow_private_product_purchase'], 10, 2);
    }

    public function grant_private_product_cap(array $allcaps, array $caps, array $args, \WP_User $user): array {
        if (! empty($allcaps['read_private_products'])) return $allcaps;

        $allowed = $this->get_allowed_roles();
        if (empty($allowed)) return $allcaps;

        if (! empty(array_intersect((array) $user->roles, $allowed))) {
            $allcaps['read_private_products'] = true;
        }

        return $allcaps;
    }

    public function include_private_products_in_query(\WP_Query $query): void {
        if (is_admin()) return;
        if (! $this->current_user_has_access()) return;
        if (! $this->is_product_query($query)) return;
        $this->add_private_status($query);
    }

    private function is_product_query(\WP_Query $query): bool {
        $post_type = $query->get('post_type');
        if ($post_type === 'product'
            || (is_array($post_type) && in_array('product', $post_type, true))) {
            return true;
        }

        // En páginas de taxonomía (product_tag, product_cat, pa_*) post_type
        // puede estar vacío; lo detectamos por la tax_query
        foreach ((array) $query->get('tax_query') as $tax) {
            if (! isset($tax['taxonomy'])) continue;
            $t = (string) $tax['taxonomy'];
            if (in_array($t, ['product_cat', 'product_tag', 'product_shipping_class'], true)
                || strpos($t, 'pa_') === 0) {
                return true;
            }
        }

        return false;
    }

    public function include_private_products_in_wc_query(\WP_Query $query): void {
        if (! $this->current_user_has_access()) return;
        $this->add_private_status($query);
    }

    private function add_private_status(\WP_Query $query): void {
        $statuses = $query->get('post_status') ?: 'publish';
        if (is_string($statuses)) {
            $statuses = [$statuses];
        }
        if (! in_array('private', $statuses, true)) {
            $statuses[] = 'private';
            $query->set('post_status', $statuses);
        }
    }

    public function allow_private_product_purchase(bool $purchasable, \WC_Product $product): bool {
        if ($purchasable) return $purchasable;
        if ($product->get_status() !== 'private') return $purchasable;
        return $this->current_user_has_access();
    }

    public function ensure_private_in_where(string $where, \WP_Query $query): string {
        global $wpdb;

        if (is_admin()) return $where;
        if (! $this->current_user_has_access()) return $where;

        // Detectamos por el SQL construido, no por los args: cubre shop, cat, tag y bloques
        if (strpos($where, $wpdb->posts . ".post_type = 'product'") === false) {
            return $where;
        }

        // Ya incluye privados: nada que hacer
        if (strpos($where, "post_status = 'private'") !== false
            || strpos($where, "post_status IN") !== false) {
            return $where;
        }

        // Reemplaza la restricción publish-only por publish+private directamente en el SQL
        return str_replace(
            $wpdb->posts . ".post_status = 'publish'",
            $wpdb->posts . ".post_status IN ('publish','private')",
            $where
        );
    }

    // ── Hooks de admin ──────────────────────────────────────────────────────
    public function admin_init(): void {
        add_action('admin_post_mads_rv_save_settings', [$this, 'handle_save_settings']);
    }

    public function handle_save_settings(): void {
        if (! current_user_can(MAD_Suite_Core::CAPABILITY)) wp_die('Sin permisos.');
        check_admin_referer(self::NONCE_SETTINGS, 'mads_rv_nonce');

        $allowed_roles = isset($_POST['allowed_roles'])
            ? array_map('sanitize_key', (array) $_POST['allowed_roles'])
            : [];

        $valid_roles   = array_keys(wp_roles()->roles);
        $allowed_roles = array_values(array_intersect($allowed_roles, $valid_roles));

        update_option(self::OPTION_KEY, ['allowed_roles' => $allowed_roles]);

        wp_safe_redirect(
            add_query_arg(['page' => $this->menu_slug(), 'saved' => '1'], admin_url('admin.php'))
        );
        exit;
    }

    // ── Página de ajustes ──────────────────────────────────────────────────
    public function render_settings_page(): void {
        if (! current_user_can(MAD_Suite_Core::CAPABILITY)) wp_die('Sin permisos.');

        $roles         = wp_roles()->roles;
        $allowed_roles = $this->get_allowed_roles();
        $saved         = isset($_GET['saved']) && $_GET['saved'] === '1';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Visibilidad por Rol', 'mad-suite'); ?></h1>

            <?php if ($saved) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Configuración guardada.', 'mad-suite'); ?></p>
                </div>
            <?php endif; ?>

            <p><?php esc_html_e('Selecciona los roles que pueden ver los productos con estado "Privado" en WooCommerce. Los administradores siempre tienen acceso.', 'mad-suite'); ?></p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE_SETTINGS, 'mads_rv_nonce'); ?>
                <input type="hidden" name="action" value="mads_rv_save_settings">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Roles con acceso', 'mad-suite'); ?></th>
                        <td>
                            <?php foreach ($roles as $role_slug => $role_data) : ?>
                                <?php if ($role_slug === 'administrator') continue; ?>
                                <label style="display:block; margin-bottom:6px;">
                                    <input type="checkbox"
                                           name="allowed_roles[]"
                                           value="<?php echo esc_attr($role_slug); ?>"
                                           <?php checked(in_array($role_slug, $allowed_roles, true)); ?>>
                                    <?php echo esc_html(translate_user_role($role_data['name'])); ?>
                                    <code style="font-size:11px;color:#666;">(<?php echo esc_html($role_slug); ?>)</code>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">
                                <?php esc_html_e('Los roles creados con el Gestor de Roles aparecen aquí automáticamente.', 'mad-suite'); ?>
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
