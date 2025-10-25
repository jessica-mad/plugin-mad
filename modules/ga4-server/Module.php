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
        
        // Capturar gclid cuando se crea el pedido
        add_action('woocommerce_checkout_update_order_meta', [$this, 'save_gclid_to_order']);
    }

    public function save_gclid_to_order($order_id){
        $gclid = '';
        
        // Intentar desde parámetro GET (cuando el usuario llega con ?gclid=xxx)
        if (isset($_GET['gclid'])) {
            $gclid = sanitize_text_field($_GET['gclid']);
        }
        // Intentar desde cookie _gcl_aw (Google Ads la crea automáticamente)
        elseif (isset($_COOKIE['_gcl_aw'])) {
            $cookie_value = sanitize_text_field($_COOKIE['_gcl_aw']);
            // Formato: GCL.1234567890.gclid_aqui
            if (preg_match('/GCL\.\d+\.(.+)/', $cookie_value, $matches)) {
                $gclid = $matches[1];
            }
        }
        
        if ($gclid) {
            $order = wc_get_order($order_id);
            if ($order) {
                $order->update_meta_data('_gclid', $gclid);
                $order->save();
                
                $this->logger->info(sprintf('GCLID guardado en pedido #%s: %s', $order_id, $gclid), ['source' => 'ga4-mad-suite']);
            }
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
        
        $log_file = WC_LOG_DIR . 'ga4-mad-suite-' . date('Y-m-d') . '-' . wp_hash('ga4-mad-suite') . '.log';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $this->title() ); ?></h1>

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