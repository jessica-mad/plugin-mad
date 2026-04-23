<?php
if (! defined('ABSPATH')) exit;

return new class ($core ?? null) implements MAD_Suite_Module {

    private const OPTION_KEY     = 'madsuite_role_visibility_settings';
    private const NONCE_SETTINGS = 'mads_rv_save_settings';

    /** Datos recogidos durante la request para el panel de debug */
    private array $debug_log = [];

    public function __construct($core) {}

    public function slug()        { return 'role-visibility'; }
    public function title()       { return __('Visibilidad por Rol', 'mad-suite'); }
    public function menu_label()  { return __('Visibilidad por Rol', 'mad-suite'); }
    public function menu_slug()   { return 'mad-suite-role-visibility'; }
    public function description() { return __('Permite que roles específicos vean los productos privados de WooCommerce.', 'mad-suite'); }
    public function required_plugins() { return ['WooCommerce' => 'woocommerce/woocommerce.php']; }

    // ── Debug ───────────────────────────────────────────────────────────────
    private function is_debug(): bool {
        return defined('MADS_RV_DEBUG') && MADS_RV_DEBUG;
    }

    private function log(string $msg): void {
        if (! $this->is_debug()) return;
        error_log('[MADS_RV] ' . $msg);
        $this->debug_log[] = $msg;
    }

    public function render_debug_panel(): void {
        if (! $this->is_debug()) return;
        if (! is_user_logged_in()) return;
        ?>
        <div id="mads-rv-debug" style="position:fixed;bottom:0;left:0;right:0;background:#1d2327;color:#f0f0f1;font:12px/1.6 monospace;padding:12px 16px;z-index:999999;max-height:260px;overflow-y:auto;border-top:3px solid #d63638;">
            <strong style="color:#72aee6;">🔍 MADS RV Debug</strong>
            <span style="float:right;cursor:pointer;color:#f0f0f1;" onclick="this.parentNode.style.display='none'">✕ cerrar</span>
            <br>
            <?php foreach ($this->debug_log as $line) : ?>
                <div><?php echo esc_html($line); ?></div>
            <?php endforeach; ?>
            <?php if (empty($this->debug_log)) : ?>
                <div style="color:#f0b849;">⚠ Ningún hook de MADS_RV se ejecutó en esta página.</div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ── Helpers ─────────────────────────────────────────────────────────────
    private function get_allowed_roles(): array {
        $settings = get_option(self::OPTION_KEY, []);
        return isset($settings['allowed_roles']) ? (array) $settings['allowed_roles'] : [];
    }

    private function current_user_has_access(): bool {
        if (current_user_can('manage_options')) {
            $this->log('current_user_has_access → TRUE (administrador)');
            return true;
        }
        $user = wp_get_current_user();
        if (! $user || ! $user->ID) {
            $this->log('current_user_has_access → FALSE (no hay usuario)');
            return false;
        }
        $allowed = $this->get_allowed_roles();
        if (empty($allowed)) {
            $this->log('current_user_has_access → FALSE (no hay roles configurados)');
            return false;
        }
        $result = (bool) array_intersect((array) $user->roles, $allowed);
        $this->log(sprintf(
            'current_user_has_access: user=%d roles=[%s] allowed=[%s] → %s',
            $user->ID,
            implode(', ', (array) $user->roles),
            implode(', ', $allowed),
            $result ? 'TRUE' : 'FALSE'
        ));
        return $result;
    }

    private function is_product_query(\WP_Query $query): bool {
        $post_type = $query->get('post_type');
        if ($post_type === 'product'
            || (is_array($post_type) && in_array('product', $post_type, true))) {
            return true;
        }
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

    private function add_private_status(\WP_Query $query): void {
        $statuses = $query->get('post_status') ?: 'publish';
        if (is_string($statuses)) $statuses = [$statuses];
        if (! in_array('private', $statuses, true)) {
            $statuses[] = 'private';
            $query->set('post_status', $statuses);
            $this->log('add_private_status: post_status actualizado a [' . implode(', ', $statuses) . ']');
        }
    }

    // ── Hooks públicos ──────────────────────────────────────────────────────
    public function init(): void {
        add_filter('user_has_cap', [$this, 'grant_private_product_cap'], 10, 4);
        add_action('pre_get_posts', [$this, 'include_private_products_in_query'], 999);
        add_action('woocommerce_product_query', [$this, 'include_private_products_in_wc_query']);
        add_filter('posts_where', [$this, 'ensure_private_in_where'], PHP_INT_MAX, 2);
        add_filter('woocommerce_is_purchasable', [$this, 'allow_private_product_purchase'], 10, 2);

        if ($this->is_debug()) {
            add_action('wp_footer', [$this, 'render_debug_panel'], PHP_INT_MAX);
        }
    }

    public function grant_private_product_cap(array $allcaps, array $caps, array $args, \WP_User $user): array {
        if (! empty($allcaps['read_private_products'])) return $allcaps;
        $allowed = $this->get_allowed_roles();
        if (empty($allowed)) return $allcaps;
        if (! empty(array_intersect((array) $user->roles, $allowed))) {
            $allcaps['read_private_products'] = true;
            $this->log(sprintf('grant_private_product_cap: user=%d → read_private_products=true', $user->ID));
        }
        return $allcaps;
    }

    public function include_private_products_in_query(\WP_Query $query): void {
        if (is_admin()) return;
        if (! $this->current_user_has_access()) return;
        if (! $this->is_product_query($query)) {
            $this->log('include_private_products_in_query: NO es query de productos (post_type=' . print_r($query->get('post_type'), true) . ')');
            return;
        }
        $this->log('include_private_products_in_query: es query de productos');
        $this->add_private_status($query);
    }

    public function include_private_products_in_wc_query(\WP_Query $query): void {
        if (! $this->current_user_has_access()) return;
        $this->log('include_private_products_in_wc_query: woocommerce_product_query disparado');
        $this->add_private_status($query);
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

        $has_product_sql = strpos($where, $wpdb->posts . ".post_type = 'product'") !== false;

        $this->log(sprintf(
            'ensure_private_in_where: product_en_sql=%s',
            $has_product_sql ? 'SI' : 'NO'
        ));
        $this->log('WHERE: ' . substr($where, 0, 600));

        if (! $has_product_sql) return $where;

        // ── Fix 1: post_status ──────────────────────────────────────────────
        $already_private = strpos($where, "post_status = 'private'") !== false
                           || strpos($where, "post_status IN") !== false;
        if (! $already_private) {
            $where = str_replace(
                $wpdb->posts . ".post_status = 'publish'",
                $wpdb->posts . ".post_status IN ('publish','private')",
                $where
            );
            $this->log('Fix 1 aplicado: post_status');
        }

        // ── Fix 2: catalog visibility (exclude-from-catalog) ───────────────
        // WooCommerce excluye productos con el término exclude-from-catalog mediante:
        //   wp_posts.ID NOT IN (SELECT object_id FROM wp_term_relationships
        //                       WHERE term_taxonomy_id IN (N))
        // Cuando un producto es Private, WooCommerce puede haberle asignado ese
        // término. Permitimos el paso añadiendo OR post_status = 'private'.
        $vis_pattern = '/'
            . preg_quote($wpdb->posts, '/')
            . '\.ID\s+NOT IN\s*\(\s*SELECT\s+object_id\s+FROM\s+'
            . preg_quote($wpdb->term_relationships, '/')
            . '\s+WHERE\s+term_taxonomy_id\s+IN\s*\(\s*[\d,\s]+\s*\)\s*\)/i';

        if (preg_match($vis_pattern, $where)) {
            $where = preg_replace(
                $vis_pattern,
                "($0 OR {$wpdb->posts}.post_status = 'private')",
                $where
            );
            $this->log('Fix 2 aplicado: catalog visibility NOT IN');
        }

        // ── Fix 3: WPML ────────────────────────────────────────────────────
        // WPML hace INNER JOIN con wpml_translations. Si el producto privado
        // no tiene entrada en esa tabla, el JOIN lo excluye.
        // Añadimos OR post_status = 'private' a la primera condición de language_code.
        if (strpos($where, 'wpml_translations.language_code') !== false) {
            $where = preg_replace(
                '/wpml_translations\.language_code\s*=\s*\'([a-z_\-]+)\'/i',
                "(wpml_translations.language_code = '$1' OR {$wpdb->posts}.post_status = 'private')",
                $where,
                1
            );
            $this->log('Fix 3 aplicado: WPML language_code');
        }

        return $where;
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
