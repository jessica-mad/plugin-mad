<?php
if ( ! defined('ABSPATH') ) exit;

return new class(MAD_Suite_Core::instance()) implements MAD_Suite_Module {

    private $core;
    private $option_key;
    private $logger;
    private $table;

    public function __construct($core){
        $this->core       = $core;
        $this->option_key = MAD_Suite_Core::option_key( $this->slug() );
        global $wpdb;
        $this->table = $wpdb->prefix . 'mad_ads_clicks';
    }

    public function slug()       { return 'ga4-server'; }
    public function title()      { return __('Conversiones de Ads (Google + Meta)','mad-suite'); }
    public function menu_label() { return __('Ads Conversiones','mad-suite'); }
    public function menu_slug()  { return 'mad-'.$this->slug(); }

    /* =========================================================
     * HOOKS
     * ======================================================= */
    public function init(){
        $this->logger = wc_get_logger();
        $this->maybe_create_table();

        add_action('wp',                                    [$this, 'capture_click_visit']);
        add_action('woocommerce_checkout_update_order_meta',[$this, 'save_tracking_to_order']);
        add_action('woocommerce_order_status_changed',      [$this, 'maybe_send_purchase_events'], 10, 4);
    }

    /* =========================================================
     * BASE DE DATOS
     * ======================================================= */
    private function maybe_create_table(){
        if (get_transient('mad_ads_clicks_table_ok')) return;

        global $wpdb;
        if ($wpdb->get_var("SHOW TABLES LIKE '{$this->table}'") === $this->table) {
            set_transient('mad_ads_clicks_table_ok', 1, DAY_IN_SECONDS);
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$this->table} (
            id           bigint(20)    NOT NULL AUTO_INCREMENT,
            platform     varchar(20)   NOT NULL,
            click_id     varchar(255)  NOT NULL,
            browser_id   varchar(100)  DEFAULT '',
            utm_campaign varchar(255)  DEFAULT '',
            utm_source   varchar(100)  DEFAULT '',
            utm_medium   varchar(100)  DEFAULT '',
            landing_url  varchar(500)  DEFAULT '',
            captured_at  datetime      NOT NULL,
            order_id     bigint(20)    DEFAULT NULL,
            order_total  decimal(10,2) DEFAULT NULL,
            currency     varchar(10)   DEFAULT '',
            converted_at datetime      DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY   platform_click (platform(10), click_id(191)),
            KEY          platform (platform),
            KEY          order_id (order_id)
        ) $charset;");

        set_transient('mad_ads_clicks_table_ok', 1, DAY_IN_SECONDS);
    }

    /* =========================================================
     * CAPTURA DE CLICS EN LANDING
     * ======================================================= */
    public function capture_click_visit(){
        // Google Ads: gclid en URL (landing) o en cookie _gcl_aw (páginas siguientes)
        $gclid = $this->extract_gclid_from_request();
        if ($gclid) {
            $this->save_click_to_db('google', $gclid, $this->get_ga_client_id(), 'google', 'cpc');
        }

        // Meta Ads: fbclid en URL (landing) o extraído de cookie _fbc
        $fbclid = $this->extract_fbclid_from_request();
        if ($fbclid) {
            $this->save_click_to_db('meta', $fbclid, $this->get_fbp(), 'facebook', 'cpc');
        }
    }

    private function save_click_to_db($platform, $click_id, $browser_id, $default_source, $default_medium){
        global $wpdb;

        // Cada clic de anuncio genera un ID único — no duplicar
        if ($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE platform = %s AND click_id = %s",
            $platform, $click_id
        ))) return;

        $wpdb->insert($this->table, [
            'platform'     => $platform,
            'click_id'     => $click_id,
            'browser_id'   => $browser_id ?: '',
            'utm_campaign' => isset($_GET['utm_campaign']) ? sanitize_text_field($_GET['utm_campaign']) : '',
            'utm_source'   => isset($_GET['utm_source'])   ? sanitize_text_field($_GET['utm_source'])   : $default_source,
            'utm_medium'   => isset($_GET['utm_medium'])   ? sanitize_text_field($_GET['utm_medium'])   : $default_medium,
            'landing_url'  => $this->get_current_url(),
            'captured_at'  => current_time('mysql'),
        ], ['%s','%s','%s','%s','%s','%s','%s','%s']);
    }

    /* =========================================================
     * GUARDAR TRACKING EN EL PEDIDO (solo cookies — gclid/fbclid
     * no están en la URL en la página de checkout)
     * ======================================================= */
    public function save_tracking_to_order($order_id){
        $order = wc_get_order($order_id);
        if (!$order) return;

        // Google
        $gclid        = $this->extract_gclid_from_cookie();
        $ga_client_id = $this->get_ga_client_id();
        if ($gclid)        $order->update_meta_data('_gclid',        $gclid);
        if ($ga_client_id) $order->update_meta_data('_ga_client_id', $ga_client_id);

        // Meta
        $fbc = $this->get_fbc();
        $fbp = $this->get_fbp();
        if ($fbc) $order->update_meta_data('_fbc', $fbc);
        if ($fbp) $order->update_meta_data('_fbp', $fbp);

        $order->save();

        // Vincular registros en DB con este pedido
        if ($gclid) $this->link_order_to_click($order, 'google', $gclid, $ga_client_id);

        $fbclid = $this->extract_fbclid_from_cookie(); // extraído de _fbc
        if ($fbclid) $this->link_order_to_click($order, 'meta', $fbclid, $fbp);
    }

    private function link_order_to_click(WC_Order $order, $platform, $click_id, $browser_id){
        global $wpdb;
        $wpdb->update(
            $this->table,
            [
                'order_id'     => $order->get_id(),
                'order_total'  => (float) $order->get_total(),
                'currency'     => $order->get_currency(),
                'converted_at' => current_time('mysql'),
                'browser_id'   => $browser_id ?: '',
            ],
            ['platform' => $platform, 'click_id' => $click_id],
            ['%d','%f','%s','%s','%s'],
            ['%s','%s']
        );
    }

    /* =========================================================
     * ENVÍO DE EVENTOS AL CAMBIAR ESTADO DEL PEDIDO
     * ======================================================= */
    public function maybe_send_purchase_events($order_id, $from_status, $to_status, $order){
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
            if (!$order) return;
        }

        $settings = $this->get_settings();
        $to_short = (strpos($to_status,'wc-') === 0) ? substr($to_status, 3) : $to_status;

        // — Google GA4 —
        if (!empty($settings['google_enabled'])) {
            $targets = array_map('strval', $settings['google_statuses']);
            if (in_array($to_short, $targets, true) && !$order->get_meta('_ga4_purchase_sent')) {
                $mid = trim($settings['measurement_id']);
                $sec = trim($settings['api_secret']);
                if ($mid && $sec) {
                    $this->send_ga4_purchase($order, $mid, $sec, $settings);
                    $order->update_meta_data('_ga4_purchase_sent', 1);
                    $order->save();
                } else {
                    $this->debug_log('error', 'Google: falta measurement_id o api_secret.');
                }
            }
        }

        // — Meta CAPI —
        if (!empty($settings['meta_enabled'])) {
            $targets = array_map('strval', $settings['meta_statuses']);
            if (in_array($to_short, $targets, true) && !$order->get_meta('_meta_capi_purchase_sent')) {
                $pixel = trim($settings['pixel_id']);
                $token = trim($settings['access_token']);
                if ($pixel && $token) {
                    $this->send_meta_purchase($order, $pixel, $token, $settings);
                    $order->update_meta_data('_meta_capi_purchase_sent', 1);
                    $order->save();
                } else {
                    $this->debug_log('error', 'Meta: falta pixel_id o access_token.');
                }
            }
        }
    }

    /* =========================================================
     * GOOGLE GA4 — MEASUREMENT PROTOCOL
     * ======================================================= */
    private function send_ga4_purchase(WC_Order $order, $measurement_id, $api_secret, array $settings){
        $items = [];
        foreach ($order->get_items() as $item_id => $item){
            if (!$item instanceof WC_Order_Item_Product) continue;
            $product = $item->get_product();
            $price   = $item->get_total() / max(1, $item->get_quantity());
            $items[] = [
                'item_id'      => $product ? (string) $product->get_id() : (string) $item_id,
                'item_name'    => $item->get_name(),
                'quantity'     => (int) $item->get_quantity(),
                'price'        => (float) wc_format_decimal($price, 2),
                'item_brand'   => $product ? (string) $product->get_attribute('brand') : '',
                'item_variant' => $product && $product->is_type('variation') ? $product->get_sku() : '',
            ];
        }

        // Usar el client_id real del navegador; UUID como fallback
        $client_id   = $order->get_meta('_ga_client_id') ?: wp_generate_uuid4();
        $user_id     = $order->get_user_id() ? (string) $order->get_user_id() : null;
        $gclid       = $order->get_meta('_gclid');
        $order_total = $this->apply_test_coupon($order, (float) $order->get_total(), $settings);

        $params = [
            'transaction_id' => (string) $order->get_id(),
            'value'          => $order_total,
            'currency'       => $order->get_currency(),
            'tax'            => (float) $order->get_total_tax(),
            'shipping'       => (float) $order->get_shipping_total(),
            'coupon'         => implode(',', $order->get_coupon_codes()),
            'items'          => $items,
        ];
        if ($gclid) $params['gclid'] = $gclid;

        $payload = [
            'client_id'            => $client_id,
            'non_personalized_ads' => false,
            'events'               => [['name' => 'purchase', 'params' => $params]],
        ];
        if ($user_id) $payload['user_id'] = $user_id;

        $this->debug_log('info', sprintf('Google GA4: enviando purchase pedido #%d | client_id: %s | gclid: %s',
            $order->get_id(), $client_id, $gclid ?: 'sin gclid'));

        $response = wp_remote_post(
            add_query_arg(['measurement_id' => $measurement_id, 'api_secret' => $api_secret],
                'https://www.google-analytics.com/mp/collect'),
            ['method' => 'POST', 'headers' => ['Content-Type' => 'application/json'],
             'body' => wp_json_encode($payload), 'timeout' => 20]
        );

        $this->log_api_response('Google GA4', $response);
    }

    /* =========================================================
     * META — CONVERSIONS API
     * ======================================================= */
    private function send_meta_purchase(WC_Order $order, $pixel_id, $access_token, array $settings){
        $fbc = $order->get_meta('_fbc') ?: '';
        $fbp = $order->get_meta('_fbp') ?: '';

        // Datos del usuario (siempre enviamos fbp/fbc; PII solo si está activado)
        $user_data = [];
        if ($fbp) $user_data['fbp'] = $fbp;
        if ($fbc) $user_data['fbc'] = $fbc;

        if (!empty($settings['send_customer_data'])) {
            $email = $order->get_billing_email();
            $phone = $order->get_billing_phone();
            $fname = strtolower(trim($order->get_billing_first_name()));
            $lname = strtolower(trim($order->get_billing_last_name()));
            $city  = strtolower(trim($order->get_billing_city()));
            $cntry = strtolower(trim($order->get_billing_country()));
            $zip   = preg_replace('/[^0-9a-z]/', '', strtolower(trim($order->get_billing_postcode())));

            if ($email)  $user_data['em']      = [hash('sha256', strtolower(trim($email)))];
            if ($phone){
                $clean = preg_replace('/[^0-9]/', '', $phone);
                if ($clean) $user_data['ph']   = [hash('sha256', $clean)];
            }
            if ($fname)  $user_data['fn']      = [hash('sha256', $fname)];
            if ($lname)  $user_data['ln']      = [hash('sha256', $lname)];
            if ($city)   $user_data['ct']      = [hash('sha256', $city)];
            if ($cntry)  $user_data['country'] = [hash('sha256', $cntry)];
            if ($zip)    $user_data['zp']      = [hash('sha256', $zip)];
        }

        $contents = [];
        foreach ($order->get_items() as $item_id => $item){
            if (!$item instanceof WC_Order_Item_Product) continue;
            $product = $item->get_product();
            $contents[] = [
                'id'         => $product ? (string) $product->get_id() : (string) $item_id,
                'quantity'   => (int) $item->get_quantity(),
                'item_price' => (float) wc_format_decimal($item->get_total() / max(1, $item->get_quantity()), 2),
            ];
        }

        $order_total = $this->apply_test_coupon($order, (float) $order->get_total(), $settings);

        $event = [
            'event_name'       => 'Purchase',
            'event_time'       => time(),
            'event_source_url' => home_url('/'),
            'action_source'    => 'website',
            'event_id'         => 'wc_order_' . $order->get_id(), // clave de deduplicación
            'user_data'        => $user_data,
            'custom_data'      => [
                'value'        => $order_total,
                'currency'     => $order->get_currency(),
                'contents'     => $contents,
                'content_type' => 'product',
                'order_id'     => (string) $order->get_id(),
                'num_items'    => count($contents),
            ],
        ];

        $payload = ['data' => [$event]];
        if (!empty($settings['meta_test_code'])) {
            $payload['test_event_code'] = sanitize_text_field($settings['meta_test_code']);
        }

        $this->debug_log('info', sprintf('Meta CAPI: enviando Purchase pedido #%d | fbp: %s | fbc: %s',
            $order->get_id(),
            $fbp ? substr($fbp, 0, 20) . '…' : 'sin fbp',
            $fbc ? substr($fbc, 0, 20) . '…' : 'sin fbc'
        ));

        $response = wp_remote_post(
            add_query_arg(['access_token' => $access_token],
                sprintf('https://graph.facebook.com/v21.0/%s/events', rawurlencode($pixel_id))),
            ['method' => 'POST', 'headers' => ['Content-Type' => 'application/json'],
             'body' => wp_json_encode($payload), 'timeout' => 20]
        );

        $this->log_api_response('Meta CAPI', $response);
    }

    /* =========================================================
     * HELPERS — EXTRACCIÓN DE IDs Y COOKIES
     * ======================================================= */

    // — Google —
    private function extract_gclid_from_request(){
        if (!empty($_GET['gclid'])) return sanitize_text_field($_GET['gclid']);
        return $this->extract_gclid_from_cookie();
    }
    private function extract_gclid_from_cookie(){
        if (!isset($_COOKIE['_gcl_aw'])) return null;
        $c = sanitize_text_field($_COOKIE['_gcl_aw']);
        return preg_match('/GCL\.\d+\.(.+)/', $c, $m) ? $m[1] : null;
    }
    private function get_ga_client_id(){
        if (!isset($_COOKIE['_ga'])) return null;
        $parts = explode('.', sanitize_text_field($_COOKIE['_ga']));
        return count($parts) >= 4 ? $parts[2] . '.' . $parts[3] : null;
    }

    // — Meta —
    private function extract_fbclid_from_request(){
        if (!empty($_GET['fbclid'])) return sanitize_text_field($_GET['fbclid']);
        return $this->extract_fbclid_from_cookie();
    }
    private function extract_fbclid_from_cookie(){
        $fbc = $this->get_fbc();
        if (!$fbc) return null;
        // _fbc formato: fb.1.{timestamp}.{fbclid}
        $parts = explode('.', $fbc, 4);
        return isset($parts[3]) && $parts[3] !== '' ? $parts[3] : null;
    }
    private function get_fbc(){
        if (!isset($_COOKIE['_fbc'])) return null;
        return sanitize_text_field($_COOKIE['_fbc']);
    }
    private function get_fbp(){
        if (!isset($_COOKIE['_fbp'])) return null;
        return sanitize_text_field($_COOKIE['_fbp']);
    }

    // — Compartidos —
    private function apply_test_coupon(WC_Order $order, float $total, array $settings): float {
        $test_coupon = trim($settings['test_coupon']);
        if ($test_coupon === '' || !$order->get_coupon_codes()) return $total;
        if (in_array(strtolower($test_coupon), array_map('strtolower', $order->get_coupon_codes()), true)) {
            return max(0.01, $total);
        }
        return $total;
    }

    private function log_api_response($label, $response){
        if (is_wp_error($response)) {
            $this->debug_log('error', $label . ' error: ' . implode(', ', $response->get_error_messages()));
            return;
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            $this->debug_log('info', sprintf('%s respondió correctamente (HTTP %d)', $label, $code));
        } else {
            $this->debug_log('error', sprintf('%s HTTP %d: %s', $label, $code, wp_remote_retrieve_body($response)));
        }
    }

    private function get_current_url(){
        return substr(home_url(add_query_arg([])), 0, 500);
    }

    private function get_order_edit_url($order_id){
        if (
            class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') &&
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
        ) {
            return admin_url('admin.php?page=wc-orders&action=edit&id=' . intval($order_id));
        }
        return get_edit_post_link($order_id);
    }

    private function debug_log($level, $message){
        if (empty($this->get_settings()['debug'])) return;
        if (!$this->logger) $this->logger = wc_get_logger();
        $this->logger->log($level, $message, ['source' => 'mad-ads-conversions']);
    }

    /* =========================================================
     * SETTINGS API
     * ======================================================= */
    public function admin_init(){
        register_setting($this->option_group(), $this->option_key, [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default'           => $this->defaults(),
        ]);

        // — Sección Google —
        add_settings_section('google_section',
            __('Google Ads (GA4 Measurement Protocol)','mad-suite'),
            fn() => print('<p>' . esc_html__('Envía eventos de compra a GA4 desde el servidor, sin depender de la página de gracias.','mad-suite') . '</p>'),
            $this->menu_slug()
        );
        foreach ([
            ['google_enabled', __('Activar','mad-suite'),                           'field_google_enabled'],
            ['measurement_id', __('ID de medición (G-XXXXXXX)','mad-suite'),        'field_measurement_id'],
            ['api_secret',     __('Secreto de API','mad-suite'),                    'field_api_secret'],
            ['google_statuses',__('Estados que disparan purchase','mad-suite'),     'field_google_statuses'],
        ] as [$id, $label, $cb]){
            add_settings_field($id, $label, [$this, $cb], $this->menu_slug(), 'google_section');
        }

        // — Sección Meta —
        add_settings_section('meta_section',
            __('Meta Ads (Conversions API)','mad-suite'),
            fn() => print('<p>' . esc_html__('Envía eventos de compra a Meta desde el servidor con datos de cliente hasheados para mejorar el match rate.','mad-suite') . '</p>'),
            $this->menu_slug()
        );
        foreach ([
            ['meta_enabled',       __('Activar','mad-suite'),                           'field_meta_enabled'],
            ['pixel_id',           __('Pixel ID','mad-suite'),                          'field_pixel_id'],
            ['access_token',       __('Access Token','mad-suite'),                      'field_access_token'],
            ['meta_statuses',      __('Estados que disparan Purchase','mad-suite'),     'field_meta_statuses'],
            ['meta_test_code',     __('Código de prueba (opcional)','mad-suite'),       'field_meta_test_code'],
            ['send_customer_data', __('Datos del cliente hasheados','mad-suite'),       'field_send_customer_data'],
        ] as [$id, $label, $cb]){
            add_settings_field($id, $label, [$this, $cb], $this->menu_slug(), 'meta_section');
        }

        // — Sección General —
        add_settings_section('general_section', __('General','mad-suite'), '__return_false', $this->menu_slug());
        foreach ([
            ['test_coupon', __('Cupón de prueba','mad-suite'), 'field_test_coupon'],
            ['debug',       __('Modo depuración','mad-suite'), 'field_debug'],
        ] as [$id, $label, $cb]){
            add_settings_field($id, $label, [$this, $cb], $this->menu_slug(), 'general_section');
        }
    }

    /* =========================================================
     * PÁGINA PRINCIPAL CON PESTAÑAS
     * ======================================================= */
    public function render_settings_page(){
        if (!current_user_can(MAD_Suite_Core::CAPABILITY)) return;
        $tab      = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        $base_url = admin_url('admin.php?page=' . $this->menu_slug());
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($this->title()); ?></h1>

            <nav class="nav-tab-wrapper" style="margin-bottom:20px;">
                <a href="<?php echo esc_url($base_url . '&tab=dashboard'); ?>"
                   class="nav-tab <?php echo $tab === 'dashboard' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Conversiones','mad-suite'); ?>
                </a>
                <a href="<?php echo esc_url($base_url . '&tab=settings'); ?>"
                   class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Ajustes','mad-suite'); ?>
                </a>
            </nav>

            <?php if ($tab === 'settings'): ?>
                <form method="post" action="options.php">
                    <?php
                    settings_fields($this->option_group());
                    do_settings_sections($this->menu_slug());
                    submit_button(__('Guardar cambios','mad-suite'));
                    ?>
                </form>
                <hr />
                <p><a href="<?php echo esc_url(admin_url('admin.php?page=wc-status&tab=logs')); ?>" class="button">
                    <?php esc_html_e('Ver logs de WooCommerce','mad-suite'); ?>
                </a></p>
            <?php else: ?>
                <?php $this->render_dashboard(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /* =========================================================
     * DASHBOARD DE CONVERSIONES
     * ======================================================= */
    private function render_dashboard(){
        global $wpdb;

        $page     = max(1, intval($_GET['paged'] ?? 1));
        $per_page = 30;
        $offset   = ($page - 1) * $per_page;

        $pf_filter   = isset($_GET['platform']) ? sanitize_key($_GET['platform']) : 'all';
        $conv_filter = isset($_GET['filter'])   ? sanitize_key($_GET['filter'])   : 'all';

        $wheres = [];
        if (in_array($pf_filter, ['google','meta'], true)) {
            $wheres[] = $wpdb->prepare('platform = %s', $pf_filter);
        }
        if ($conv_filter === 'converted') $wheres[] = 'order_id IS NOT NULL';
        if ($conv_filter === 'pending')   $wheres[] = 'order_id IS NULL';
        $where = $wheres ? 'WHERE ' . implode(' AND ', $wheres) : '';

        // Stats globales por plataforma
        $stats_rows = $wpdb->get_results(
            "SELECT platform,
                    COUNT(*) as total,
                    SUM(CASE WHEN order_id IS NOT NULL THEN 1 ELSE 0 END) as converted,
                    SUM(CASE WHEN order_id IS NOT NULL THEN order_total ELSE 0 END) as revenue
             FROM {$this->table}
             GROUP BY platform"
        );
        $by_pf = [];
        $combined = ['total' => 0, 'converted' => 0, 'revenue' => 0.0];
        foreach ($stats_rows as $s) {
            $by_pf[$s->platform] = $s;
            $combined['total']     += $s->total;
            $combined['converted'] += $s->converted;
            $combined['revenue']   += $s->revenue;
        }
        $combined['rate'] = $combined['total'] > 0
            ? round(($combined['converted'] / $combined['total']) * 100, 1) : 0;

        $total_filtered = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} $where");
        $rows           = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} $where ORDER BY captured_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ));
        $total_pages = (int) ceil($total_filtered / $per_page);

        $settings  = $this->get_settings();
        $base_url  = admin_url('admin.php?page=' . $this->menu_slug() . '&tab=dashboard');
        $google_ok = !empty($settings['google_enabled']) && !empty($settings['measurement_id']) && !empty($settings['api_secret']);
        $meta_ok   = !empty($settings['meta_enabled'])   && !empty($settings['pixel_id'])       && !empty($settings['access_token']);
        ?>
        <style>
        .ads-cards{display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap}
        .ads-card{background:#fff;border:1px solid #c3c4c7;border-radius:8px;padding:16px 20px;flex:1;min-width:210px}
        .ads-card h3{margin:0 0 12px;font-size:.93em;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
        .ads-card .stats{display:flex;gap:16px;flex-wrap:wrap}
        .ads-card.combined{background:#f6f7f7}
        .mini .val{font-size:1.55em;font-weight:700;display:block;line-height:1.1}
        .mini .lbl{font-size:.78em;color:#646970}
        .mini.green .val{color:#00a32a}
        .mini.blue  .val{color:#2271b1}
        .bg-google{background:#e8f0fe;color:#1a73e8;padding:2px 8px;border-radius:10px;font-size:.78em;font-weight:600}
        .bg-meta  {background:#e7f3ff;color:#0866ff;padding:2px 8px;border-radius:10px;font-size:.78em;font-weight:600}
        .ads-warn{display:inline-flex;align-items:center;gap:4px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:3px 8px;font-size:.76em}
        .ads-tbl{width:100%;border-collapse:collapse;background:#fff;border:1px solid #c3c4c7;border-radius:4px}
        .ads-tbl th{background:#f6f7f7;padding:9px 12px;text-align:left;font-size:.82em;border-bottom:1px solid #c3c4c7;white-space:nowrap}
        .ads-tbl td{padding:9px 12px;border-bottom:1px solid #f0f0f1;font-size:.82em;vertical-align:middle}
        .ads-tbl tr:last-child td{border-bottom:none}
        .ads-tbl tbody tr:hover td{background:#f9f9f9}
        .badge-ok{display:inline-block;padding:2px 9px;border-radius:10px;font-weight:600;font-size:.78em;background:#edfaef;color:#00a32a}
        .badge-no{display:inline-block;padding:2px 9px;border-radius:10px;font-size:.78em;background:#f0f0f1;color:#646970}
        .ads-mono{font-family:monospace;font-size:.78em;color:#8c8f94}
        .ads-filters{display:flex;gap:8px;align-items:center;margin-bottom:16px;flex-wrap:wrap}
        .ads-filters a{text-decoration:none;padding:5px 12px;border-radius:3px;border:1px solid #c3c4c7;font-size:.82em;background:#fff;color:#1d2327}
        .ads-filters a.active{background:#2271b1;color:#fff;border-color:#2271b1}
        .ads-sep{color:#c3c4c7;margin:0 4px}
        </style>

        <?php if (!$google_ok && !$meta_ok): ?>
        <div class="notice notice-warning inline" style="margin-bottom:20px">
            <p><?php printf(
                esc_html__('Configura al menos una plataforma en %s para empezar a registrar conversiones.','mad-suite'),
                '<a href="' . esc_url(admin_url('admin.php?page='.$this->menu_slug().'&tab=settings')) . '">' . esc_html__('Ajustes','mad-suite') . '</a>'
            ); ?></p>
        </div>
        <?php endif; ?>

        <!-- Tarjetas de stats por plataforma -->
        <div class="ads-cards">

            <div class="ads-card combined">
                <h3><?php esc_html_e('Total combinado','mad-suite'); ?></h3>
                <div class="stats">
                    <div class="mini"><span class="val"><?php echo esc_html(number_format($combined['total'])); ?></span><span class="lbl"><?php esc_html_e('Clics','mad-suite'); ?></span></div>
                    <div class="mini green"><span class="val"><?php echo esc_html(number_format($combined['converted'])); ?></span><span class="lbl"><?php esc_html_e('Conversiones','mad-suite'); ?></span></div>
                    <div class="mini blue"><span class="val"><?php echo esc_html($combined['rate']); ?>%</span><span class="lbl"><?php esc_html_e('Tasa','mad-suite'); ?></span></div>
                    <div class="mini"><span class="val"><?php echo esc_html(number_format($combined['revenue'], 2)); ?></span><span class="lbl"><?php esc_html_e('Ingresos','mad-suite'); ?></span></div>
                </div>
            </div>

            <div class="ads-card">
                <h3>
                    <span class="bg-google">Google Ads</span>
                    <?php if (!$google_ok): ?><span class="ads-warn">⚠ <?php esc_html_e('Sin configurar','mad-suite'); ?></span><?php endif; ?>
                </h3>
                <?php $g = $by_pf['google'] ?? null; ?>
                <div class="stats">
                    <div class="mini"><span class="val"><?php echo esc_html(number_format((int)($g->total ?? 0))); ?></span><span class="lbl"><?php esc_html_e('Clics','mad-suite'); ?></span></div>
                    <div class="mini green"><span class="val"><?php echo esc_html(number_format((int)($g->converted ?? 0))); ?></span><span class="lbl"><?php esc_html_e('Conversiones','mad-suite'); ?></span></div>
                    <div class="mini"><span class="val"><?php echo esc_html(number_format((float)($g->revenue ?? 0), 2)); ?></span><span class="lbl"><?php esc_html_e('Ingresos','mad-suite'); ?></span></div>
                </div>
            </div>

            <div class="ads-card">
                <h3>
                    <span class="bg-meta">Meta Ads</span>
                    <?php if (!$meta_ok): ?><span class="ads-warn">⚠ <?php esc_html_e('Sin configurar','mad-suite'); ?></span><?php endif; ?>
                </h3>
                <?php $m = $by_pf['meta'] ?? null; ?>
                <div class="stats">
                    <div class="mini"><span class="val"><?php echo esc_html(number_format((int)($m->total ?? 0))); ?></span><span class="lbl"><?php esc_html_e('Clics','mad-suite'); ?></span></div>
                    <div class="mini green"><span class="val"><?php echo esc_html(number_format((int)($m->converted ?? 0))); ?></span><span class="lbl"><?php esc_html_e('Conversiones','mad-suite'); ?></span></div>
                    <div class="mini"><span class="val"><?php echo esc_html(number_format((float)($m->revenue ?? 0), 2)); ?></span><span class="lbl"><?php esc_html_e('Ingresos','mad-suite'); ?></span></div>
                </div>
            </div>

        </div>

        <!-- Filtros -->
        <div class="ads-filters">
            <strong><?php esc_html_e('Plataforma:','mad-suite'); ?></strong>
            <?php foreach (['all' => __('Todas','mad-suite'), 'google' => 'Google Ads', 'meta' => 'Meta Ads'] as $val => $label): ?>
            <a href="<?php echo esc_url(add_query_arg(['platform' => $val, 'filter' => $conv_filter, 'paged' => 1], $base_url)); ?>"
               class="<?php echo $pf_filter === $val ? 'active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
            <?php endforeach; ?>

            <span class="ads-sep">|</span>
            <strong><?php esc_html_e('Estado:','mad-suite'); ?></strong>
            <?php foreach ([
                'all'       => __('Todos','mad-suite'),
                'converted' => __('Convertidos','mad-suite'),
                'pending'   => __('Sin compra','mad-suite'),
            ] as $val => $label): ?>
            <a href="<?php echo esc_url(add_query_arg(['platform' => $pf_filter, 'filter' => $val, 'paged' => 1], $base_url)); ?>"
               class="<?php echo $conv_filter === $val ? 'active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if (empty($rows)): ?>
            <p style="color:#646970"><?php esc_html_e('No hay datos todavía. Los clics desde anuncios de Google o Meta aparecerán aquí automáticamente.','mad-suite'); ?></p>
        <?php else: ?>

        <table class="ads-tbl">
            <thead>
                <tr>
                    <th><?php esc_html_e('Plataforma','mad-suite'); ?></th>
                    <th><?php esc_html_e('Fecha clic','mad-suite'); ?></th>
                    <th><?php esc_html_e('Click ID','mad-suite'); ?></th>
                    <th><?php esc_html_e('Campaña / UTM','mad-suite'); ?></th>
                    <th><?php esc_html_e('Landing','mad-suite'); ?></th>
                    <th><?php esc_html_e('Pedido','mad-suite'); ?></th>
                    <th><?php esc_html_e('Importe','mad-suite'); ?></th>
                    <th><?php esc_html_e('Estado','mad-suite'); ?></th>
                    <th><?php esc_html_e('Fecha compra','mad-suite'); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <?php if ($row->platform === 'google'): ?>
                            <span class="bg-google">Google</span>
                        <?php else: ?>
                            <span class="bg-meta">Meta</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html(wp_date('d/m/Y H:i', strtotime($row->captured_at))); ?></td>
                    <td><span class="ads-mono" title="<?php echo esc_attr($row->click_id); ?>"><?php echo esc_html(substr($row->click_id, 0, 18)); ?>…</span></td>
                    <td>
                        <?php if ($row->utm_campaign): ?>
                            <strong><?php echo esc_html($row->utm_campaign); ?></strong><br>
                            <small style="color:#8c8f94"><?php echo esc_html($row->utm_source . ' / ' . $row->utm_medium); ?></small>
                        <?php else: ?>
                            <span style="color:#c3c4c7">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row->landing_url):
                            $path = wp_parse_url($row->landing_url, PHP_URL_PATH) ?: '/'; ?>
                            <a href="<?php echo esc_url($row->landing_url); ?>" target="_blank"
                               title="<?php echo esc_attr($row->landing_url); ?>" style="font-size:.78em">
                                <?php echo esc_html(strlen($path) > 28 ? substr($path, 0, 28) . '…' : $path); ?>
                            </a>
                        <?php else: ?><span style="color:#c3c4c7">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row->order_id): ?>
                            <a href="<?php echo esc_url($this->get_order_edit_url($row->order_id)); ?>">#<?php echo esc_html($row->order_id); ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <?php echo $row->order_total !== null
                            ? esc_html(number_format((float)$row->order_total, 2) . ' ' . $row->currency)
                            : '—'; ?>
                    </td>
                    <td>
                        <?php echo $row->order_id
                            ? '<span class="badge-ok">' . esc_html__('Convertido','mad-suite') . '</span>'
                            : '<span class="badge-no">' . esc_html__('Sin compra','mad-suite') . '</span>'; ?>
                    </td>
                    <td>
                        <?php echo $row->converted_at
                            ? esc_html(wp_date('d/m/Y H:i', strtotime($row->converted_at)))
                            : '—'; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
        <div style="margin-top:16px;">
            <?php echo paginate_links([
                'base'    => add_query_arg(['platform' => $pf_filter, 'filter' => $conv_filter, 'paged' => '%#%'], $base_url),
                'format'  => '',
                'current' => $page,
                'total'   => $total_pages,
            ]); ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>
        <?php
    }

    /* =========================================================
     * SETTINGS HELPERS
     * ======================================================= */
    private function defaults(){
        return [
            'google_enabled'     => 1,
            'measurement_id'     => '',
            'api_secret'         => '',
            'google_statuses'    => ['processing'],
            'meta_enabled'       => 0,
            'pixel_id'           => '',
            'access_token'       => '',
            'meta_statuses'      => ['processing'],
            'meta_test_code'     => '',
            'send_customer_data' => 1,
            'test_coupon'        => '',
            'debug'              => 0,
        ];
    }

    private function get_settings(){
        $opts = get_option($this->option_key, []);
        return wp_parse_args(is_array($opts) ? $opts : [], $this->defaults());
    }

    private function option_group(){ return 'group_'.$this->slug(); }

    public function sanitize_settings($input){
        $out = [];
        $out['google_enabled']     = !empty($input['google_enabled'])     ? 1 : 0;
        $out['measurement_id']     = sanitize_text_field($input['measurement_id']     ?? '');
        $out['api_secret']         = sanitize_text_field($input['api_secret']         ?? '');
        $out['google_statuses']    = $this->sanitize_statuses($input['google_statuses']    ?? []);
        $out['meta_enabled']       = !empty($input['meta_enabled'])       ? 1 : 0;
        $out['pixel_id']           = sanitize_text_field($input['pixel_id']           ?? '');
        $out['access_token']       = sanitize_text_field($input['access_token']       ?? '');
        $out['meta_statuses']      = $this->sanitize_statuses($input['meta_statuses']      ?? []);
        $out['meta_test_code']     = sanitize_text_field($input['meta_test_code']     ?? '');
        $out['send_customer_data'] = !empty($input['send_customer_data']) ? 1 : 0;
        $out['test_coupon']        = sanitize_text_field($input['test_coupon']        ?? '');
        $out['debug']              = !empty($input['debug'])              ? 1 : 0;
        return $out;
    }

    private function sanitize_statuses($raw){
        if (!is_array($raw)) return [];
        $all = array_keys(wc_get_order_statuses());
        $clean = [];
        foreach ($raw as $st){
            $st = sanitize_text_field($st);
            if (in_array('wc-'.$st, $all, true)) $clean[] = $st;
            elseif (strpos($st,'wc-') === 0 && in_array($st, $all, true)) $clean[] = substr($st, 3);
        }
        return array_values(array_unique($clean));
    }

    /* =========================================================
     * FIELD RENDERERS
     * ======================================================= */
    public function field_google_enabled(){
        $v = (int) $this->get_settings()['google_enabled'];
        printf('<label><input type="checkbox" name="%s[google_enabled]" value="1" %s /> %s</label>',
            esc_attr($this->option_key), checked(1, $v, false),
            esc_html__('Enviar eventos de compra a GA4','mad-suite'));
    }
    public function field_measurement_id(){
        $v = $this->get_settings()['measurement_id'];
        printf('<input type="text" class="regular-text" name="%s[measurement_id]" value="%s" placeholder="G-XXXXXXXX" />',
            esc_attr($this->option_key), esc_attr($v));
    }
    public function field_api_secret(){
        $v = $this->get_settings()['api_secret'];
        printf('<input type="password" class="regular-text" name="%s[api_secret]" value="%s" autocomplete="new-password" />',
            esc_attr($this->option_key), esc_attr($v));
        echo '<p class="description">'.esc_html__('GA4 → Administrador → Flujo de datos → Protocolo de medición → Secretos de API.','mad-suite').'</p>';
    }
    public function field_google_statuses(){
        $this->render_statuses_checkboxes('google_statuses');
    }

    public function field_meta_enabled(){
        $v = (int) $this->get_settings()['meta_enabled'];
        printf('<label><input type="checkbox" name="%s[meta_enabled]" value="1" %s /> %s</label>',
            esc_attr($this->option_key), checked(1, $v, false),
            esc_html__('Enviar eventos de compra a Meta CAPI','mad-suite'));
    }
    public function field_pixel_id(){
        $v = $this->get_settings()['pixel_id'];
        printf('<input type="text" class="regular-text" name="%s[pixel_id]" value="%s" placeholder="123456789012345" />',
            esc_attr($this->option_key), esc_attr($v));
        echo '<p class="description">'.esc_html__('Meta Business Manager → Administrador de eventos → tu Pixel → Configuración.','mad-suite').'</p>';
    }
    public function field_access_token(){
        $v = $this->get_settings()['access_token'];
        printf('<input type="password" class="regular-text" name="%s[access_token]" value="%s" autocomplete="new-password" />',
            esc_attr($this->option_key), esc_attr($v));
        echo '<p class="description">'.esc_html__('Meta Business Manager → Administrador de eventos → Configuración → Conversions API → Generar token de acceso.','mad-suite').'</p>';
    }
    public function field_meta_statuses(){
        $this->render_statuses_checkboxes('meta_statuses');
    }
    public function field_meta_test_code(){
        $v = $this->get_settings()['meta_test_code'];
        printf('<input type="text" class="regular-text" name="%s[meta_test_code]" value="%s" placeholder="TEST12345" />',
            esc_attr($this->option_key), esc_attr($v));
        echo '<p class="description">'.esc_html__('Meta → Administrador de eventos → Herramienta de prueba. Dejar vacío en producción.','mad-suite').'</p>';
    }
    public function field_send_customer_data(){
        $v = (int) $this->get_settings()['send_customer_data'];
        printf('<label><input type="checkbox" name="%s[send_customer_data]" value="1" %s /> %s</label>',
            esc_attr($this->option_key), checked(1, $v, false),
            esc_html__('Enviar email, teléfono y dirección hasheados con SHA-256 (mejora el match rate)','mad-suite'));
        echo '<p class="description">'.esc_html__('Los datos se hashean antes de enviarse — Meta nunca recibe datos en texto plano.','mad-suite').'</p>';
    }
    public function field_test_coupon(){
        $v = $this->get_settings()['test_coupon'];
        printf('<input type="text" class="regular-text" name="%s[test_coupon]" value="%s" placeholder="TEST-ADS" />',
            esc_attr($this->option_key), esc_attr($v));
        echo '<p class="description">'.esc_html__('Pedidos con este cupón se envían con value=0.01 en ambas plataformas (solo para pruebas).','mad-suite').'</p>';
    }
    public function field_debug(){
        $v = (int) $this->get_settings()['debug'];
        printf('<label><input type="checkbox" name="%s[debug]" value="1" %s /> %s</label>',
            esc_attr($this->option_key), checked(1, $v, false),
            esc_html__('Registrar actividad en WC Logger (no registra credenciales)','mad-suite'));
    }

    private function render_statuses_checkboxes($key){
        $selected = $this->get_settings()[$key];
        $all      = wc_get_order_statuses();
        echo '<fieldset>';
        foreach ($all as $wc_key => $label){
            $short = (strpos($wc_key,'wc-') === 0) ? substr($wc_key, 3) : $wc_key;
            printf('<label><input type="checkbox" name="%s[%s][]" value="%s" %s /> %s</label><br>',
                esc_attr($this->option_key), esc_attr($key), esc_attr($short),
                checked(in_array($short, $selected, true), true, false),
                esc_html($label));
        }
        echo '</fieldset>';
        echo '<p class="description">'.esc_html__('Recomendado: solo "En curso" (processing).','mad-suite').'</p>';
    }
};
