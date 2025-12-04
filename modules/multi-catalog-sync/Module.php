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

        // Auto-load classes
        spl_autoload_register([$this, 'autoload']);
    }

    private function autoload($class){
        // Namespace: MAD_Suite\MultiCatalogSync\...
        $prefix = 'MAD_Suite\\MultiCatalogSync\\';
        if ( strpos($class, $prefix) !== 0 ) return;

        $relative = substr($class, strlen($prefix));
        $file = __DIR__ . '/includes/' . str_replace('\\', '/', $relative) . '.php';

        if ( file_exists($file) ) {
            require_once $file;
        }
    }

    /* ==== Identidad del módulo ==== */
    public function slug(){ return 'multi-catalog-sync'; }
    public function title(){ return __('Multi-Catalog Sync','mad-suite'); }
    public function menu_label(){ return __('Catalog Sync','mad-suite'); }
    public function menu_slug(){ return 'mad-'.$this->slug(); }

    public function description(){
        return __('Sincroniza productos de WooCommerce con Google Merchant Center, Facebook Catalog y Pinterest.','mad-suite');
    }

    public function required_plugins(){
        return ['WooCommerce' => 'woocommerce/woocommerce.php'];
    }

    /* ==== Hooks públicos ==== */
    public function init(){
        // Product meta box
        add_action('add_meta_boxes', [$this, 'add_product_meta_box']);
        add_action('save_post_product', [$this, 'save_product_meta'], 10, 1);

        // Category fields
        add_action('product_cat_add_form_fields', [$this, 'add_category_fields'], 10);
        add_action('product_cat_edit_form_fields', [$this, 'edit_category_fields'], 10);
        add_action('created_product_cat', [$this, 'save_category_fields'], 10);
        add_action('edited_product_cat', [$this, 'save_category_fields'], 10);

        // Tag fields
        add_action('product_tag_add_form_fields', [$this, 'add_tag_fields'], 10);
        add_action('product_tag_edit_form_fields', [$this, 'edit_tag_fields'], 10);
        add_action('created_product_tag', [$this, 'save_tag_fields'], 10);
        add_action('edited_product_tag', [$this, 'save_tag_fields'], 10);

        // Webhooks for real-time sync
        add_action('woocommerce_update_product', [$this, 'queue_product_sync'], 10, 1);
        add_action('woocommerce_reduce_order_stock', [$this, 'queue_stock_update'], 10, 1);

        // Cron for scheduled sync
        add_action('madsuite_catalog_sync_cron', [$this, 'run_scheduled_sync']);
        add_action('mcs_process_queue_hook', [$this, 'process_sync_queue']);

        // AJAX handlers
        add_action('wp_ajax_mcs_search_google_category', [$this, 'ajax_search_google_category']);
        add_action('wp_ajax_mcs_manual_sync', [$this, 'ajax_manual_sync']);
        add_action('wp_ajax_mcs_get_sync_status', [$this, 'ajax_get_sync_status']);
        add_action('wp_ajax_mcs_sync_specific_products', [$this, 'ajax_sync_specific_products']);
    }

    /* ==== Registro de ajustes (Settings API) ==== */
    public function admin_init(){
        // Enqueue admin assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        register_setting( $this->option_group(), $this->option_key, [
            'type' => 'array',
            'sanitize_callback' => [$this,'sanitize_settings'],
            'default' => $this->get_default_settings(),
        ]);

        // General Settings Section
        add_settings_section(
            'mcs_general',
            __('Configuración General','mad-suite'),
            [$this, 'render_general_section'],
            $this->menu_slug()
        );

        add_settings_field(
            'default_brand',
            __('Marca predeterminada','mad-suite'),
            [$this,'field_default_brand'],
            $this->menu_slug(),
            'mcs_general'
        );

        add_settings_field(
            'allow_brand_override',
            __('Permitir sobrescribir marca','mad-suite'),
            [$this,'field_allow_brand_override'],
            $this->menu_slug(),
            'mcs_general'
        );

        add_settings_field(
            'sync_schedule',
            __('Programación de sincronización','mad-suite'),
            [$this,'field_sync_schedule'],
            $this->menu_slug(),
            'mcs_general'
        );

        // Google Merchant Center Section
        add_settings_section(
            'mcs_google',
            __('Google Merchant Center','mad-suite'),
            [$this, 'render_google_section'],
            $this->menu_slug()
        );

        add_settings_field(
            'google_enabled',
            __('Habilitar sincronización','mad-suite'),
            [$this,'field_google_enabled'],
            $this->menu_slug(),
            'mcs_google'
        );

        add_settings_field(
            'google_merchant_id',
            __('Merchant ID','mad-suite'),
            [$this,'field_google_merchant_id'],
            $this->menu_slug(),
            'mcs_google'
        );

        add_settings_field(
            'google_service_account_json',
            __('Service Account JSON','mad-suite'),
            [$this,'field_google_service_account_json'],
            $this->menu_slug(),
            'mcs_google'
        );

        add_settings_field(
            'google_data_source_id',
            __('Data Source ID','mad-suite'),
            [$this,'field_google_data_source_id'],
            $this->menu_slug(),
            'mcs_google'
        );

        add_settings_field(
            'google_feed_label',
            __('Feed Label','mad-suite'),
            [$this,'field_google_feed_label'],
            $this->menu_slug(),
            'mcs_google'
        );

        // Facebook Catalog Section
        add_settings_section(
            'mcs_facebook',
            __('Facebook Catalog','mad-suite'),
            [$this, 'render_facebook_section'],
            $this->menu_slug()
        );

        add_settings_field(
            'facebook_enabled',
            __('Habilitar sincronización','mad-suite'),
            [$this,'field_facebook_enabled'],
            $this->menu_slug(),
            'mcs_facebook'
        );

        add_settings_field(
            'facebook_catalog_id',
            __('Catalog ID','mad-suite'),
            [$this,'field_facebook_catalog_id'],
            $this->menu_slug(),
            'mcs_facebook'
        );

        add_settings_field(
            'facebook_access_token',
            __('System User Access Token','mad-suite'),
            [$this,'field_facebook_access_token'],
            $this->menu_slug(),
            'mcs_facebook'
        );

        // Pinterest Catalog Section
        add_settings_section(
            'mcs_pinterest',
            __('Pinterest Catalog','mad-suite'),
            [$this, 'render_pinterest_section'],
            $this->menu_slug()
        );

        add_settings_field(
            'pinterest_enabled',
            __('Habilitar sincronización','mad-suite'),
            [$this,'field_pinterest_enabled'],
            $this->menu_slug(),
            'mcs_pinterest'
        );

        add_settings_field(
            'pinterest_catalog_id',
            __('Catalog ID','mad-suite'),
            [$this,'field_pinterest_catalog_id'],
            $this->menu_slug(),
            'mcs_pinterest'
        );

        add_settings_field(
            'pinterest_access_token',
            __('Access Token','mad-suite'),
            [$this,'field_pinterest_access_token'],
            $this->menu_slug(),
            'mcs_pinterest'
        );

        // Custom Labels Section
        add_settings_section(
            'mcs_custom_labels',
            __('Custom Labels','mad-suite'),
            [$this, 'render_custom_labels_section'],
            $this->menu_slug()
        );

        // Add fields for each custom label (0-4)
        for ($i = 0; $i <= 4; $i++) {
            add_settings_field(
                "custom_label_{$i}_name",
                sprintf(__('Custom Label %d','mad-suite'), $i),
                [$this, 'field_custom_label_name'],
                $this->menu_slug(),
                'mcs_custom_labels',
                ['label_index' => $i]
            );
        }
    }

    /* ==== Admin Assets ==== */
    public function enqueue_admin_assets($hook){
        // Only on our settings page, product edit pages, and taxonomy edit pages
        $allowed_hooks = [
            'post.php',
            'post-new.php',
            'edit-tags.php',  // Category/Tag edit pages
            'term.php',       // Category/Tag edit pages (new UI)
        ];

        if ( strpos($hook, $this->menu_slug()) === false && !in_array($hook, $allowed_hooks) ) {
            return;
        }

        $screen = get_current_screen();

        // Allow on our settings page
        if ( strpos($hook, $this->menu_slug()) !== false ) {
            // Settings page - allow
        }
        // Allow on product pages
        elseif ( $screen && $screen->post_type === 'product' ) {
            // Product edit page - allow
        }
        // Allow on product_cat and product_tag taxonomy pages
        elseif ( $screen && in_array($screen->taxonomy, ['product_cat', 'product_tag']) ) {
            // Taxonomy edit page - allow
        }
        else {
            return;
        }

        wp_enqueue_style(
            'mcs-admin-styles',
            plugin_dir_url(__FILE__) . 'assets/css/admin-styles.css',
            [],
            '1.0.0'
        );

        wp_enqueue_script(
            'mcs-admin-scripts',
            plugin_dir_url(__FILE__) . 'assets/js/admin-scripts.js',
            ['jquery', 'jquery-ui-autocomplete'],
            '1.0.0',
            true
        );

        wp_localize_script('mcs-admin-scripts', 'mcsAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mcs_ajax'),
            'strings' => [
                'searching' => __('Buscando...', 'mad-suite'),
                'no_results' => __('No se encontraron resultados', 'mad-suite'),
                'syncing' => __('Sincronizando...', 'mad-suite'),
                'sync_complete' => __('Sincronización completada', 'mad-suite'),
                'sync_error' => __('Error en sincronización', 'mad-suite'),
            ]
        ]);
    }

    /* ==== Página de ajustes ==== */
    public function render_settings_page(){
        if ( ! current_user_can(MAD_Suite_Core::CAPABILITY) ) return;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( $this->title() ); ?></h1>

            <?php settings_errors(); ?>

            <div class="mcs-admin-wrapper">
                <div class="mcs-tabs">
                    <nav class="nav-tab-wrapper">
                        <a href="#tab-general" class="nav-tab nav-tab-active"><?php esc_html_e('General', 'mad-suite'); ?></a>
                        <a href="#tab-google" class="nav-tab"><?php esc_html_e('Google Merchant', 'mad-suite'); ?></a>
                        <a href="#tab-facebook" class="nav-tab"><?php esc_html_e('Facebook', 'mad-suite'); ?></a>
                        <a href="#tab-pinterest" class="nav-tab"><?php esc_html_e('Pinterest', 'mad-suite'); ?></a>
                        <a href="#tab-dashboard" class="nav-tab"><?php esc_html_e('Dashboard', 'mad-suite'); ?></a>
                    </nav>

                    <form method="post" action="options.php">
                        <?php settings_fields( $this->option_group() ); ?>

                        <div id="tab-general" class="mcs-tab-content">
                            <?php do_settings_sections( $this->menu_slug() ); ?>
                        </div>

                        <div id="tab-google" class="mcs-tab-content" style="display:none;">
                            <h2><?php esc_html_e('Google Merchant Center', 'mad-suite'); ?></h2>
                            <?php
                            do_settings_fields($this->menu_slug(), 'mcs_google');
                            ?>
                        </div>

                        <div id="tab-facebook" class="mcs-tab-content" style="display:none;">
                            <h2><?php esc_html_e('Facebook Catalog', 'mad-suite'); ?></h2>
                            <?php
                            do_settings_fields($this->menu_slug(), 'mcs_facebook');
                            ?>
                        </div>

                        <div id="tab-pinterest" class="mcs-tab-content" style="display:none;">
                            <h2><?php esc_html_e('Pinterest Catalog', 'mad-suite'); ?></h2>
                            <?php
                            do_settings_fields($this->menu_slug(), 'mcs_pinterest');
                            ?>
                        </div>

                        <div id="tab-dashboard" class="mcs-tab-content" style="display:none;">
                            <?php include __DIR__ . '/views/dashboard.php'; ?>
                        </div>

                        <?php submit_button(__('Guardar cambios','mad-suite')); ?>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /* ==== Settings API helpers ==== */
    private function option_group(){ return 'group_'.$this->slug(); }

    private function get_default_settings(){
        return [
            // General
            'default_brand' => '',
            'allow_brand_override' => 1,
            'sync_schedule' => '6', // hours
            'sync_only_changes' => 1,
            'sync_stock_realtime' => 1,

            // Google
            'google_enabled' => 0,
            'google_merchant_id' => '',
            'google_service_account_json' => '',
            'google_data_source_id' => '', // API Merchant Center data source ID
            'google_feed_label' => 'ES', // Feed label (EN, ES, etc.)

            // Facebook
            'facebook_enabled' => 0,
            'facebook_catalog_id' => '',
            'facebook_access_token' => '',

            // Pinterest
            'pinterest_enabled' => 0,
            'pinterest_catalog_id' => '',
            'pinterest_access_token' => '',

            // Custom Labels mapping
            'custom_label_0_name' => 'Temporada',
            'custom_label_1_name' => 'Género',
            'custom_label_2_name' => 'Descuento',
            'custom_label_3_name' => 'Colección',
            'custom_label_4_name' => '',
        ];
    }

    private function get_settings(){
        $opts = get_option( $this->option_key, [] );
        return wp_parse_args( is_array($opts) ? $opts : [], $this->get_default_settings() );
    }

    public function sanitize_settings($input){
        $clean = [];

        // General
        $clean['default_brand'] = isset($input['default_brand']) ? sanitize_text_field($input['default_brand']) : '';
        $clean['allow_brand_override'] = !empty($input['allow_brand_override']) ? 1 : 0;
        $clean['sync_schedule'] = isset($input['sync_schedule']) ? absint($input['sync_schedule']) : 6;
        $clean['sync_only_changes'] = !empty($input['sync_only_changes']) ? 1 : 0;
        $clean['sync_stock_realtime'] = !empty($input['sync_stock_realtime']) ? 1 : 0;

        // Google
        $clean['google_enabled'] = !empty($input['google_enabled']) ? 1 : 0;
        $clean['google_merchant_id'] = isset($input['google_merchant_id']) ? sanitize_text_field($input['google_merchant_id']) : '';
        $clean['google_service_account_json'] = isset($input['google_service_account_json']) ? wp_kses_post($input['google_service_account_json']) : '';
        $clean['google_data_source_id'] = isset($input['google_data_source_id']) ? sanitize_text_field($input['google_data_source_id']) : '';
        $clean['google_feed_label'] = isset($input['google_feed_label']) ? sanitize_text_field($input['google_feed_label']) : 'ES';

        // Facebook
        $clean['facebook_enabled'] = !empty($input['facebook_enabled']) ? 1 : 0;
        $clean['facebook_catalog_id'] = isset($input['facebook_catalog_id']) ? sanitize_text_field($input['facebook_catalog_id']) : '';
        $clean['facebook_access_token'] = isset($input['facebook_access_token']) ? sanitize_text_field($input['facebook_access_token']) : '';

        // Pinterest
        $clean['pinterest_enabled'] = !empty($input['pinterest_enabled']) ? 1 : 0;
        $clean['pinterest_catalog_id'] = isset($input['pinterest_catalog_id']) ? sanitize_text_field($input['pinterest_catalog_id']) : '';
        $clean['pinterest_access_token'] = isset($input['pinterest_access_token']) ? sanitize_text_field($input['pinterest_access_token']) : '';

        // Custom Labels
        for ($i = 0; $i <= 4; $i++) {
            $key = "custom_label_{$i}_name";
            $clean[$key] = isset($input[$key]) ? sanitize_text_field($input[$key]) : '';
        }

        return $clean;
    }

    /* ==== Section Renderers ==== */
    public function render_general_section(){
        echo '<p>'.esc_html__('Configuración general para la sincronización de catálogos.','mad-suite').'</p>';
    }

    public function render_google_section(){
        echo '<p>'.esc_html__('Configuración de Google Merchant Center.','mad-suite').'</p>';
        echo '<p><a href="https://merchants.google.com/" target="_blank">'.esc_html__('Ir a Google Merchant Center →','mad-suite').'</a></p>';
    }

    public function render_facebook_section(){
        echo '<p>'.esc_html__('Configuración de Facebook Catalog.','mad-suite').'</p>';
        echo '<p><strong>'.esc_html__('Importante:','mad-suite').'</strong> '.esc_html__('Para uso en producción, debes usar un System User Access Token (no expira). Los tokens de usuario normales solo duran 60 días.','mad-suite').'</p>';
        echo '<p>';
        echo '<a href="https://business.facebook.com/commerce" target="_blank">'.esc_html__('→ Ir a Facebook Commerce Manager','mad-suite').'</a> | ';
        echo '<a href="https://business.facebook.com/settings/system-users" target="_blank">'.esc_html__('→ Usuarios del Sistema','mad-suite').'</a>';
        echo '</p>';
    }

    public function render_pinterest_section(){
        echo '<p>'.esc_html__('Configuración de Pinterest Catalog.','mad-suite').'</p>';
        echo '<p><a href="https://www.pinterest.com/business/catalogs/" target="_blank">'.esc_html__('Ir a Pinterest Catalogs →','mad-suite').'</a></p>';
    }

    public function render_custom_labels_section(){
        echo '<p>'.esc_html__('Define los nombres de los Custom Labels que se usarán en Google Merchant Center, Facebook y Pinterest.','mad-suite').'</p>';
        echo '<p>'.esc_html__('Después configura tus etiquetas de WooCommerce (Productos > Etiquetas) para asignarlas a estos Custom Labels.','mad-suite').'</p>';
        echo '<p><strong>'.esc_html__('Nota:','mad-suite').'</strong> '.esc_html__('Múltiples etiquetas pueden usar el mismo Custom Label. Por ejemplo: "Verano", "Invierno", "Primavera" pueden asignarse a Custom Label 0 (Temporada).','mad-suite').'</p>';
    }

    /* ==== Field Renderers ==== */
    public function field_default_brand(){
        $v = $this->get_settings()['default_brand'];
        printf('<input type="text" class="regular-text" name="%s[default_brand]" value="%s" placeholder="%s" />',
            esc_attr($this->option_key),
            esc_attr($v),
            esc_attr__('Mi Marca', 'mad-suite')
        );
        echo '<p class="description">'.esc_html__('Marca que se usará por defecto para todos los productos.','mad-suite').'</p>';
    }

    public function field_allow_brand_override(){
        $v = (int) $this->get_settings()['allow_brand_override'];
        printf('<label><input type="checkbox" name="%s[allow_brand_override]" value="1" %s /> %s</label>',
            esc_attr($this->option_key),
            checked(1, $v, false),
            esc_html__('Permitir sobrescribir marca por producto','mad-suite')
        );
    }

    public function field_sync_schedule(){
        $v = $this->get_settings()['sync_schedule'];
        echo '<select name="'.esc_attr($this->option_key).'[sync_schedule]">';
        $options = [
            '1' => __('Cada hora', 'mad-suite'),
            '3' => __('Cada 3 horas', 'mad-suite'),
            '6' => __('Cada 6 horas', 'mad-suite'),
            '12' => __('Cada 12 horas', 'mad-suite'),
            '24' => __('Una vez al día', 'mad-suite'),
        ];
        foreach ($options as $hours => $label) {
            printf('<option value="%s" %s>%s</option>',
                esc_attr($hours),
                selected($v, $hours, false),
                esc_html($label)
            );
        }
        echo '</select>';
        echo '<p class="description">'.esc_html__('Frecuencia de sincronización automática.','mad-suite').'</p>';
    }

    public function field_google_enabled(){
        $v = (int) $this->get_settings()['google_enabled'];
        printf('<label><input type="checkbox" name="%s[google_enabled]" value="1" %s /> %s</label>',
            esc_attr($this->option_key),
            checked(1, $v, false),
            esc_html__('Sincronizar con Google Merchant Center','mad-suite')
        );
    }

    public function field_google_merchant_id(){
        $v = $this->get_settings()['google_merchant_id'];
        printf('<input type="text" class="regular-text" name="%s[google_merchant_id]" value="%s" placeholder="123456789" />',
            esc_attr($this->option_key),
            esc_attr($v)
        );
        echo '<p class="description">'.esc_html__('ID de tu cuenta de Merchant Center.','mad-suite').'</p>';
    }

    public function field_google_service_account_json(){
        $v = $this->get_settings()['google_service_account_json'];
        printf('<textarea class="large-text code" rows="10" name="%s[google_service_account_json]" placeholder=\'{"type":"service_account",...}\'>%s</textarea>',
            esc_attr($this->option_key),
            esc_textarea($v)
        );
        echo '<p class="description">'.esc_html__('Contenido completo del archivo JSON de la cuenta de servicio de Google Cloud.','mad-suite').'</p>';
    }

    public function field_google_data_source_id(){
        $v = $this->get_settings()['google_data_source_id'];
        printf('<input type="text" class="regular-text" name="%s[google_data_source_id]" value="%s" />',
            esc_attr($this->option_key),
            esc_attr($v)
        );
        echo '<p class="description">'.esc_html__('ID de la fuente de datos API de Merchant Center (se encuentra en Fuentes de datos > API merchant center). Ejemplo: 10588679125','mad-suite').'</p>';
    }

    public function field_google_feed_label(){
        $v = $this->get_settings()['google_feed_label'];
        printf('<input type="text" class="regular-text" name="%s[google_feed_label]" value="%s" />',
            esc_attr($this->option_key),
            esc_attr($v)
        );
        echo '<p class="description">'.esc_html__('Etiqueta de feed configurada en Merchant Center. Ejemplo: ES, EN, FR','mad-suite').'</p>';
    }

    public function field_facebook_enabled(){
        $v = (int) $this->get_settings()['facebook_enabled'];
        printf('<label><input type="checkbox" name="%s[facebook_enabled]" value="1" %s /> %s</label>',
            esc_attr($this->option_key),
            checked(1, $v, false),
            esc_html__('Sincronizar con Facebook Catalog','mad-suite')
        );
    }

    public function field_facebook_catalog_id(){
        $v = $this->get_settings()['facebook_catalog_id'];
        printf('<input type="text" class="regular-text" name="%s[facebook_catalog_id]" value="%s" placeholder="123456789012345" />',
            esc_attr($this->option_key),
            esc_attr($v)
        );
    }

    public function field_facebook_access_token(){
        $v = $this->get_settings()['facebook_access_token'];
        printf('<input type="text" class="large-text" name="%s[facebook_access_token]" value="%s" placeholder="EAAxxxxxx..." />',
            esc_attr($this->option_key),
            esc_attr($v)
        );
        echo '<p class="description">';
        echo esc_html__('System User Access Token de Meta Business (no expira, recomendado para producción).','mad-suite').'<br>';
        echo '<strong>'.esc_html__('Cómo obtenerlo:','mad-suite').'</strong><br>';
        echo '1. '.esc_html__('Ve a Meta Business Suite → Configuración → Usuarios → Usuarios del sistema','mad-suite').'<br>';
        echo '2. '.esc_html__('Crea un nuevo usuario del sistema con rol "Administrador"','mad-suite').'<br>';
        echo '3. '.esc_html__('Asigna permisos al catálogo (catalog_management, business_management)','mad-suite').'<br>';
        echo '4. '.esc_html__('Genera el token de acceso y cópialo aquí','mad-suite').'<br>';
        echo '<a href="https://business.facebook.com/settings/system-users" target="_blank">'.esc_html__('→ Ir a Usuarios del Sistema','mad-suite').'</a>';
        echo '</p>';
    }

    public function field_pinterest_enabled(){
        $v = (int) $this->get_settings()['pinterest_enabled'];
        printf('<label><input type="checkbox" name="%s[pinterest_enabled]" value="1" %s /> %s</label>',
            esc_attr($this->option_key),
            checked(1, $v, false),
            esc_html__('Sincronizar con Pinterest Catalog','mad-suite')
        );
    }

    public function field_pinterest_catalog_id(){
        $v = $this->get_settings()['pinterest_catalog_id'];
        printf('<input type="text" class="regular-text" name="%s[pinterest_catalog_id]" value="%s" placeholder="1234567890" />',
            esc_attr($this->option_key),
            esc_attr($v)
        );
    }

    public function field_pinterest_access_token(){
        $v = $this->get_settings()['pinterest_access_token'];
        printf('<input type="text" class="large-text" name="%s[pinterest_access_token]" value="%s" placeholder="pina_xxx..." />',
            esc_attr($this->option_key),
            esc_attr($v)
        );
        echo '<p class="description">'.esc_html__('Token de acceso de Pinterest para usar con la API.','mad-suite').'</p>';
    }

    public function field_custom_label_name($args){
        $label_index = $args['label_index'];
        $key = "custom_label_{$label_index}_name";
        $v = $this->get_settings()[$key];

        printf('<input type="text" class="regular-text" name="%s[%s]" value="%s" placeholder="%s" />',
            esc_attr($this->option_key),
            esc_attr($key),
            esc_attr($v),
            esc_attr__('Ejemplo: Temporada, Género, Descuento...','mad-suite')
        );

        $descriptions = [
            0 => __('Ejemplo: "Temporada" - Luego asigna etiquetas como "Verano", "Invierno" a este Custom Label.','mad-suite'),
            1 => __('Ejemplo: "Género" - Luego asigna etiquetas como "Hombre", "Mujer", "Unisex" a este Custom Label.','mad-suite'),
            2 => __('Ejemplo: "Descuento" - Luego asigna etiquetas como "Rebajas", "Outlet" a este Custom Label.','mad-suite'),
            3 => __('Ejemplo: "Colección" - Luego asigna etiquetas como "Primavera 2024", "Nueva Colección" a este Custom Label.','mad-suite'),
            4 => __('Campo adicional opcional para cualquier otra categorización.','mad-suite'),
        ];

        if (isset($descriptions[$label_index])) {
            echo '<p class="description">'.$descriptions[$label_index].'</p>';
        }
    }

    /* ==== Product Meta Box ==== */
    public function add_product_meta_box(){
        add_meta_box(
            'mcs_product_sync',
            __('Catalog Sync', 'mad-suite'),
            [$this, 'render_product_meta_box'],
            'product',
            'side',
            'default'
        );
    }

    public function render_product_meta_box($post){
        wp_nonce_field('mcs_product_meta', 'mcs_product_meta_nonce');

        $sync_enabled = get_post_meta($post->ID, '_mcs_sync_enabled', true);
        $sync_enabled = $sync_enabled === '' ? '1' : $sync_enabled; // Default enabled

        $custom_brand = get_post_meta($post->ID, '_mcs_custom_brand', true);
        $gtin = get_post_meta($post->ID, '_mcs_gtin', true);
        $mpn = get_post_meta($post->ID, '_mcs_mpn', true);

        $settings = $this->get_settings();
        ?>
        <div class="mcs-product-meta">
            <p>
                <label>
                    <input type="checkbox" name="_mcs_sync_enabled" value="1" <?php checked($sync_enabled, '1'); ?> />
                    <strong><?php esc_html_e('Sincronizar con catálogos', 'mad-suite'); ?></strong>
                </label>
            </p>

            <div class="mcs-sync-destinations" style="margin-left: 20px;">
                <?php if ($settings['google_enabled']): ?>
                <label style="display: block;">
                    <input type="checkbox" disabled checked />
                    Google Merchant Center
                </label>
                <?php endif; ?>

                <?php if ($settings['facebook_enabled']): ?>
                <label style="display: block;">
                    <input type="checkbox" disabled checked />
                    Facebook Catalog
                </label>
                <?php endif; ?>

                <?php if ($settings['pinterest_enabled']): ?>
                <label style="display: block;">
                    <input type="checkbox" disabled checked />
                    Pinterest Catalog
                </label>
                <?php endif; ?>
            </div>

            <hr style="margin: 15px 0;">

            <?php if ($settings['allow_brand_override']): ?>
            <p>
                <label>
                    <strong><?php esc_html_e('Marca:', 'mad-suite'); ?></strong><br>
                    <input type="text" name="_mcs_custom_brand" value="<?php echo esc_attr($custom_brand); ?>"
                           placeholder="<?php echo esc_attr($settings['default_brand'] ?: __('Marca', 'mad-suite')); ?>"
                           style="width: 100%;" />
                </label>
                <span class="description"><?php esc_html_e('Dejar vacío para usar marca predeterminada', 'mad-suite'); ?></span>
            </p>
            <?php endif; ?>

            <p>
                <label>
                    <strong><?php esc_html_e('GTIN/EAN:', 'mad-suite'); ?></strong><br>
                    <input type="text" name="_mcs_gtin" value="<?php echo esc_attr($gtin); ?>"
                           placeholder="<?php esc_attr_e('Código de barras', 'mad-suite'); ?>"
                           style="width: 100%;" />
                </label>
            </p>

            <p>
                <label>
                    <strong><?php esc_html_e('MPN:', 'mad-suite'); ?></strong><br>
                    <input type="text" name="_mcs_mpn" value="<?php echo esc_attr($mpn); ?>"
                           placeholder="<?php esc_attr_e('Número de parte', 'mad-suite'); ?>"
                           style="width: 100%;" />
                </label>
            </p>

            <?php
            // Show last sync status
            $last_sync = get_post_meta($post->ID, '_mcs_last_sync', true);
            if ($last_sync):
            ?>
            <hr style="margin: 15px 0;">
            <p class="mcs-sync-status">
                <strong><?php esc_html_e('Última sincronización:', 'mad-suite'); ?></strong><br>
                <small><?php echo esc_html(human_time_diff($last_sync, current_time('timestamp')) . ' ' . __('atrás', 'mad-suite')); ?></small>
            </p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function save_product_meta($post_id){
        if ( ! isset($_POST['mcs_product_meta_nonce']) ||
             ! wp_verify_nonce($_POST['mcs_product_meta_nonce'], 'mcs_product_meta') ) {
            return;
        }

        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( ! current_user_can('edit_product', $post_id) ) return;

        // Sync enabled
        $sync_enabled = isset($_POST['_mcs_sync_enabled']) ? '1' : '0';
        update_post_meta($post_id, '_mcs_sync_enabled', $sync_enabled);

        // Custom brand
        if (isset($_POST['_mcs_custom_brand'])) {
            update_post_meta($post_id, '_mcs_custom_brand', sanitize_text_field($_POST['_mcs_custom_brand']));
        }

        // GTIN
        if (isset($_POST['_mcs_gtin'])) {
            update_post_meta($post_id, '_mcs_gtin', sanitize_text_field($_POST['_mcs_gtin']));
        }

        // MPN
        if (isset($_POST['_mcs_mpn'])) {
            update_post_meta($post_id, '_mcs_mpn', sanitize_text_field($_POST['_mcs_mpn']));
        }

        // Queue for sync
        if ($sync_enabled === '1') {
            $this->queue_product_sync($post_id);
        }
    }

    /* ==== Category Fields ==== */
    public function add_category_fields($taxonomy){
        ?>
        <div class="form-field">
            <label><?php esc_html_e('Google Product Category', 'mad-suite'); ?></label>
            <input type="text" name="mcs_google_category" id="mcs_google_category" value="" class="mcs-category-search" />
            <input type="hidden" name="mcs_google_category_id" id="mcs_google_category_id" value="" />
            <p class="description"><?php esc_html_e('Buscar y seleccionar categoría de Google Merchant Center', 'mad-suite'); ?></p>
        </div>
        <?php
    }

    public function edit_category_fields($term){
        $google_category = get_term_meta($term->term_id, '_mcs_google_category', true);
        $google_category_id = get_term_meta($term->term_id, '_mcs_google_category_id', true);
        ?>
        <tr class="form-field">
            <th scope="row">
                <label><?php esc_html_e('Google Product Category', 'mad-suite'); ?></label>
            </th>
            <td>
                <input type="text" name="mcs_google_category" id="mcs_google_category"
                       value="<?php echo esc_attr($google_category); ?>"
                       class="mcs-category-search regular-text" />
                <input type="hidden" name="mcs_google_category_id" id="mcs_google_category_id"
                       value="<?php echo esc_attr($google_category_id); ?>" />
                <p class="description"><?php esc_html_e('Buscar y seleccionar categoría de Google Merchant Center', 'mad-suite'); ?></p>
            </td>
        </tr>
        <?php
    }

    public function save_category_fields($term_id){
        if (isset($_POST['mcs_google_category'])) {
            update_term_meta($term_id, '_mcs_google_category', sanitize_text_field($_POST['mcs_google_category']));
        }
        if (isset($_POST['mcs_google_category_id'])) {
            update_term_meta($term_id, '_mcs_google_category_id', sanitize_text_field($_POST['mcs_google_category_id']));
        }
    }

    /* ==== Tag Fields ==== */
    public function add_tag_fields($taxonomy){
        $settings = $this->get_settings();
        ?>
        <div class="form-field">
            <label>
                <input type="checkbox" name="mcs_tag_sync_enabled" value="1" />
                <?php esc_html_e('Sincronizar con catálogos', 'mad-suite'); ?>
            </label>
            <p class="description"><?php esc_html_e('Incluir esta etiqueta en la sincronización de productos', 'mad-suite'); ?></p>
        </div>

        <div class="form-field">
            <label><?php esc_html_e('Asignar a Custom Label', 'mad-suite'); ?></label>
            <select name="mcs_tag_custom_label">
                <option value=""><?php esc_html_e('-- No asignar --', 'mad-suite'); ?></option>
                <?php for ($i = 0; $i <= 4; $i++):
                    $label_name = $settings["custom_label_{$i}_name"];
                    if (empty($label_name)) continue;
                ?>
                <option value="<?php echo $i; ?>">
                    <?php echo sprintf(__('Custom Label %d (%s)', 'mad-suite'), $i, esc_html($label_name)); ?>
                </option>
                <?php endfor; ?>
            </select>
        </div>
        <?php
    }

    public function edit_tag_fields($term){
        $sync_enabled = get_term_meta($term->term_id, '_mcs_sync_enabled', true);
        $custom_label = get_term_meta($term->term_id, '_mcs_custom_label', true);
        $settings = $this->get_settings();
        ?>
        <tr class="form-field">
            <th scope="row">
                <label><?php esc_html_e('Sincronización', 'mad-suite'); ?></label>
            </th>
            <td>
                <label>
                    <input type="checkbox" name="mcs_tag_sync_enabled" value="1" <?php checked($sync_enabled, '1'); ?> />
                    <?php esc_html_e('Sincronizar con catálogos', 'mad-suite'); ?>
                </label>
            </td>
        </tr>

        <tr class="form-field">
            <th scope="row">
                <label><?php esc_html_e('Custom Label', 'mad-suite'); ?></label>
            </th>
            <td>
                <select name="mcs_tag_custom_label">
                    <option value=""><?php esc_html_e('-- No asignar --', 'mad-suite'); ?></option>
                    <?php for ($i = 0; $i <= 4; $i++):
                        $label_name = $settings["custom_label_{$i}_name"];
                        if (empty($label_name)) continue;
                    ?>
                    <option value="<?php echo $i; ?>" <?php selected($custom_label, $i); ?>>
                        <?php echo sprintf(__('Custom Label %d (%s)', 'mad-suite'), $i, esc_html($label_name)); ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </td>
        </tr>
        <?php
    }

    public function save_tag_fields($term_id){
        // Get previous values to detect changes
        $old_sync_enabled = get_term_meta($term_id, '_mcs_sync_enabled', true);
        $old_custom_label = get_term_meta($term_id, '_mcs_custom_label', true);

        // Save new values
        $sync_enabled = isset($_POST['mcs_tag_sync_enabled']) ? '1' : '0';
        update_term_meta($term_id, '_mcs_sync_enabled', $sync_enabled);

        $new_custom_label = '';
        if (isset($_POST['mcs_tag_custom_label'])) {
            $new_custom_label = sanitize_text_field($_POST['mcs_tag_custom_label']);
            update_term_meta($term_id, '_mcs_custom_label', $new_custom_label);
        }

        // Check if relevant changes were made (sync enabled or custom label changed)
        $sync_changed = ($old_sync_enabled !== $sync_enabled);
        $label_changed = ($old_custom_label !== $new_custom_label);

        // If sync is enabled and something changed, trigger re-sync of all products with this tag
        if ($sync_enabled === '1' && ($sync_changed || $label_changed)) {
            $this->queue_products_with_tag($term_id);
        }
    }

    /**
     * Queue all products with a specific tag for sync
     *
     * @param int $term_id Tag term ID
     */
    private function queue_products_with_tag($term_id){
        // Get all products with this tag
        $products = wc_get_products([
            'limit' => -1,
            'return' => 'ids',
            'tag' => [$term_id],
            'status' => 'publish',
        ]);

        if (empty($products)) {
            return;
        }

        // Queue each product for sync
        foreach ($products as $product_id) {
            $this->queue_product_sync($product_id);
        }

        // Log the action
        $term = get_term($term_id, 'product_tag');
        if ($term && !is_wp_error($term)) {
            $logger = new \MAD_Suite\MultiCatalogSync\Core\Logger();
            $logger->info(sprintf(
                'Auto-sync triggered for tag "%s": %d products queued for sync',
                $term->name,
                count($products)
            ));
        }

        // Schedule immediate background processing of the queue
        if (!wp_next_scheduled('mcs_process_queue_hook')) {
            wp_schedule_single_event(time(), 'mcs_process_queue_hook');
        }

        // Show admin notice
        add_action('admin_notices', function() use ($term, $products) {
            if ($term && !is_wp_error($term)) {
                printf(
                    '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
                    sprintf(
                        esc_html__('Etiqueta "%s" actualizada. %d productos han sido encolados para sincronización automática.', 'mad-suite'),
                        esc_html($term->name),
                        count($products)
                    )
                );
            }
        });
    }

    /* ==== Sync Queue Management ==== */
    public function queue_product_sync($product_id){
        // Add to sync queue
        $queue = get_option('mcs_sync_queue', []);
        if (!in_array($product_id, $queue)) {
            $queue[] = $product_id;
            update_option('mcs_sync_queue', $queue);
        }
    }

    public function queue_stock_update($order){
        $settings = $this->get_settings();
        if (!$settings['sync_stock_realtime']) return;

        // Queue all products in this order for stock update
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $this->queue_product_sync($product_id);
        }
    }

    public function run_scheduled_sync(){
        // Load ProductSyncManager
        require_once __DIR__ . '/includes/Core/ProductSyncManager.php';

        $settings = $this->get_settings();
        $sync_manager = new \MAD_Suite\MultiCatalogSync\Core\ProductSyncManager($settings);

        // Sync all enabled products
        $results = $sync_manager->sync_all();

        // Log results
        $logger = new \MAD_Suite\MultiCatalogSync\Core\Logger();
        foreach ($results as $destination => $result) {
            $logger->info(sprintf(
                'Scheduled sync to %s: %d synced, %d failed',
                $destination,
                isset($result['synced']) ? $result['synced'] : 0,
                isset($result['failed']) ? $result['failed'] : 0
            ));
        }
    }

    /**
     * Process the sync queue (triggered by tag changes or product updates)
     */
    public function process_sync_queue(){
        // Load ProductSyncManager
        require_once __DIR__ . '/includes/Core/ProductSyncManager.php';

        $settings = $this->get_settings();
        $sync_manager = new \MAD_Suite\MultiCatalogSync\Core\ProductSyncManager($settings);

        // Process queue
        $result = $sync_manager->process_queue();

        // Log results
        $logger = new \MAD_Suite\MultiCatalogSync\Core\Logger();
        if ($result['success'] && isset($result['processed']) && $result['processed'] > 0) {
            $logger->info(sprintf(
                'Queue processed: %d products synced',
                $result['processed']
            ));
        }
    }

    /* ==== AJAX Handlers ==== */
    public function ajax_search_google_category(){
        check_ajax_referer('mcs_ajax', 'nonce');

        $term = isset($_POST['term']) ? sanitize_text_field($_POST['term']) : '';

        if (empty($term)) {
            wp_send_json_success([]);
            return;
        }

        // Load CategoryMapper
        require_once __DIR__ . '/includes/Core/CategoryMapper.php';
        $mapper = new \MAD_Suite\MultiCatalogSync\Core\CategoryMapper();

        $results = $mapper->search_taxonomy($term, 20);

        wp_send_json_success($results);
    }

    public function ajax_manual_sync(){
        check_ajax_referer('mcs_ajax', 'nonce');

        $destination = isset($_POST['destination']) ? sanitize_text_field($_POST['destination']) : 'all';

        // Load ProductSyncManager
        require_once __DIR__ . '/includes/Core/ProductSyncManager.php';

        $settings = $this->get_settings();
        $sync_manager = new \MAD_Suite\MultiCatalogSync\Core\ProductSyncManager($settings);

        // Get syncable products
        $product_ids = $this->get_syncable_product_ids();

        if (empty($product_ids)) {
            wp_send_json_error([
                'message' => __('No hay productos para sincronizar', 'mad-suite'),
            ]);
            return;
        }

        // Sync products
        if ($destination === 'all') {
            $results = $sync_manager->sync_batch($product_ids);
        } else {
            $results = $sync_manager->sync_batch($product_ids, $destination);
        }

        // Calculate totals
        $total_synced = 0;
        $total_failed = 0;

        foreach ($results as $dest_name => $result) {
            $total_synced += isset($result['synced']) ? $result['synced'] : 0;
            $total_failed += isset($result['failed']) ? $result['failed'] : 0;
        }

        if ($total_failed > 0) {
            wp_send_json_error([
                'message' => sprintf(
                    __('%d productos sincronizados, %d fallaron', 'mad-suite'),
                    $total_synced,
                    $total_failed
                ),
                'results' => $results,
            ]);
        } else {
            wp_send_json_success([
                'message' => sprintf(__('%d productos sincronizados exitosamente', 'mad-suite'), $total_synced),
                'results' => $results,
            ]);
        }
    }

    public function ajax_sync_specific_products(){
        check_ajax_referer('mcs_ajax', 'nonce');

        $product_ids_string = isset($_POST['product_ids']) ? sanitize_text_field($_POST['product_ids']) : '';
        $destination = isset($_POST['destination']) ? sanitize_text_field($_POST['destination']) : 'all';

        // Parse product IDs (comma-separated)
        $product_ids = array_filter(array_map('intval', explode(',', $product_ids_string)));

        if (empty($product_ids)) {
            wp_send_json_error([
                'message' => __('No se proporcionaron IDs de productos válidos', 'mad-suite'),
            ]);
            return;
        }

        // Load ProductSyncManager
        require_once __DIR__ . '/includes/Core/ProductSyncManager.php';

        $settings = $this->get_settings();
        $sync_manager = new \MAD_Suite\MultiCatalogSync\Core\ProductSyncManager($settings);

        // Sync products
        if ($destination === 'all') {
            $results = $sync_manager->sync_batch($product_ids);
        } else {
            $results = $sync_manager->sync_batch($product_ids, $destination);
        }

        // Calculate totals
        $total_synced = 0;
        $total_failed = 0;

        foreach ($results as $dest_name => $result) {
            $total_synced += isset($result['synced']) ? $result['synced'] : 0;
            $total_failed += isset($result['failed']) ? $result['failed'] : 0;
        }

        if ($total_failed > 0) {
            wp_send_json_error([
                'message' => sprintf(
                    __('%d productos sincronizados, %d fallaron', 'mad-suite'),
                    $total_synced,
                    $total_failed
                ),
                'results' => $results,
            ]);
        } else {
            wp_send_json_success([
                'message' => sprintf(__('%d productos sincronizados exitosamente', 'mad-suite'), $total_synced),
                'results' => $results,
            ]);
        }
    }

    public function ajax_get_sync_status(){
        check_ajax_referer('mcs_ajax', 'nonce');

        // Load ProductSyncManager
        require_once __DIR__ . '/includes/Core/ProductSyncManager.php';

        $settings = $this->get_settings();
        $sync_manager = new \MAD_Suite\MultiCatalogSync\Core\ProductSyncManager($settings);

        $status = $sync_manager->get_sync_status();

        wp_send_json_success($status);
    }

    /* ==== Helper Methods for AJAX ==== */

    /**
     * Get syncable product IDs
     */
    private function get_syncable_product_ids(){
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_mcs_sync_enabled',
                    'value' => '1',
                ],
                [
                    'key' => '_mcs_sync_enabled',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ];

        $query = new \WP_Query($args);
        return $query->posts;
    }

    /* ==== Dashboard Helper Methods ==== */

    /**
     * Count total variations in the store
     */
    private function count_variations(){
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_status = 'publish'"
        );
    }

    /**
     * Get count of synced products
     */
    private function get_synced_count(){
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
            WHERE meta_key = '_mcs_sync_enabled' AND meta_value = '1'"
        );
    }

    /**
     * Get count of excluded products
     */
    private function get_excluded_count(){
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
            WHERE meta_key = '_mcs_sync_enabled' AND meta_value = '0'"
        );
    }

    /**
     * Get count of products with errors
     */
    private function get_error_count(){
        $errors = get_option('mcs_sync_errors', []);
        return count($errors);
    }

    /**
     * Get recent sync errors
     */
    private function get_recent_errors(){
        return get_option('mcs_sync_errors', []);
    }

    /**
     * Get next scheduled sync timestamp
     */
    private function get_next_scheduled_sync(){
        return wp_next_scheduled('madsuite_catalog_sync_cron');
    }

    /**
     * Check if Google is connected
     */
    private function is_google_connected(){
        $settings = $this->get_settings();
        return !empty($settings['google_merchant_id']) && !empty($settings['google_service_account_json']);
    }

    /**
     * Check if Facebook is connected
     */
    private function is_facebook_connected(){
        $settings = $this->get_settings();
        return !empty($settings['facebook_catalog_id']) && !empty($settings['facebook_access_token']);
    }

    /**
     * Check if Pinterest is connected
     */
    private function is_pinterest_connected(){
        $settings = $this->get_settings();
        return !empty($settings['pinterest_catalog_id']) && !empty($settings['pinterest_access_token']);
    }

    /**
     * Get item count for a specific destination
     */
    private function get_destination_item_count($destination){
        $counts = get_option('mcs_destination_counts', []);
        return isset($counts[$destination]['items']) ? (int) $counts[$destination]['items'] : 0;
    }

    /**
     * Get error count for a specific destination
     */
    private function get_destination_error_count($destination){
        $counts = get_option('mcs_destination_counts', []);
        return isset($counts[$destination]['errors']) ? (int) $counts[$destination]['errors'] : 0;
    }

    /**
     * Get log file name for WooCommerce logs
     */
    private function get_log_file_name(){
        return 'multi-catalog-sync-' . date('Y-m-d') . '-' . wp_hash('multi-catalog-sync');
    }
};
