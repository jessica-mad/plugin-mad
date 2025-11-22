<?php
/**
 * Módulo: Gemini Assistant
 *
 * Integración con Google Gemini AI para chat interactivo con soporte multimodal.
 * Permite conversaciones con IA, adjuntar imágenes y PDFs, y gestionar historial.
 *
 * @package MAD_Suite
 * @subpackage Gemini_Assistant
 */

if (!defined('ABSPATH')) exit;

return new class($core ?? null) implements MAD_Suite_Module {
    private $core;
    private $slug = 'gemini-assistant';
    private $gemini_core;

    public function __construct($core) {
        $this->core = $core;

        // Cargar clases
        require_once __DIR__ . '/classes/Activator.php';
        require_once __DIR__ . '/classes/Core.php';

        $this->gemini_core = new MAD_Gemini_Core();

        // Hook de activación (cuando se habilita el módulo)
        add_action('admin_init', [$this, 'check_activation']);
    }

    public function slug() {
        return $this->slug;
    }

    public function title() {
        return __('Gemini Assistant', 'mad-suite');
    }

    public function menu_label() {
        return __('Gemini AI', 'mad-suite');
    }

    public function menu_slug() {
        return MAD_Suite_Core::MENU_SLUG_ROOT . '-' . $this->slug;
    }

    public function description() {
        return __('Asistente de IA con Google Gemini. Chat interactivo con soporte para imágenes y PDFs.', 'mad-suite');
    }

    public function required_plugins() {
        return [];
    }

    /**
     * Verificar activación y crear tablas si es necesario
     */
    public function check_activation() {
        $db_version = get_option('mad_gemini_assistant_db_version');

        if (!$db_version) {
            MAD_Gemini_Activator::activate();
        }
    }

    /**
     * Inicialización del módulo
     */
    public function init() {
        $this->gemini_core->init();

        // Enqueue de assets
        add_action('admin_enqueue_scripts', [$this->gemini_core, 'enqueue_admin_assets']);
    }

    /**
     * Inicialización del admin
     */
    public function admin_init() {
        $this->gemini_core->admin_init();
    }

    /**
     * Renderizar página de configuración
     */
    public function render_settings_page() {
        if (!current_user_can(MAD_Suite_Core::CAPABILITY)) {
            wp_die(__('No tienes permisos suficientes.', 'mad-suite'));
        }

        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'chat';

        $tabs = [
            'chat' => __('Chat', 'mad-suite'),
            'settings' => __('Configuración', 'mad-suite'),
        ];

        ?>
        <div class="wrap mad-gemini-wrapper">
            <h1><?php echo esc_html($this->title()); ?></h1>

            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php _e('Configuración guardada correctamente.', 'mad-suite'); ?></p>
                </div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $tab_key => $tab_label): ?>
                    <a href="<?php echo esc_url(add_query_arg('tab', $tab_key, admin_url('admin.php?page=' . $this->menu_slug()))); ?>"
                       class="nav-tab <?php echo $tab === $tab_key ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($tab_label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="tab-content">
                <?php
                switch ($tab) {
                    case 'settings':
                        $this->render_view('settings-page', [
                            'settings' => $this->gemini_core->get_settings(),
                            'module' => $this,
                        ]);
                        break;

                    case 'chat':
                    default:
                        $this->render_view('chat-interface', [
                            'settings' => $this->gemini_core->get_settings(),
                            'conversation' => $this->gemini_core->get_conversation_instance(),
                            'user_id' => get_current_user_id(),
                        ]);
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Renderizar una vista
     */
    private function render_view($view_name, $data = []) {
        $view_file = __DIR__ . '/views/' . $view_name . '.php';
        if (!file_exists($view_file)) {
            wp_die(sprintf(__('La vista %s no existe.', 'mad-suite'), $view_name));
        }
        extract($data, EXTR_SKIP);
        include $view_file;
    }
};
