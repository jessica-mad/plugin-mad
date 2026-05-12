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
        $this->table = $wpdb->prefix . 'ga4_gclid_log';
    }

    public function slug()       { return 'ga4-server'; }
    public function title()      { return __('GA4 Server (Measurement Protocol)','mad-suite'); }
    public function menu_label() { return __('GA4 Server','mad-suite'); }
    public function menu_slug()  { return 'mad-'.$this->slug(); }

    /* ==== Hooks ==== */
    public function init(){
        $this->logger = wc_get_logger();
        $this->maybe_create_table();

        // Capturar gclid cuando el usuario llega desde un anuncio
        add_action('wp', [$this, 'capture_gclid_visit']);

        // Guardar gclid + client_id real en el pedido al hacer checkout
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_tracking_to_order']);

        // Enviar evento purchase a GA4 cuando cambia el estado del pedido
        add_action('woocommerce_order_status_changed', [$this, 'maybe_send_purchase_on_status'], 10, 4);
    }

    /* ==== Tabla DB ==== */
    private function maybe_create_table(){
        if (get_transient('ga4_gclid_table_ok')) return;

        global $wpdb;
        if ($wpdb->get_var("SHOW TABLES LIKE '{$this->table}'") === $this->table) {
            set_transient('ga4_gclid_table_ok', 1, DAY_IN_SECONDS);
            return;
        }

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$this->table} (
            id           bigint(20)    NOT NULL AUTO_INCREMENT,
            gclid        varchar(255)  NOT NULL,
            ga_client_id varchar(100)  DEFAULT '',
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
            UNIQUE KEY   gclid (gclid(191)),
            KEY          order_id (order_id)
        ) $charset;");

        set_transient('ga4_gclid_table_ok', 1, DAY_IN_SECONDS);
    }

    /* ==== Capturar gclid en visita de landing ==== */
    public function capture_gclid_visit(){
        // Usar GET param en la landing; fallback a cookie en páginas siguientes
        $gclid = $this->extract_gclid_from_request();
        if (!$gclid) return;

        global $wpdb;

        // Cada clic en un anuncio genera un gclid único — no duplicar
        if ($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE gclid = %s", $gclid
        ))) return;

        $wpdb->insert($this->table, [
            'gclid'        => $gclid,
            'ga_client_id' => $this->get_ga_client_id() ?: '',
            'utm_campaign' => isset($_GET['utm_campaign']) ? sanitize_text_field($_GET['utm_campaign']) : '',
            'utm_source'   => isset($_GET['utm_source'])   ? sanitize_text_field($_GET['utm_source'])   : 'google',
            'utm_medium'   => isset($_GET['utm_medium'])   ? sanitize_text_field($_GET['utm_medium'])   : 'cpc',
            'landing_url'  => $this->get_current_url(),
            'captured_at'  => current_time('mysql'),
        ], ['%s','%s','%s','%s','%s','%s','%s']);
    }

    /* ==== Guardar tracking en el pedido (solo cookies — el gclid no está en GET en checkout) ==== */
    public function save_tracking_to_order($order_id){
        $order = wc_get_order($order_id);
        if (!$order) return;

        $gclid        = $this->extract_gclid_from_cookie();
        $ga_client_id = $this->get_ga_client_id();

        if ($gclid)        $order->update_meta_data('_gclid',        $gclid);
        if ($ga_client_id) $order->update_meta_data('_ga_client_id', $ga_client_id);
        $order->save();

        // Vincular el registro de gclid en DB con este pedido
        if ($gclid) {
            global $wpdb;
            $wpdb->update(
                $this->table,
                [
                    'order_id'     => $order_id,
                    'order_total'  => (float) $order->get_total(),
                    'currency'     => $order->get_currency(),
                    'converted_at' => current_time('mysql'),
                    'ga_client_id' => $ga_client_id ?: '',
                ],
                ['gclid' => $gclid],
                ['%d','%f','%s','%s','%s'],
                ['%s']
            );
        }
    }

    /* ==== Enviar evento purchase al cambiar estado del pedido ==== */
    public function maybe_send_purchase_on_status($order_id, $from_status, $to_status, $order){
        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
            if (!$order) return;
        }

        // Anti-duplicado: solo disparar una vez por pedido
        if ($order->get_meta('_ga4_purchase_sent')) return;

        $settings = $this->get_settings();
        $targets  = array_map('strval', $settings['fire_statuses']);
        $to_short = (strpos($to_status,'wc-') === 0) ? substr($to_status, 3) : $to_status;

        if (!in_array($to_short, $targets, true)) return;

        $mid = trim($settings['measurement_id']);
        $sec = trim($settings['api_secret']);
        if ($mid === '' || $sec === '') {
            $this->debug_log('error', 'Falta measurement_id o api_secret. No se envió el evento.');
            return;
        }

        $this->send_ga4_purchase($order, $mid, $sec, $settings);

        $order->update_meta_data('_ga4_purchase_sent', 1);
        $order->save();
    }

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

        // Usar el client_id real del navegador (_ga cookie), no un UUID aleatorio
        $client_id = $order->get_meta('_ga_client_id') ?: wp_generate_uuid4();
        $user_id   = $order->get_user_id() ? (string) $order->get_user_id() : null;
        $gclid     = $order->get_meta('_gclid');

        $order_total = (float) $order->get_total();
        $test_coupon = trim($settings['test_coupon']);
        if ($test_coupon !== '' && $order->get_coupon_codes()) {
            if (in_array(strtolower($test_coupon), array_map('strtolower', $order->get_coupon_codes()), true)) {
                $order_total = max(0.01, $order_total);
            }
        }

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

        $this->debug_log('info', sprintf(
            'Enviando purchase pedido #%d | client_id: %s | gclid: %s',
            $order->get_id(),
            $client_id,
            $gclid ?: 'sin gclid'
        ));

        $response = wp_remote_post(
            add_query_arg(
                ['measurement_id' => $measurement_id, 'api_secret' => $api_secret],
                'https://www.google-analytics.com/mp/collect'
            ),
            [
                'method'  => 'POST',
                'headers' => ['Content-Type' => 'application/json'],
                'body'    => wp_json_encode($payload),
                'timeout' => 20,
            ]
        );

        if (is_wp_error($response)){
            $this->debug_log('error', 'Error HTTP: ' . implode(', ', $response->get_error_messages()));
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            $this->debug_log('info', sprintf('Evento enviado correctamente (HTTP %d)', $code));
        } else {
            $this->debug_log('error', sprintf('GA4 respondió HTTP %d: %s', $code, wp_remote_retrieve_body($response)));
        }
    }

    /* ==== Helpers de extracción ==== */

    // Para captura en landing: GET param (si llega del clic) o cookie
    private function extract_gclid_from_request(){
        if (!empty($_GET['gclid'])) {
            return sanitize_text_field($_GET['gclid']);
        }
        return $this->extract_gclid_from_cookie();
    }

    // Para checkout: solo cookie (el gclid no estará en la URL de checkout)
    private function extract_gclid_from_cookie(){
        if (!isset($_COOKIE['_gcl_aw'])) return null;
        $cookie = sanitize_text_field($_COOKIE['_gcl_aw']);
        if (preg_match('/GCL\.\d+\.(.+)/', $cookie, $matches)) {
            return $matches[1];
        }
        return null;
    }

    // client_id real de GA4 desde la cookie _ga (formato: GA1.1.XXXXXXXX.XXXXXXXX)
    private function get_ga_client_id(){
        if (!isset($_COOKIE['_ga'])) return null;
        $parts = explode('.', sanitize_text_field($_COOKIE['_ga']));
        return count($parts) >= 4 ? $parts[2] . '.' . $parts[3] : null;
    }

    private function get_current_url(){
        $url = home_url(add_query_arg([]));
        return substr($url, 0, 500);
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
        // Nunca registrar el api_secret en los logs
        $this->logger->log($level, $message, ['source' => 'ga4-mad-suite']);
    }

    /* ==== Settings API ==== */
    public function admin_init(){
        register_setting($this->option_group(), $this->option_key, [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
            'default'           => $this->defaults(),
        ]);

        add_settings_section(
            $this->section_id(),
            __('Ajustes de GA4 Measurement Protocol','mad-suite'),
            fn() => print('<p>' . esc_html__('Envía eventos de compra a GA4 directamente desde el servidor, sin depender de la página de gracias.','mad-suite') . '</p>'),
            $this->menu_slug()
        );

        foreach ([
            ['measurement_id', __('ID de medición (G-XXXXXXX)','mad-suite'), 'field_measurement_id'],
            ['api_secret',     __('Secreto de API','mad-suite'),              'field_api_secret'],
            ['fire_statuses',  __('Estados que disparan purchase','mad-suite'),'field_fire_statuses'],
            ['test_coupon',    __('Cupón de prueba','mad-suite'),              'field_test_coupon'],
            ['debug',          __('Modo depuración','mad-suite'),              'field_debug'],
        ] as [$id, $label, $cb]){
            add_settings_field($id, $label, [$this, $cb], $this->menu_slug(), $this->section_id());
        }
    }

    /* ==== Página principal con pestañas ==== */
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
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-status&tab=logs')); ?>" class="button">
                        <?php esc_html_e('Ver logs de WooCommerce','mad-suite'); ?>
                    </a>
                </p>
            <?php else: ?>
                <?php $this->render_dashboard(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ==== Dashboard de conversiones ==== */
    private function render_dashboard(){
        global $wpdb;

        $page     = max(1, intval($_GET['paged'] ?? 1));
        $per_page = 30;
        $offset   = ($page - 1) * $per_page;
        $filter   = isset($_GET['filter']) ? sanitize_key($_GET['filter']) : 'all';

        $where = match($filter) {
            'converted' => 'WHERE order_id IS NOT NULL',
            'pending'   => 'WHERE order_id IS NULL',
            default     => '',
        };

        $total_all       = (int)   $wpdb->get_var("SELECT COUNT(*) FROM {$this->table}");
        $total_converted = (int)   $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} WHERE order_id IS NOT NULL");
        $total_pending   = $total_all - $total_converted;
        $total_revenue   = (float) $wpdb->get_var("SELECT SUM(order_total) FROM {$this->table} WHERE order_id IS NOT NULL");
        $conv_rate       = $total_all > 0 ? round(($total_converted / $total_all) * 100, 1) : 0;

        $total_filtered = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table} $where");
        $rows           = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table} $where ORDER BY captured_at DESC LIMIT %d OFFSET %d",
            $per_page, $offset
        ));
        $total_pages = (int) ceil($total_filtered / $per_page);

        $settings = $this->get_settings();
        $base_url = admin_url('admin.php?page=' . $this->menu_slug() . '&tab=dashboard');
        ?>
        <style>
        .ga4-stats{display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap}
        .ga4-stat{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:16px 24px;min-width:130px;text-align:center;box-shadow:0 1px 2px rgba(0,0,0,.05)}
        .ga4-stat .val{font-size:2em;font-weight:700;color:#1d2327;display:block;line-height:1.2}
        .ga4-stat .lbl{font-size:.8em;color:#646970;margin-top:4px;display:block}
        .ga4-stat.green .val{color:#00a32a}
        .ga4-stat.blue  .val{color:#2271b1}
        .ga4-stat.red   .val{color:#d63638}
        .ga4-tbl{width:100%;border-collapse:collapse;background:#fff;border:1px solid #c3c4c7;border-radius:4px;margin-top:0}
        .ga4-tbl th{background:#f6f7f7;padding:9px 12px;text-align:left;font-size:.82em;border-bottom:1px solid #c3c4c7;white-space:nowrap}
        .ga4-tbl td{padding:9px 12px;border-bottom:1px solid #f0f0f1;font-size:.82em;vertical-align:middle}
        .ga4-tbl tr:last-child td{border-bottom:none}
        .ga4-tbl tbody tr:hover td{background:#f9f9f9}
        .ga4-badge{display:inline-block;padding:2px 9px;border-radius:10px;font-weight:600;font-size:.78em}
        .ga4-badge.ok{background:#edfaef;color:#00a32a}
        .ga4-badge.no{background:#f0f0f1;color:#646970}
        .ga4-mono{font-family:monospace;font-size:.78em;color:#8c8f94}
        .ga4-filters{display:flex;gap:8px;align-items:center;margin-bottom:16px;flex-wrap:wrap}
        .ga4-filters a{text-decoration:none;padding:5px 12px;border-radius:3px;border:1px solid #c3c4c7;font-size:.85em;background:#fff;color:#1d2327}
        .ga4-filters a.active{background:#2271b1;color:#fff;border-color:#2271b1}
        .ga4-info{background:#f0f6fc;border-left:4px solid #2271b1;padding:10px 14px;margin-bottom:20px;font-size:.88em;line-height:1.5}
        </style>

        <?php if (empty($settings['measurement_id']) || empty($settings['api_secret'])): ?>
        <div class="notice notice-warning inline" style="margin-bottom:20px">
            <p><?php printf(
                esc_html__('Configura el ID de medición y el Secreto de API en %s para activar el envío de eventos.','mad-suite'),
                '<a href="' . esc_url(admin_url('admin.php?page=' . $this->menu_slug() . '&tab=settings')) . '">' . esc_html__('Ajustes','mad-suite') . '</a>'
            ); ?></p>
        </div>
        <?php endif; ?>

        <div class="ga4-info">
            <?php esc_html_e('Cada clic en un anuncio de Google Ads genera un gclid único. Se registra cuando el usuario llega a la web y se marca como convertido si realiza una compra.','mad-suite'); ?>
            <strong><?php esc_html_e('Tip:','mad-suite'); ?></strong>
            <?php esc_html_e('Para ver el nombre de campaña activa los parámetros UTM en tus anuncios (utm_campaign, utm_source, utm_medium) — Google Ads los puede añadir automáticamente con el etiquetado automático + UTM manual.','mad-suite'); ?>
        </div>

        <div class="ga4-stats">
            <div class="ga4-stat">
                <span class="val"><?php echo esc_html(number_format($total_all)); ?></span>
                <span class="lbl"><?php esc_html_e('Clics de Ads','mad-suite'); ?></span>
            </div>
            <div class="ga4-stat green">
                <span class="val"><?php echo esc_html(number_format($total_converted)); ?></span>
                <span class="lbl"><?php esc_html_e('Conversiones','mad-suite'); ?></span>
            </div>
            <div class="ga4-stat blue">
                <span class="val"><?php echo esc_html($conv_rate); ?>%</span>
                <span class="lbl"><?php esc_html_e('Tasa de conversión','mad-suite'); ?></span>
            </div>
            <div class="ga4-stat">
                <span class="val"><?php echo esc_html(number_format($total_revenue, 2)); ?></span>
                <span class="lbl"><?php esc_html_e('Ingresos desde Ads','mad-suite'); ?></span>
            </div>
            <div class="ga4-stat red">
                <span class="val"><?php echo esc_html(number_format($total_pending)); ?></span>
                <span class="lbl"><?php esc_html_e('Sin compra','mad-suite'); ?></span>
            </div>
        </div>

        <div class="ga4-filters">
            <strong><?php esc_html_e('Filtrar:','mad-suite'); ?></strong>
            <a href="<?php echo esc_url($base_url); ?>"
               class="<?php echo $filter === 'all' ? 'active' : ''; ?>">
                <?php esc_html_e('Todos','mad-suite'); ?> (<?php echo esc_html($total_all); ?>)
            </a>
            <a href="<?php echo esc_url($base_url . '&filter=converted'); ?>"
               class="<?php echo $filter === 'converted' ? 'active' : ''; ?>">
                <?php esc_html_e('Convertidos','mad-suite'); ?> (<?php echo esc_html($total_converted); ?>)
            </a>
            <a href="<?php echo esc_url($base_url . '&filter=pending'); ?>"
               class="<?php echo $filter === 'pending' ? 'active' : ''; ?>">
                <?php esc_html_e('Sin compra','mad-suite'); ?> (<?php echo esc_html($total_pending); ?>)
            </a>
        </div>

        <?php if (empty($rows)): ?>
            <p style="color:#646970"><?php esc_html_e('No hay datos todavía. Los clics desde Google Ads aparecerán aquí automáticamente en cuanto un usuario llegue desde un anuncio.','mad-suite'); ?></p>
        <?php else: ?>

        <table class="ga4-tbl">
            <thead>
                <tr>
                    <th><?php esc_html_e('Fecha clic','mad-suite'); ?></th>
                    <th><?php esc_html_e('GCLID','mad-suite'); ?></th>
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
                    <td><?php echo esc_html(wp_date('d/m/Y H:i', strtotime($row->captured_at))); ?></td>
                    <td>
                        <span class="ga4-mono" title="<?php echo esc_attr($row->gclid); ?>">
                            <?php echo esc_html(substr($row->gclid, 0, 18)); ?>…
                        </span>
                    </td>
                    <td>
                        <?php if ($row->utm_campaign): ?>
                            <strong><?php echo esc_html($row->utm_campaign); ?></strong><br>
                            <small style="color:#8c8f94"><?php echo esc_html($row->utm_source . ' / ' . $row->utm_medium); ?></small>
                        <?php else: ?>
                            <span style="color:#c3c4c7">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row->landing_url): ?>
                            <a href="<?php echo esc_url($row->landing_url); ?>" target="_blank"
                               title="<?php echo esc_attr($row->landing_url); ?>"
                               style="font-size:.78em">
                                <?php
                                $path = wp_parse_url($row->landing_url, PHP_URL_PATH) ?: '/';
                                echo esc_html(strlen($path) > 30 ? substr($path, 0, 30) . '…' : $path);
                                ?>
                            </a>
                        <?php else: ?>
                            <span style="color:#c3c4c7">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row->order_id): ?>
                            <a href="<?php echo esc_url($this->get_order_edit_url($row->order_id)); ?>">
                                #<?php echo esc_html($row->order_id); ?>
                            </a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row->order_total !== null): ?>
                            <?php echo esc_html(number_format((float) $row->order_total, 2) . ' ' . $row->currency); ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row->order_id): ?>
                            <span class="ga4-badge ok"><?php esc_html_e('Convertido','mad-suite'); ?></span>
                        <?php else: ?>
                            <span class="ga4-badge no"><?php esc_html_e('Sin compra','mad-suite'); ?></span>
                        <?php endif; ?>
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
                'base'    => $base_url . '&paged=%#%',
                'format'  => '',
                'current' => $page,
                'total'   => $total_pages,
            ]); ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>
        <?php
    }

    /* ==== Settings helpers ==== */
    private function defaults(){
        return [
            'measurement_id' => '',
            'api_secret'     => '',
            'fire_statuses'  => ['processing'],
            'debug'          => 0,
            'test_coupon'    => '',
        ];
    }

    private function get_settings(){
        $opts = get_option($this->option_key, []);
        return wp_parse_args(is_array($opts) ? $opts : [], $this->defaults());
    }

    private function option_group(){ return 'group_'.$this->slug(); }
    private function section_id() { return 'section_'.$this->slug(); }

    public function sanitize_settings($input){
        $out = [];
        $out['measurement_id'] = isset($input['measurement_id']) ? sanitize_text_field($input['measurement_id']) : '';
        $out['api_secret']     = isset($input['api_secret'])     ? sanitize_text_field($input['api_secret'])     : '';
        $out['debug']          = !empty($input['debug']) ? 1 : 0;
        $out['test_coupon']    = isset($input['test_coupon'])    ? sanitize_text_field($input['test_coupon'])    : '';

        $all_statuses = array_keys(wc_get_order_statuses());
        $clean        = [];
        if (isset($input['fire_statuses']) && is_array($input['fire_statuses'])){
            foreach ($input['fire_statuses'] as $st){
                $st = sanitize_text_field($st);
                if (in_array('wc-'.$st, $all_statuses, true)) {
                    $clean[] = $st;
                } elseif (strpos($st,'wc-') === 0 && in_array($st, $all_statuses, true)) {
                    $clean[] = substr($st, 3);
                }
            }
        }
        $out['fire_statuses'] = array_values(array_unique($clean));
        return $out;
    }

    /* ==== Field renderers ==== */
    public function field_measurement_id(){
        $v = $this->get_settings()['measurement_id'];
        printf('<input type="text" class="regular-text" name="%s[measurement_id]" value="%s" placeholder="G-XXXXXXXX" />',
            esc_attr($this->option_key), esc_attr($v));
    }

    public function field_api_secret(){
        $v = $this->get_settings()['api_secret'];
        printf('<input type="password" class="regular-text" name="%s[api_secret]" value="%s" autocomplete="new-password" />',
            esc_attr($this->option_key), esc_attr($v));
        echo '<p class="description">'.esc_html__('GA4 → Administrador → Flujo de datos (Web) → Protocolo de medición → Secretos de API.','mad-suite').'</p>';
    }

    public function field_fire_statuses(){
        $selected = $this->get_settings()['fire_statuses'];
        $all      = wc_get_order_statuses();
        echo '<fieldset>';
        foreach ($all as $key => $label){
            $short = (strpos($key,'wc-') === 0) ? substr($key, 3) : $key;
            printf('<label><input type="checkbox" name="%s[fire_statuses][]" value="%s" %s /> %s</label><br>',
                esc_attr($this->option_key), esc_attr($short),
                checked(in_array($short, $selected, true), true, false),
                esc_html($label));
        }
        echo '</fieldset>';
        echo '<p class="description">'.esc_html__('Recomendado: solo "En curso" (processing) para evitar envíos duplicados.','mad-suite').'</p>';
    }

    public function field_test_coupon(){
        $v = $this->get_settings()['test_coupon'];
        printf('<input type="text" class="regular-text" name="%s[test_coupon]" value="%s" placeholder="TEST-GA4" />',
            esc_attr($this->option_key), esc_attr($v));
        echo '<p class="description">'.esc_html__('Pedidos con este cupón se envían con value=0.01 (solo para pruebas en GA4).','mad-suite').'</p>';
    }

    public function field_debug(){
        $v = (int) $this->get_settings()['debug'];
        printf('<label><input type="checkbox" name="%s[debug]" value="1" %s /> %s</label>',
            esc_attr($this->option_key),
            checked(1, $v, false),
            esc_html__('Registrar actividad en WC Logger (no registra credenciales)','mad-suite'));
    }
};
