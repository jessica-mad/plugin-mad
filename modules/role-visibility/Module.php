<?php
if (! defined('ABSPATH')) exit;

return new class ($core ?? null) implements MAD_Suite_Module {

    private const OPTION_KEY     = 'madsuite_role_visibility_settings';
    private const NONCE_SETTINGS = 'mads_rv_save_settings';

    private array $debug_log        = [];
    private array $wc_product_queries = [];

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
        if (! $this->is_debug() || ! is_user_logged_in()) return;
        ?>
        <div id="mads-rv-debug" style="position:fixed;bottom:0;left:0;right:0;background:#1d2327;color:#f0f0f1;font:12px/1.8 monospace;padding:10px 16px;z-index:999999;max-height:200px;overflow-y:auto;border-top:3px solid #d63638;">
            <strong style="color:#72aee6;">MADS RV Debug</strong>
            <span style="float:right;cursor:pointer;" onclick="this.parentNode.style.display='none'">✕</span><br>
            <?php foreach ($this->debug_log as $line) : ?>
                <div><?php echo esc_html($line); ?></div>
            <?php endforeach; ?>
            <?php if (empty($this->debug_log)) : ?>
                <div style="color:#f0b849;">Ningún hook relevante ejecutado en esta página.</div>
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
        static $cache = null;
        if ($cache !== null) return $cache;

        if (current_user_can('manage_options')) {
            return $cache = true;
        }
        $user    = wp_get_current_user();
        $allowed = $this->get_allowed_roles();

        if (! $user || ! $user->ID || empty($allowed)) {
            return $cache = false;
        }

        $result = (bool) array_intersect((array) $user->roles, $allowed);

        $this->log(sprintf(
            'Usuario %d | roles: [%s] | permitidos: [%s] | acceso: %s',
            $user->ID,
            implode(', ', (array) $user->roles),
            implode(', ', $allowed),
            $result ? 'SÍ' : 'NO'
        ));

        return $cache = $result;
    }

    private function is_product_query(\WP_Query $query): bool {
        $post_type = $query->get('post_type');
        if ($post_type === 'product'
            || (is_array($post_type) && in_array('product', $post_type, true))) {
            return true;
        }
        // Taxonomy archive pages: post_type is not set in query vars, but the
        // taxonomy itself implies products (e.g. /etiqueta-producto/early-access/)
        if ($query->is_tax('product_tag') || $query->is_tax('product_cat')) {
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
        // Tracked by woocommerce_product_query hook (covers cases not matched above)
        return isset($this->wc_product_queries[spl_object_hash($query)]);
    }

    private function add_private_status(\WP_Query $query): void {
        $statuses = $query->get('post_status') ?: 'publish';
        if (is_string($statuses)) $statuses = [$statuses];
        if (! in_array('private', $statuses, true)) {
            $statuses[] = 'private';
            $query->set('post_status', $statuses);
        }
    }

    // ── Hooks públicos ──────────────────────────────────────────────────────
    public function init(): void {
        // Acceso directo por URL a productos privados
        add_filter('user_has_cap', [$this, 'grant_private_product_cap'], 10, 4);

        // Incluir 'private' en el post_status de queries de producto
        add_action('pre_get_posts', [$this, 'include_private_products_in_query'], 999);
        add_action('woocommerce_product_query', [$this, 'include_private_products_in_wc_query']);

        // Inyectar productos privados que WPML/WooCommerce hayan filtrado
        // suppress_filters=true en la sub-query bypasea WPML, catalog visibility, etc.
        add_filter('the_posts', [$this, 'inject_missing_private_products'], 10, 2);

        add_filter('woocommerce_product_is_visible', [$this, 'allow_private_product_visibility'], 10, 2);
        add_filter('woocommerce_is_purchasable',    [$this, 'allow_private_product_purchase'],    10, 2);

        if ($this->is_debug()) {
            add_action('wp_footer', [$this, 'render_debug_panel'], PHP_INT_MAX);
        }
    }

    public function grant_private_product_cap(array $allcaps, array $caps, array $args, \WP_User $user): array {
        if (! empty($allcaps['read_private_products'])) return $allcaps;
        $allowed = $this->get_allowed_roles();
        if (! empty($allowed) && ! empty(array_intersect((array) $user->roles, $allowed))) {
            $allcaps['read_private_products'] = true;
        }
        return $allcaps;
    }

    public function include_private_products_in_query(\WP_Query $query): void {
        if (is_admin()) return;
        if (! $this->current_user_has_access()) return;
        if (! $this->is_product_query($query)) return;
        $this->add_private_status($query);
        $this->log('pre_get_posts: post_status actualizado a [publish, private]');
    }

    public function include_private_products_in_wc_query(\WP_Query $query): void {
        if (! $this->current_user_has_access()) return;
        $this->add_private_status($query);
        $this->wc_product_queries[spl_object_hash($query)] = true;
        $this->log('woocommerce_product_query: post_status actualizado a [publish, private]');
    }

    /**
     * Inyecta en los resultados los productos privados que WPML o WooCommerce
     * hayan eliminado mediante JOINs o tax_queries.
     *
     * get_posts con suppress_filters=true bypasea:
     *   - Los filtros posts_join / posts_where de WPML (filtro de idioma)
     *   - Los pre_get_posts de WooCommerce (visibilidad de catálogo)
     *   - El propio filtro the_posts (sin riesgo de recursión)
     */
    public function inject_missing_private_products(array $posts, \WP_Query $query): array {
        if (is_admin()) return $posts;
        if (! $this->current_user_has_access()) return $posts;
        if (! $this->is_product_query($query)) return $posts;

        $all_private = get_posts([
            'post_type'        => 'product',
            'post_status'      => 'private',
            'posts_per_page'   => -1,
            'no_found_rows'    => true,
            'suppress_filters' => true,   // bypasea WPML, WooCommerce y the_posts
        ]);

        if (empty($all_private)) {
            $this->log('inject: no existen productos privados en la BD');
            return $posts;
        }

        $existing_ids = array_map('intval', wp_list_pluck($posts, 'ID'));
        $injected     = [];

        foreach ($all_private as $private_post) {
            if (! in_array((int) $private_post->ID, $existing_ids, true)) {
                $posts[]    = $private_post;
                $injected[] = $private_post->ID;
            }
        }

        if (! empty($injected)) {
            $this->log('inject: inyectados IDs [' . implode(', ', $injected) . ']');
        } else {
            $this->log('inject: todos los privados ya estaban en el resultado');
        }

        return $posts;
    }

    public function allow_private_product_visibility(bool $visible, int $product_id): bool {
        if ($visible) return $visible;
        $product = wc_get_product($product_id);
        if (! $product || $product->get_status() !== 'private') return $visible;
        return $this->current_user_has_access();
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
