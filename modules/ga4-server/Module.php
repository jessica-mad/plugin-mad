<?php
if ( ! defined('ABSPATH') ) exit;

return new class(MAD_Suite_Core::instance()) implements MAD_Suite_Module {

    /** @var MAD_Suite_Core */
    private $core;
    private $option_key;
    private $logger;

    public function __construct($core){
        $this->core = $core;
        $this->option_key = MAD_Suite_Core::option_key( $this->slug() );
        $this->logger = wc_get_logger();

        // Crear tablas de atribución si no existen
        $this->maybe_create_tables();
    }

    /* ==== Identidad del módulo ==== */
    public function slug(){ return 'ga4-server'; }
    public function title(){ return __('GA4 Server (Measurement Protocol)','mad-suite'); }
    public function menu_label(){ return __('GA4 Server','mad-suite'); }
    public function menu_slug(){ return 'mad-'.$this->slug(); }

    /* ==== Hooks públicos ==== */
    public function init(){
        // Enviamos purchase cuando cambia el estado del pedido a alguno seleccionado
        add_action('woocommerce_order_status_changed', [$this,'maybe_send_purchase_on_status'], 10, 4);

        // Capturar gclid ANTES de redirigir a pasarela de pago (Redsys, etc.)
        // Se guarda en el pedido para usarlo después cuando se confirme el pago
        add_action('woocommerce_checkout_order_processed', [$this, 'save_gclid_to_order'], 10);

        // FASE 2: Capturar touchpoints en todas las visitas
        add_action('template_redirect', [$this, 'capture_touchpoint'], 5);

        // FASE 4: Funnel Event Tracking
        add_action('woocommerce_after_single_product', [$this, 'track_view_item'], 10);
        add_action('woocommerce_add_to_cart', [$this, 'track_add_to_cart'], 10, 6);
        add_action('woocommerce_before_checkout_form', [$this, 'track_begin_checkout'], 10);
    }

    /* ==== Database Tables ==== */
    private function maybe_create_tables(){
        try {
            $db_version_key = 'mad_attribution_db_version';
            $current_version = '1.0.0';
            $installed_version = get_option($db_version_key, '0');

            if (version_compare($installed_version, $current_version, '<')) {
                $this->create_tables();
                update_option($db_version_key, $current_version);
                $this->logger->info('Tablas de atribución creadas/actualizadas', ['source' => 'ga4-mad-suite']);
            }
        } catch (\Exception $e) {
            // No romper el sitio si falla la creación de tablas
            $this->logger->error('Error creando tablas: ' . $e->getMessage(), ['source' => 'ga4-mad-suite']);
        }
    }

    private function create_tables(){
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Tabla 1: Touchpoints (puntos de contacto con click_ids)
        $table_touchpoints = $wpdb->prefix . 'mad_attribution_touchpoints';
        $sql_touchpoints = "CREATE TABLE {$table_touchpoints} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(100) NOT NULL,
            user_id BIGINT UNSIGNED NULL,

            gclid VARCHAR(255) NULL,
            fbclid VARCHAR(255) NULL,
            ttclid VARCHAR(255) NULL,
            msclkid VARCHAR(255) NULL,
            epik VARCHAR(255) NULL,
            twclid VARCHAR(255) NULL,
            li_fat_id VARCHAR(255) NULL,
            scid VARCHAR(255) NULL,

            utm_source VARCHAR(255) NULL,
            utm_medium VARCHAR(255) NULL,
            utm_campaign VARCHAR(255) NULL,
            utm_term VARCHAR(255) NULL,
            utm_content VARCHAR(255) NULL,

            referrer TEXT NULL,
            landing_page TEXT NULL,
            user_agent TEXT NULL,
            ip_address VARCHAR(45) NULL,

            timestamp DATETIME NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            converted TINYINT(1) DEFAULT 0,

            PRIMARY KEY (id),
            KEY idx_session (session_id),
            KEY idx_user (user_id),
            KEY idx_order (order_id),
            KEY idx_timestamp (timestamp),
            KEY idx_converted (converted)
        ) {$charset_collate};";

        // Tabla 2: Funnel Events (eventos del embudo de conversión)
        $table_funnel = $wpdb->prefix . 'mad_attribution_funnel_events';
        $sql_funnel = "CREATE TABLE {$table_funnel} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(100) NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            touchpoint_id BIGINT UNSIGNED NULL,

            event_type ENUM('view_item', 'add_to_cart', 'begin_checkout', 'purchase') NOT NULL,
            event_data TEXT NULL,

            product_id BIGINT UNSIGNED NULL,
            product_name VARCHAR(255) NULL,
            product_price DECIMAL(10,2) NULL,

            cart_total DECIMAL(10,2) NULL,
            order_id BIGINT UNSIGNED NULL,

            timestamp DATETIME NOT NULL,

            PRIMARY KEY (id),
            KEY idx_session (session_id),
            KEY idx_user (user_id),
            KEY idx_touchpoint (touchpoint_id),
            KEY idx_event_type (event_type),
            KEY idx_timestamp (timestamp),
            KEY idx_order (order_id)
        ) {$charset_collate};";

        // Tabla 3: Attribution Stats (estadísticas agregadas)
        $table_stats = $wpdb->prefix . 'mad_attribution_stats';
        $sql_stats = "CREATE TABLE {$table_stats} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,

            date DATE NOT NULL,
            platform VARCHAR(50) NOT NULL,

            utm_source VARCHAR(255) NULL,
            utm_medium VARCHAR(255) NULL,
            utm_campaign VARCHAR(255) NULL,

            sessions INT DEFAULT 0,
            view_item_count INT DEFAULT 0,
            add_to_cart_count INT DEFAULT 0,
            begin_checkout_count INT DEFAULT 0,
            purchases INT DEFAULT 0,
            revenue DECIMAL(10,2) DEFAULT 0,

            PRIMARY KEY (id),
            UNIQUE KEY unique_stat (date, platform, utm_source(100), utm_medium(100), utm_campaign(100)),
            KEY idx_date (date),
            KEY idx_platform (platform)
        ) {$charset_collate};";

        dbDelta($sql_touchpoints);
        dbDelta($sql_funnel);
        dbDelta($sql_stats);
    }

    /* ==== Click ID Detection ==== */
    private function detect_click_ids(){
        try {
            $click_ids = [];

            // Google Ads - GCLID
            if (isset($_GET['gclid'])) {
                $click_ids['gclid'] = sanitize_text_field($_GET['gclid']);
            } elseif (isset($_COOKIE['_gcl_aw'])) {
                $cookie_value = sanitize_text_field($_COOKIE['_gcl_aw']);
                if (preg_match('/GCL\.\d+\.(.+)/', $cookie_value, $matches)) {
                    $click_ids['gclid'] = $matches[1];
                }
            }

            // Facebook/Meta - FBCLID
            if (isset($_GET['fbclid'])) {
                $click_ids['fbclid'] = sanitize_text_field($_GET['fbclid']);
            }

            // TikTok - TTCLID
            if (isset($_GET['ttclid'])) {
                $click_ids['ttclid'] = sanitize_text_field($_GET['ttclid']);
            }

            // Microsoft Ads - MSCLKID
            if (isset($_GET['msclkid'])) {
                $click_ids['msclkid'] = sanitize_text_field($_GET['msclkid']);
            }

            // Pinterest - EPIK
            if (isset($_GET['epik'])) {
                $click_ids['epik'] = sanitize_text_field($_GET['epik']);
            }

            // Twitter/X - TWCLID
            if (isset($_GET['twclid'])) {
                $click_ids['twclid'] = sanitize_text_field($_GET['twclid']);
            }

            // LinkedIn - LI_FAT_ID
            if (isset($_GET['li_fat_id'])) {
                $click_ids['li_fat_id'] = sanitize_text_field($_GET['li_fat_id']);
            }

            // Snapchat - ScCid
            if (isset($_GET['ScCid'])) {
                $click_ids['scid'] = sanitize_text_field($_GET['ScCid']);
            }

            // UTM Parameters
            $utm_params = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];
            foreach ($utm_params as $param) {
                if (isset($_GET[$param])) {
                    $click_ids[$param] = sanitize_text_field($_GET[$param]);
                }
            }

            // Referrer
            if (!empty($_SERVER['HTTP_REFERER'])) {
                $click_ids['referrer'] = esc_url_raw($_SERVER['HTTP_REFERER']);
            }

            // Landing page
            $click_ids['landing_page'] = esc_url_raw($_SERVER['REQUEST_URI'] ?? '');

            // User Agent
            if (!empty($_SERVER['HTTP_USER_AGENT'])) {
                $click_ids['user_agent'] = sanitize_text_field($_SERVER['HTTP_USER_AGENT']);
            }

            // IP Address
            if (!empty($_SERVER['REMOTE_ADDR'])) {
                $click_ids['ip_address'] = sanitize_text_field($_SERVER['REMOTE_ADDR']);
            }

            // FASE 1: Solo logging (no guardar aún)
            if (!empty($click_ids)) {
                $detected_platforms = [];
                if (!empty($click_ids['gclid'])) $detected_platforms[] = 'Google Ads';
                if (!empty($click_ids['fbclid'])) $detected_platforms[] = 'Facebook';
                if (!empty($click_ids['ttclid'])) $detected_platforms[] = 'TikTok';
                if (!empty($click_ids['msclkid'])) $detected_platforms[] = 'Microsoft';
                if (!empty($click_ids['epik'])) $detected_platforms[] = 'Pinterest';
                if (!empty($click_ids['twclid'])) $detected_platforms[] = 'Twitter';
                if (!empty($click_ids['li_fat_id'])) $detected_platforms[] = 'LinkedIn';
                if (!empty($click_ids['scid'])) $detected_platforms[] = 'Snapchat';

                if (!empty($detected_platforms)) {
                    $this->logger->info(
                        sprintf('Click IDs detectados: %s', implode(', ', $detected_platforms)),
                        ['source' => 'ga4-mad-suite', 'click_ids' => $click_ids]
                    );
                }
            }

            return $click_ids;

        } catch (\Exception $e) {
            // Falla silenciosamente, no romper el sitio
            $this->logger->error('Error detectando click_ids: ' . $e->getMessage(), ['source' => 'ga4-mad-suite']);
            return [];
        }
    }

    /* ==== Session Tracking ==== */
    private function get_session_id(){
        try {
            $cookie_name = 'mad_attribution_sid';
            $cookie_lifetime = 30 * DAY_IN_SECONDS; // 30 días

            // Si ya existe cookie, usarla
            if (isset($_COOKIE[$cookie_name])) {
                return sanitize_text_field($_COOKIE[$cookie_name]);
            }

            // Crear nuevo session_id
            $session_id = 'mad_' . wp_generate_uuid4();

            // Guardar en cookie
            if (!headers_sent()) {
                setcookie(
                    $cookie_name,
                    $session_id,
                    time() + $cookie_lifetime,
                    COOKIEPATH,
                    COOKIE_DOMAIN,
                    is_ssl(),
                    true // httponly
                );
            }

            return $session_id;

        } catch (\Exception $e) {
            $this->logger->error('Error generando session_id: ' . $e->getMessage(), ['source' => 'ga4-mad-suite']);
            return 'mad_' . wp_generate_uuid4();
        }
    }

    public function capture_touchpoint(){
        try {
            // Solo capturar si hay click_ids o UTM parameters en la URL
            $has_tracking_params = false;
            $tracking_params = ['gclid', 'fbclid', 'ttclid', 'msclkid', 'epik', 'twclid', 'li_fat_id', 'ScCid',
                               'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

            foreach ($tracking_params as $param) {
                if (isset($_GET[$param])) {
                    $has_tracking_params = true;
                    break;
                }
            }

            // Solo guardar touchpoint si hay parámetros de tracking
            if (!$has_tracking_params) {
                return;
            }

            // Detectar click_ids
            $click_ids = $this->detect_click_ids();

            if (empty($click_ids)) {
                return;
            }

            // Obtener session_id
            $session_id = $this->get_session_id();

            // Obtener user_id si está logueado
            $user_id = is_user_logged_in() ? get_current_user_id() : null;

            // Guardar touchpoint
            $this->save_touchpoint($session_id, $user_id, $click_ids);

        } catch (\Exception $e) {
            // CRÍTICO: No romper el sitio si falla el tracking
            $this->logger->error('Error capturando touchpoint: ' . $e->getMessage(), ['source' => 'ga4-mad-suite']);
        }
    }

    private function save_touchpoint($session_id, $user_id, $click_ids){
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'mad_attribution_touchpoints';

            // Verificar si ya existe un touchpoint para esta sesión en las últimas 24 horas
            // Si existe, actualizar en lugar de crear duplicado
            $existing = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM {$table}
                WHERE session_id = %s
                AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY timestamp DESC
                LIMIT 1
            ", $session_id));

            $data = [
                'session_id' => $session_id,
                'user_id' => $user_id,
                'gclid' => $click_ids['gclid'] ?? null,
                'fbclid' => $click_ids['fbclid'] ?? null,
                'ttclid' => $click_ids['ttclid'] ?? null,
                'msclkid' => $click_ids['msclkid'] ?? null,
                'epik' => $click_ids['epik'] ?? null,
                'twclid' => $click_ids['twclid'] ?? null,
                'li_fat_id' => $click_ids['li_fat_id'] ?? null,
                'scid' => $click_ids['scid'] ?? null,
                'utm_source' => $click_ids['utm_source'] ?? null,
                'utm_medium' => $click_ids['utm_medium'] ?? null,
                'utm_campaign' => $click_ids['utm_campaign'] ?? null,
                'utm_term' => $click_ids['utm_term'] ?? null,
                'utm_content' => $click_ids['utm_content'] ?? null,
                'referrer' => $click_ids['referrer'] ?? null,
                'landing_page' => $click_ids['landing_page'] ?? null,
                'user_agent' => $click_ids['user_agent'] ?? null,
                'ip_address' => $click_ids['ip_address'] ?? null,
                'timestamp' => current_time('mysql'),
            ];

            if ($existing) {
                // Actualizar touchpoint existente
                $result = $wpdb->update(
                    $table,
                    $data,
                    ['id' => $existing]
                );

                if ($result !== false) {
                    $this->logger->info(
                        sprintf('Touchpoint actualizado (ID: %d, Session: %s)', $existing, $session_id),
                        ['source' => 'ga4-mad-suite']
                    );
                }
            } else {
                // Crear nuevo touchpoint
                $result = $wpdb->insert($table, $data);

                if ($result !== false) {
                    $this->logger->info(
                        sprintf('Nuevo touchpoint creado (ID: %d, Session: %s)', $wpdb->insert_id, $session_id),
                        ['source' => 'ga4-mad-suite']
                    );
                }
            }

            if ($wpdb->last_error) {
                throw new \Exception($wpdb->last_error);
            }

        } catch (\Exception $e) {
            // No romper el sitio, solo loggear
            $this->logger->error('Error guardando touchpoint: ' . $e->getMessage(), ['source' => 'ga4-mad-suite']);
        }
    }

    public function save_gclid_to_order($order_id){
        try {
            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }

            // Obtener session_id actual
            $session_id = $this->get_session_id();

            // Buscar touchpoint asociado a esta sesión
            global $wpdb;
            $table = $wpdb->prefix . 'mad_attribution_touchpoints';

            $touchpoint = $wpdb->get_row($wpdb->prepare("
                SELECT * FROM {$table}
                WHERE session_id = %s
                ORDER BY timestamp DESC
                LIMIT 1
            ", $session_id));

            // Preparar attribution data
            $attribution_data = [];

            if ($touchpoint) {
                // Usar datos del touchpoint si existe
                $attribution_data = [
                    'touchpoint_id' => $touchpoint->id,
                    'session_id' => $touchpoint->session_id,
                    'gclid' => $touchpoint->gclid,
                    'fbclid' => $touchpoint->fbclid,
                    'ttclid' => $touchpoint->ttclid,
                    'msclkid' => $touchpoint->msclkid,
                    'epik' => $touchpoint->epik,
                    'twclid' => $touchpoint->twclid,
                    'li_fat_id' => $touchpoint->li_fat_id,
                    'scid' => $touchpoint->scid,
                    'utm_source' => $touchpoint->utm_source,
                    'utm_medium' => $touchpoint->utm_medium,
                    'utm_campaign' => $touchpoint->utm_campaign,
                    'utm_term' => $touchpoint->utm_term,
                    'utm_content' => $touchpoint->utm_content,
                    'referrer' => $touchpoint->referrer,
                    'landing_page' => $touchpoint->landing_page,
                ];

                // FASE 3: Marcar touchpoint como convertido
                try {
                    $wpdb->update(
                        $table,
                        [
                            'converted' => 1,
                            'order_id' => $order_id
                        ],
                        ['id' => $touchpoint->id]
                    );

                    $this->logger->info(
                        sprintf('Touchpoint #%d marcado como convertido para pedido #%s', $touchpoint->id, $order_id),
                        ['source' => 'ga4-mad-suite']
                    );
                } catch (\Exception $e) {
                    $this->logger->error(
                        sprintf('Error marcando touchpoint como convertido: %s', $e->getMessage()),
                        ['source' => 'ga4-mad-suite']
                    );
                }
            } else {
                // Si no hay touchpoint, detectar click_ids directamente de la request
                $click_ids = $this->detect_click_ids();
                if (!empty($click_ids)) {
                    $attribution_data = array_merge(['session_id' => $session_id], $click_ids);
                }
            }

            // Guardar attribution data completa en order meta
            if (!empty($attribution_data)) {
                try {
                    // Limpiar nulls del array
                    $attribution_data = array_filter($attribution_data, function($value) {
                        return $value !== null && $value !== '';
                    });

                    $order->update_meta_data('_attribution_data', $attribution_data);

                    // Mantener compatibilidad: guardar GCLID en campo separado
                    if (!empty($attribution_data['gclid'])) {
                        $order->update_meta_data('_gclid', $attribution_data['gclid']);
                    }

                    $order->save();

                    $platforms = [];
                    if (!empty($attribution_data['gclid'])) $platforms[] = 'Google Ads';
                    if (!empty($attribution_data['fbclid'])) $platforms[] = 'Facebook';
                    if (!empty($attribution_data['ttclid'])) $platforms[] = 'TikTok';
                    if (!empty($attribution_data['msclkid'])) $platforms[] = 'Microsoft';

                    $platform_text = !empty($platforms) ? implode(', ', $platforms) : 'Direct/Organic';

                    $this->logger->info(
                        sprintf('Attribution data guardada en pedido #%s (%s)', $order_id, $platform_text),
                        ['source' => 'ga4-mad-suite', 'attribution' => $attribution_data]
                    );

                } catch (\Exception $e) {
                    // No romper el flujo si falla el guardado
                    $this->logger->error(
                        sprintf('Error guardando attribution data en pedido #%s: %s', $order_id, $e->getMessage()),
                        ['source' => 'ga4-mad-suite']
                    );
                }
            }

            // FASE 4: Trackear evento de purchase en funnel
            $this->track_purchase($order_id);

        } catch (\Exception $e) {
            // CRÍTICO: No romper el checkout bajo ninguna circunstancia
            $this->logger->error('Error en save_gclid_to_order: ' . $e->getMessage(), ['source' => 'ga4-mad-suite']);
        }
    }

    /* ==== Funnel Event Tracking ==== */
    private function get_touchpoint_id(){
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'mad_attribution_touchpoints';
            $session_id = $this->get_session_id();

            $touchpoint_id = $wpdb->get_var($wpdb->prepare("
                SELECT id FROM {$table}
                WHERE session_id = %s
                ORDER BY timestamp DESC
                LIMIT 1
            ", $session_id));

            return $touchpoint_id;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function track_view_item(){
        try {
            global $product, $wpdb;

            if (!$product || !is_a($product, 'WC_Product')) {
                return;
            }

            $session_id = $this->get_session_id();
            $user_id = is_user_logged_in() ? get_current_user_id() : null;
            $touchpoint_id = $this->get_touchpoint_id();

            $table = $wpdb->prefix . 'mad_attribution_funnel_events';

            $result = $wpdb->insert($table, [
                'session_id' => $session_id,
                'user_id' => $user_id,
                'touchpoint_id' => $touchpoint_id,
                'event_type' => 'view_item',
                'product_id' => $product->get_id(),
                'product_name' => $product->get_name(),
                'product_price' => (float) $product->get_price(),
                'event_data' => wp_json_encode([
                    'product_type' => $product->get_type(),
                    'sku' => $product->get_sku(),
                ]),
                'timestamp' => current_time('mysql'),
            ]);

            if ($result !== false) {
                $this->logger->debug(
                    sprintf('View Item tracked: %s (ID: %d)', $product->get_name(), $product->get_id()),
                    ['source' => 'ga4-mad-suite']
                );
            }

        } catch (\Exception $e) {
            // No romper la página, solo loggear
            $this->logger->error('Error tracking view_item: ' . $e->getMessage(), ['source' => 'ga4-mad-suite']);
        }
    }

    public function track_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data){
        try {
            global $wpdb;

            $product = wc_get_product($variation_id ?: $product_id);
            if (!$product) {
                return;
            }

            $session_id = $this->get_session_id();
            $user_id = is_user_logged_in() ? get_current_user_id() : null;
            $touchpoint_id = $this->get_touchpoint_id();

            // Calcular cart total
            $cart_total = WC()->cart ? (float) WC()->cart->get_cart_contents_total() : 0;

            $table = $wpdb->prefix . 'mad_attribution_funnel_events';

            $result = $wpdb->insert($table, [
                'session_id' => $session_id,
                'user_id' => $user_id,
                'touchpoint_id' => $touchpoint_id,
                'event_type' => 'add_to_cart',
                'product_id' => $product->get_id(),
                'product_name' => $product->get_name(),
                'product_price' => (float) $product->get_price(),
                'cart_total' => $cart_total,
                'event_data' => wp_json_encode([
                    'quantity' => $quantity,
                    'variation_id' => $variation_id,
                ]),
                'timestamp' => current_time('mysql'),
            ]);

            if ($result !== false) {
                $this->logger->debug(
                    sprintf('Add to Cart tracked: %s x%d', $product->get_name(), $quantity),
                    ['source' => 'ga4-mad-suite']
                );
            }

        } catch (\Exception $e) {
            // No romper add to cart, solo loggear
            $this->logger->error('Error tracking add_to_cart: ' . $e->getMessage(), ['source' => 'ga4-mad-suite']);
        }
    }

    public function track_begin_checkout(){
        try {
            global $wpdb;

            $session_id = $this->get_session_id();
            $user_id = is_user_logged_in() ? get_current_user_id() : null;
            $touchpoint_id = $this->get_touchpoint_id();

            $cart = WC()->cart;
            if (!$cart) {
                return;
            }

            $cart_total = (float) $cart->get_cart_contents_total();
            $cart_items = $cart->get_cart();

            $table = $wpdb->prefix . 'mad_attribution_funnel_events';

            $result = $wpdb->insert($table, [
                'session_id' => $session_id,
                'user_id' => $user_id,
                'touchpoint_id' => $touchpoint_id,
                'event_type' => 'begin_checkout',
                'cart_total' => $cart_total,
                'event_data' => wp_json_encode([
                    'item_count' => $cart->get_cart_contents_count(),
                    'items' => array_map(function($item) {
                        return [
                            'product_id' => $item['product_id'],
                            'quantity' => $item['quantity'],
                        ];
                    }, $cart_items),
                ]),
                'timestamp' => current_time('mysql'),
            ]);

            if ($result !== false) {
                $this->logger->debug(
                    sprintf('Begin Checkout tracked: %.2f EUR (%d items)', $cart_total, $cart->get_cart_contents_count()),
                    ['source' => 'ga4-mad-suite']
                );
            }

        } catch (\Exception $e) {
            // No romper checkout, solo loggear
            $this->logger->error('Error tracking begin_checkout: ' . $e->getMessage(), ['source' => 'ga4-mad-suite']);
        }
    }

    private function track_purchase($order_id){
        try {
            global $wpdb;

            $order = wc_get_order($order_id);
            if (!$order) {
                return;
            }

            $session_id = $this->get_session_id();
            $user_id = $order->get_user_id() ?: null;
            $touchpoint_id = $this->get_touchpoint_id();

            $table = $wpdb->prefix . 'mad_attribution_funnel_events';

            $items = [];
            foreach ($order->get_items() as $item) {
                if ($item instanceof WC_Order_Item_Product) {
                    $items[] = [
                        'product_id' => $item->get_product_id(),
                        'product_name' => $item->get_name(),
                        'quantity' => $item->get_quantity(),
                        'total' => (float) $item->get_total(),
                    ];
                }
            }

            $result = $wpdb->insert($table, [
                'session_id' => $session_id,
                'user_id' => $user_id,
                'touchpoint_id' => $touchpoint_id,
                'event_type' => 'purchase',
                'order_id' => $order_id,
                'cart_total' => (float) $order->get_total(),
                'event_data' => wp_json_encode([
                    'order_total' => (float) $order->get_total(),
                    'tax' => (float) $order->get_total_tax(),
                    'shipping' => (float) $order->get_shipping_total(),
                    'currency' => $order->get_currency(),
                    'items' => $items,
                ]),
                'timestamp' => current_time('mysql'),
            ]);

            if ($result !== false) {
                $this->logger->info(
                    sprintf('Purchase tracked: Order #%s (%.2f %s)', $order_id, $order->get_total(), $order->get_currency()),
                    ['source' => 'ga4-mad-suite']
                );
            }

        } catch (\Exception $e) {
            // No romper el proceso de pedido
            $this->logger->error('Error tracking purchase: ' . $e->getMessage(), ['source' => 'ga4-mad-suite']);
        }
    }

    /* ==== Registro de ajustes (Settings API) ==== */
    public function admin_init(){
        register_setting( $this->option_group(), $this->option_key, [
            'type' => 'array',
            'sanitize_callback' => [$this,'sanitize_settings'],
            'default' => [
                'measurement_id' => '',
                'api_secret'     => '',
                'fire_statuses'  => ['processing','completed'],
                'debug'          => 0,
                'test_coupon'    => '',
            ],
        ]);

        add_settings_section(
            $this->section_id(),
            __('Ajustes de GA4 Measurement Protocol','mad-suite'),
            function(){
                echo '<p>'.esc_html__('Envía eventos de compra de WooCommerce a GA4 directamente desde el servidor (sin depender de la página de gracias).','mad-suite').'</p>';
            },
            $this->menu_slug()
        );

        // ID de medición
        add_settings_field(
            'measurement_id',
            __('ID de medición (G-XXXXXXX)','mad-suite'),
            [$this,'field_measurement_id'],
            $this->menu_slug(),
            $this->section_id()
        );

        // API Secret
        add_settings_field(
            'api_secret',
            __('Secreto de API (Measurement Protocol)','mad-suite'),
            [$this,'field_api_secret'],
            $this->menu_slug(),
            $this->section_id()
        );

        // Estados de pedido
        add_settings_field(
            'fire_statuses',
            __('Estados de pedido que disparan purchase (server-side)','mad-suite'),
            [$this,'field_fire_statuses'],
            $this->menu_slug(),
            $this->section_id()
        );

        // Cupón de prueba
        add_settings_field(
            'test_coupon',
            __('Cupón de prueba','mad-suite'),
            [$this,'field_test_coupon'],
            $this->menu_slug(),
            $this->section_id()
        );

        // Debug
        add_settings_field(
            'debug',
            __('Modo depuración (log en error_log)','mad-suite'),
            [$this,'field_debug'],
            $this->menu_slug(),
            $this->section_id()
        );
    }

    /* ==== Página de ajustes ==== */
    public function render_settings_page(){
        if ( ! current_user_can(MAD_Suite_Core::CAPABILITY) ) return;

        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'settings';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $this->title() ); ?></h1>

            <h2 class="nav-tab-wrapper">
                <a href="?page=<?php echo esc_attr($this->menu_slug()); ?>&tab=settings"
                   class="nav-tab <?php echo $current_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Configuración', 'mad-suite'); ?>
                </a>
                <a href="?page=<?php echo esc_attr($this->menu_slug()); ?>&tab=attribution"
                   class="nav-tab <?php echo $current_tab === 'attribution' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Attribution Tracking', 'mad-suite'); ?>
                </a>
            </h2>

            <?php
            if ($current_tab === 'settings') {
                $this->render_settings_tab();
            } elseif ($current_tab === 'attribution') {
                $this->render_attribution_tab();
            }
            ?>
        </div>
        <?php
    }

    private function render_settings_tab(){
        $log_file = WC_LOG_DIR . 'ga4-mad-suite-' . date('Y-m-d') . '-' . wp_hash('ga4-mad-suite') . '.log';
        ?>
        <form method="post" action="options.php">
            <?php
            settings_fields( $this->option_group() );
            do_settings_sections( $this->menu_slug() );
            submit_button(__('Guardar cambios','mad-suite'));
            ?>
        </form>

        <hr />
        <h2><?php esc_html_e('Prueba rápida','mad-suite'); ?></h2>
        <p><?php esc_html_e('Realiza un pedido de prueba y cambia su estado a uno de los seleccionados. Revisa en GA4 → Administrar → Depuración (DebugView).','mad-suite'); ?></p>

        <h3><?php esc_html_e('Ver logs','mad-suite'); ?></h3>
        <p>
            <?php
            printf(
                esc_html__('Los logs se guardan en: %s','mad-suite'),
                '<code>' . esc_html($log_file) . '</code>'
            );
            ?>
        </p>
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wc-status&tab=logs')); ?>" class="button">
                <?php esc_html_e('Ver logs de WooCommerce','mad-suite'); ?>
            </a>
        </p>
        <?php
    }

    private function render_attribution_tab(){
        global $wpdb;
        $table_touchpoints = $wpdb->prefix . 'mad_attribution_touchpoints';
        $table_funnel = $wpdb->prefix . 'mad_attribution_funnel_events';

        // Obtener touchpoints recientes
        $touchpoints = $wpdb->get_results("
            SELECT *
            FROM {$table_touchpoints}
            ORDER BY timestamp DESC
            LIMIT 50
        ");

        // Contar totales de touchpoints
        $total_touchpoints = $wpdb->get_var("SELECT COUNT(*) FROM {$table_touchpoints}");
        $total_converted = $wpdb->get_var("SELECT COUNT(*) FROM {$table_touchpoints} WHERE converted = 1");
        $total_sessions = $wpdb->get_var("SELECT COUNT(DISTINCT session_id) FROM {$table_touchpoints}");

        // Contar eventos del funnel (últimos 30 días)
        $funnel_stats = $wpdb->get_results("
            SELECT
                event_type,
                COUNT(*) as count,
                COUNT(DISTINCT session_id) as unique_sessions
            FROM {$table_funnel}
            WHERE timestamp > DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY event_type
        ");

        $funnel_counts = [
            'view_item' => 0,
            'add_to_cart' => 0,
            'begin_checkout' => 0,
            'purchase' => 0,
        ];

        foreach ($funnel_stats as $stat) {
            $funnel_counts[$stat->event_type] = (int) $stat->unique_sessions;
        }

        // Calcular conversion rates
        $total_visitors = max(1, $funnel_counts['view_item']);
        $view_to_cart_rate = $funnel_counts['view_item'] > 0 ? ($funnel_counts['add_to_cart'] / $funnel_counts['view_item']) * 100 : 0;
        $cart_to_checkout_rate = $funnel_counts['add_to_cart'] > 0 ? ($funnel_counts['begin_checkout'] / $funnel_counts['add_to_cart']) * 100 : 0;
        $checkout_to_purchase_rate = $funnel_counts['begin_checkout'] > 0 ? ($funnel_counts['purchase'] / $funnel_counts['begin_checkout']) * 100 : 0;
        $overall_conversion_rate = $funnel_counts['view_item'] > 0 ? ($funnel_counts['purchase'] / $funnel_counts['view_item']) * 100 : 0;

        ?>
        <div style="margin-top: 20px;">
            <h2><?php esc_html_e('Estadísticas de Attribution', 'mad-suite'); ?></h2>

            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 30px;">
                <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 4px;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Total Touchpoints', 'mad-suite'); ?></h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 0;"><?php echo number_format($total_touchpoints); ?></p>
                </div>
                <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 4px;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Sesiones Únicas', 'mad-suite'); ?></h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 0;"><?php echo number_format($total_sessions); ?></p>
                </div>
                <div style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; border-radius: 4px;">
                    <h3 style="margin-top: 0;"><?php esc_html_e('Conversiones', 'mad-suite'); ?></h3>
                    <p style="font-size: 32px; font-weight: bold; margin: 0; color: #46b450;"><?php echo number_format($total_converted); ?></p>
                </div>
            </div>

            <!-- Funnel Visualization -->
            <h2><?php esc_html_e('Embudo de Conversión (últimos 30 días)', 'mad-suite'); ?></h2>
            <div style="background: #fff; border: 1px solid #ccd0d4; padding: 30px; border-radius: 4px; margin-bottom: 30px;">
                <div style="max-width: 600px; margin: 0 auto;">
                    <!-- View Item -->
                    <div style="margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <strong><?php esc_html_e('Vista de Producto', 'mad-suite'); ?></strong>
                            <span><?php echo number_format($funnel_counts['view_item']); ?> sesiones</span>
                        </div>
                        <div style="background: #f0f0f0; height: 40px; border-radius: 4px; position: relative; overflow: hidden;">
                            <div style="background: #2271b1; height: 100%; width: 100%;"></div>
                        </div>
                        <div style="text-align: right; margin-top: 5px; color: #666; font-size: 12px;">100%</div>
                    </div>

                    <!-- Arrow -->
                    <div style="text-align: center; margin: 10px 0; color: #999;">▼ <?php echo number_format($view_to_cart_rate, 1); ?>%</div>

                    <!-- Add to Cart -->
                    <div style="margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <strong><?php esc_html_e('Agregar al Carrito', 'mad-suite'); ?></strong>
                            <span><?php echo number_format($funnel_counts['add_to_cart']); ?> sesiones</span>
                        </div>
                        <div style="background: #f0f0f0; height: 40px; border-radius: 4px; position: relative; overflow: hidden;">
                            <div style="background: #72aee6; height: 100%; width: <?php echo ($funnel_counts['view_item'] > 0 ? ($funnel_counts['add_to_cart'] / $funnel_counts['view_item']) * 100 : 0); ?>%;"></div>
                        </div>
                        <div style="text-align: right; margin-top: 5px; color: #666; font-size: 12px;">
                            <?php echo number_format($view_to_cart_rate, 1); ?>% del total
                        </div>
                    </div>

                    <!-- Arrow -->
                    <div style="text-align: center; margin: 10px 0; color: #999;">▼ <?php echo number_format($cart_to_checkout_rate, 1); ?>%</div>

                    <!-- Begin Checkout -->
                    <div style="margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <strong><?php esc_html_e('Iniciar Checkout', 'mad-suite'); ?></strong>
                            <span><?php echo number_format($funnel_counts['begin_checkout']); ?> sesiones</span>
                        </div>
                        <div style="background: #f0f0f0; height: 40px; border-radius: 4px; position: relative; overflow: hidden;">
                            <div style="background: #f6a323; height: 100%; width: <?php echo ($funnel_counts['view_item'] > 0 ? ($funnel_counts['begin_checkout'] / $funnel_counts['view_item']) * 100 : 0); ?>%;"></div>
                        </div>
                        <div style="text-align: right; margin-top: 5px; color: #666; font-size: 12px;">
                            <?php echo number_format($funnel_counts['view_item'] > 0 ? ($funnel_counts['begin_checkout'] / $funnel_counts['view_item']) * 100 : 0, 1); ?>% del total
                        </div>
                    </div>

                    <!-- Arrow -->
                    <div style="text-align: center; margin: 10px 0; color: #999;">▼ <?php echo number_format($checkout_to_purchase_rate, 1); ?>%</div>

                    <!-- Purchase -->
                    <div style="margin-bottom: 20px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                            <strong style="color: #46b450;"><?php esc_html_e('Compra Realizada', 'mad-suite'); ?></strong>
                            <span style="color: #46b450;"><?php echo number_format($funnel_counts['purchase']); ?> pedidos</span>
                        </div>
                        <div style="background: #f0f0f0; height: 40px; border-radius: 4px; position: relative; overflow: hidden;">
                            <div style="background: #46b450; height: 100%; width: <?php echo ($funnel_counts['view_item'] > 0 ? ($funnel_counts['purchase'] / $funnel_counts['view_item']) * 100 : 0); ?>%;"></div>
                        </div>
                        <div style="text-align: right; margin-top: 5px; color: #46b450; font-size: 12px; font-weight: bold;">
                            Conversión Global: <?php echo number_format($overall_conversion_rate, 1); ?>%
                        </div>
                    </div>
                </div>

                <?php if ($funnel_counts['view_item'] === 0): ?>
                    <div style="text-align: center; padding: 40px; color: #666;">
                        <p><?php esc_html_e('No hay datos de funnel aún. Los eventos se rastrearán automáticamente cuando los usuarios naveguen por tu tienda.', 'mad-suite'); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <h2><?php esc_html_e('Touchpoints Recientes (últimos 50)', 'mad-suite'); ?></h2>

            <?php if (empty($touchpoints)): ?>
                <div class="notice notice-info">
                    <p><?php esc_html_e('No hay touchpoints capturados aún. Los touchpoints se crean cuando un visitante llega con parámetros de tracking (gclid, fbclid, utm_source, etc.).', 'mad-suite'); ?></p>
                    <p><?php esc_html_e('Prueba visitando tu sitio con: ?utm_source=test&utm_campaign=prueba', 'mad-suite'); ?></p>
                </div>
            <?php else: ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('ID', 'mad-suite'); ?></th>
                            <th><?php esc_html_e('Timestamp', 'mad-suite'); ?></th>
                            <th><?php esc_html_e('Plataforma', 'mad-suite'); ?></th>
                            <th><?php esc_html_e('UTM Source', 'mad-suite'); ?></th>
                            <th><?php esc_html_e('UTM Campaign', 'mad-suite'); ?></th>
                            <th><?php esc_html_e('Usuario', 'mad-suite'); ?></th>
                            <th><?php esc_html_e('Convertido', 'mad-suite'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($touchpoints as $tp):
                            // Detectar plataforma
                            $platform = 'Direct';
                            if (!empty($tp->gclid)) $platform = 'Google Ads';
                            elseif (!empty($tp->fbclid)) $platform = 'Facebook';
                            elseif (!empty($tp->ttclid)) $platform = 'TikTok';
                            elseif (!empty($tp->msclkid)) $platform = 'Microsoft';
                            elseif (!empty($tp->epik)) $platform = 'Pinterest';
                            elseif (!empty($tp->twclid)) $platform = 'Twitter';
                            elseif (!empty($tp->li_fat_id)) $platform = 'LinkedIn';
                            elseif (!empty($tp->scid)) $platform = 'Snapchat';
                            elseif (!empty($tp->utm_source)) $platform = $tp->utm_source;
                        ?>
                        <tr>
                            <td><code><?php echo esc_html($tp->id); ?></code></td>
                            <td><?php echo esc_html($tp->timestamp); ?></td>
                            <td><strong><?php echo esc_html($platform); ?></strong></td>
                            <td><?php echo esc_html($tp->utm_source ?? '-'); ?></td>
                            <td><?php echo esc_html($tp->utm_campaign ?? '-'); ?></td>
                            <td>
                                <?php
                                if ($tp->user_id) {
                                    $user = get_userdata($tp->user_id);
                                    echo $user ? esc_html($user->user_login) : '#' . esc_html($tp->user_id);
                                } else {
                                    echo '<em>' . esc_html__('Visitante', 'mad-suite') . '</em>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ($tp->converted): ?>
                                    <span style="color: #46b450; font-weight: bold;">✓
                                        <?php if ($tp->order_id): ?>
                                            <a href="<?php echo esc_url(admin_url('post.php?post=' . $tp->order_id . '&action=edit')); ?>">
                                                #<?php echo esc_html($tp->order_id); ?>
                                            </a>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color: #999;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /* ==== Settings API helpers ==== */
    private function option_group(){ return 'group_'.$this->slug(); }
    private function section_id(){ return 'section_'.$this->slug(); }

    private function get_settings(){
        $defaults = [
            'measurement_id' => '',
            'api_secret'     => '',
            'fire_statuses'  => ['processing','completed'],
            'debug'          => 0,
            'test_coupon'    => '',
        ];
        $opts = get_option( $this->option_key, [] );
        return wp_parse_args( is_array($opts) ? $opts : [], $defaults );
    }

    public function sanitize_settings($input){
        $out = [];
        $out['measurement_id'] = isset($input['measurement_id']) ? sanitize_text_field($input['measurement_id']) : '';
        $out['api_secret']     = isset($input['api_secret']) ? sanitize_text_field($input['api_secret']) : '';
        $out['debug']          = !empty($input['debug']) ? 1 : 0;
        $out['test_coupon']    = isset($input['test_coupon']) ? sanitize_text_field($input['test_coupon']) : '';

        $statuses = array_keys( wc_get_order_statuses() );
        $clean_statuses = [];
        if ( isset($input['fire_statuses']) && is_array($input['fire_statuses']) ){
            foreach ($input['fire_statuses'] as $st){
                $st = sanitize_text_field($st);
                if ( in_array('wc-'.$st, $statuses, true) ) {
                    $clean_statuses[] = $st;
                } elseif ( in_array($st, $statuses, true) && strpos($st,'wc-') === 0 ) {
                    $clean_statuses[] = substr($st, 3);
                }
            }
        }
        $out['fire_statuses'] = array_values(array_unique($clean_statuses));

        return $out;
    }

    /* ==== Campos ==== */
    public function field_measurement_id(){
        $v = $this->get_settings()['measurement_id'];
        printf('<input type="text" class="regular-text" name="%s[measurement_id]" value="%s" placeholder="G-XXXXXXXX" />',
            esc_attr($this->option_key), esc_attr($v)
        );
    }

    public function field_api_secret(){
        $v = $this->get_settings()['api_secret'];
        printf('<input type="text" class="regular-text" name="%s[api_secret]" value="%s" placeholder="%s" />',
            esc_attr($this->option_key), esc_attr($v), esc_attr__('Secreto de API','mad-suite')
        );
        echo '<p class="description">'.esc_html__('GA4 → Administrador → Flujo de datos (Web) → Protocolo de medición → Secretos de API.','mad-suite').'</p>';
    }

    public function field_fire_statuses(){
        $selected = $this->get_settings()['fire_statuses'];
        $all = wc_get_order_statuses();
        echo '<fieldset>';
        foreach ($all as $key => $label){
            $short = (strpos($key,'wc-') === 0) ? substr($key,3) : $key;
            printf(
                '<label><input type="checkbox" name="%s[fire_statuses][]" value="%s" %s /> %s</label><br>',
                esc_attr($this->option_key),
                esc_attr($short),
                checked( in_array($short, $selected, true), true, false ),
                esc_html($label)
            );
        }
        echo '</fieldset>';
        echo '<p class="description">'.esc_html__('Recomendado: processing y completed.','mad-suite').'</p>';
    }

    public function field_test_coupon(){
        $v = $this->get_settings()['test_coupon'];
        printf('<input type="text" class="regular-text" name="%s[test_coupon]" value="%s" placeholder="TEST-GA4" />',
            esc_attr($this->option_key), esc_attr($v)
        );
        echo '<p class="description">'.esc_html__('Si un pedido usa este cupón, se forzará value=0.01 aunque el total sea 0€ (solo para pruebas en GA4).','mad-suite').'</p>';
    }

    public function field_debug(){
        $v = (int) $this->get_settings()['debug'];
        printf('<label><input type="checkbox" name="%s[debug]" value="1" %s /> %s</label>',
            esc_attr($this->option_key),
            checked(1, $v, false),
            esc_html__('Registrar peticiones/respuestas en el log del servidor (error_log)','mad-suite')
        );
    }

    /* ==== Lógica de envío ==== */
    public function maybe_send_purchase_on_status( $order_id, $from_status, $to_status, $order ){
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order($order_id);
            if ( ! $order ) return;
        }

        $settings = $this->get_settings();
        
        $this->debug_log('info', '========== INICIO GA4 SERVER ==========');
        $this->debug_log('info', sprintf('Pedido #%s | Estado: %s → %s', $order_id, $from_status, $to_status));
        
        $targets  = array_map('strval', $settings['fire_statuses']);
        $to_short = (strpos($to_status,'wc-') === 0) ? substr($to_status,3) : $to_status;

        $this->debug_log('info', sprintf('Estados objetivo: %s | Estado actual: %s', implode(', ', $targets), $to_short));

        if ( ! in_array($to_short, $targets, true) ) {
            $this->debug_log('info', 'Estado no coincide. No se envía evento.');
            return;
        }

        $mid = trim($settings['measurement_id']);
        $sec = trim($settings['api_secret']);
        if ( $mid === '' || $sec === '' ) {
            $this->debug_log('error', 'Falta measurement_id o api_secret.');
            return;
        }

        $this->debug_log('info', 'Configuración válida. Procediendo a enviar evento...');
        $this->send_ga4_purchase( $order, $mid, $sec, $settings );
    }

    private function send_ga4_purchase( WC_Order $order, $measurement_id, $api_secret, array $settings ){
        $endpoint = sprintf(
            'https://www.google-analytics.com/mp/collect?measurement_id=%s&api_secret=%s',
            rawurlencode($measurement_id),
            rawurlencode($api_secret)
        );

        // Items
        $items = [];
        foreach ( $order->get_items() as $item_id => $item ){
            if ( ! $item instanceof WC_Order_Item_Product ) continue;
            $product = $item->get_product();
            $price   = $item->get_total() / max(1, $item->get_quantity());
            $items[] = [
                'item_id'    => $product ? (string) $product->get_id() : (string) $item_id,
                'item_name'  => $item->get_name(),
                'quantity'   => (int) $item->get_quantity(),
                'price'      => (float) wc_format_decimal( $price, 2 ),
                'item_brand' => $product ? (string) $product->get_attribute('brand') : '',
                'item_variant' => $product && $product->is_type('variation') ? $product->get_sku() : '',
            ];
        }

        $this->debug_log('info', sprintf('Items del pedido: %d productos', count($items)));

        // client_id / user_id
        $client_id = wp_generate_uuid4();
        $user_id   = $order->get_user_id() ? (string) $order->get_user_id() : null;

        // Detectar cupón de prueba y forzar valor mínimo
        $order_total = (float) $order->get_total();
        $is_test_order = false;
        
        $test_coupon = trim($settings['test_coupon']);
        if ($test_coupon !== '' && $order->get_coupon_codes()) {
            $order_coupons = array_map('strtolower', $order->get_coupon_codes());
            $this->debug_log('info', sprintf('Cupones del pedido: %s', implode(', ', $order_coupons)));
            
            if (in_array(strtolower($test_coupon), $order_coupons, true)) {
                $is_test_order = true;
                $original_total = $order_total;
                $order_total = max(0.01, $order_total);
                $this->debug_log('warning', sprintf('Pedido de prueba detectado (cupón: %s)', $test_coupon));
                $this->debug_log('warning', sprintf('Valor original: %s → Forzado a: %s para GA4', $original_total, $order_total));
            }
        } else {
            $this->debug_log('info', sprintf('Valor del pedido: %s', $order_total));
        }

        // Obtener gclid del pedido
        $gclid = $order->get_meta('_gclid');

        $params = [
            'transaction_id' => (string) $order->get_id(),
            'value'          => $order_total,
            'currency'       => $order->get_currency(),
            'tax'            => (float) $order->get_total_tax(),
            'shipping'       => (float) $order->get_shipping_total(),
            'coupon'         => implode(',', $order->get_coupon_codes() ),
            'items'          => $items,
        ];

        // Añadir gclid si existe (CRÍTICO para Google Ads)
        if ($gclid) {
            $params['gclid'] = $gclid;
            $this->debug_log('info', sprintf('GCLID incluido: %s... (Google Ads podrá atribuir)', substr($gclid, 0, 20)));
        } else {
            $this->debug_log('warning', 'Sin GCLID: Esta conversión NO se atribuirá en Google Ads');
        }

        // Marcar pedidos de prueba en GA4
        if ($is_test_order) {
            $params['test_order'] = true;
        }

        $payload = [
            'client_id' => $client_id,
            'non_personalized_ads' => false,
            'events' => [[
                'name'   => 'purchase',
                'params' => $params,
            ]],
        ];
        if ( $user_id ) $payload['user_id'] = $user_id;

        $args = [
            'method'  => 'POST',
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $payload ),
            'timeout' => 20,
        ];

        $this->debug_log('info', 'Enviando evento a GA4...');
        $this->debug_log('debug', 'Endpoint: ' . $endpoint);
        $this->debug_log('debug', 'Payload: ' . wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $response = wp_remote_post( $endpoint, $args );
        
        if ( is_wp_error($response) ){
            $this->debug_log('error', 'Error HTTP: ' . implode(', ', $response->get_error_messages()));
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code >= 200 && $code < 300) {
            $this->debug_log('info', sprintf('Evento enviado exitosamente (HTTP %d)', $code));
            if ($body) {
                $this->debug_log('debug', 'Respuesta: ' . $body);
            }
        } else {
            $this->debug_log('error', sprintf('Error en respuesta (HTTP %d): %s', $code, $body));
        }
        
        $this->debug_log('info', '========== FIN GA4 SERVER ==========');
    }

    private function debug_log($level, $message, $context = []){
        $settings = $this->get_settings();
        if ( empty($settings['debug']) ) return;
        
        $this->logger->log($level, $message, array_merge([
            'source' => 'ga4-mad-suite',
        ], is_array($context) ? $context : []));
    }
};