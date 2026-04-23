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
        // Concede la capacidad nativa de WordPress para leer posts privados del tipo product
        add_filter('user_has_cap', [$this, 'grant_private_product_cap'], 10, 4);

        // WooCommerce fuerza post_status='publish' en sus queries, así que añadimos
        // 'private' explícitamente para los usuarios con acceso
        add_action('pre_get_posts', [$this, 'include_private_products_in_query'], 999);

        // WooCommerce marca productos privados como no comprables por defecto;
        // hay que habilitarlo para los roles con acceso
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
        if (is_admin() || ! $query->is_main_query()) return;
        if (! $this->current_user_has_access()) return;

        $post_type = $query->get('post_type');
        $is_product_query = $post_type === 'product'
            || (is_array($post_type) && in_array('product', $post_type, true));

        if (! $is_product_query) return;

        $statuses = $query->get('post_status') ?: 'publish';
        if (is_string($statuses)) {
            $statuses = [$statuses];
        }
        if (! in_array('private', $statuses, true)) {
            $statuses[] = 'private';
        }
        $query->set('post_status', $statuses);
    }

    public function allow_private_product_purchase(bool $purchasable, \WC_Product $product): bool {
        if ($purchasable) return $purchasable;
        if ($product->get_status() !== 'private') return $purchasable;
        return $this->current_user_has_access();
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
