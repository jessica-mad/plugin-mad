<?php
namespace MAD_Suite\Modules\RoleCreator;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Integración con Mailchimp para sincronización de roles
 */
class MailchimpIntegration
{
    private static $instance;
    private $api_key;
    private $audience_id;
    private $server_prefix;

    const SETTINGS_KEY = 'madsuite_role_creator_mailchimp';

    public static function instance()
    {
        if (! self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __construct()
    {
        $this->load_settings();
    }

    /**
     * Carga configuración de Mailchimp
     */
    private function load_settings()
    {
        $settings = get_option(self::SETTINGS_KEY, []);

        $this->api_key     = isset($settings['api_key']) ? $settings['api_key'] : '';
        $this->audience_id = isset($settings['audience_id']) ? $settings['audience_id'] : '';

        // Extraer server prefix del API key (ej: us1, us2, etc.)
        if (! empty($this->api_key) && strpos($this->api_key, '-') !== false) {
            $parts = explode('-', $this->api_key);
            $this->server_prefix = end($parts);
        }
    }

    /**
     * Verifica si Mailchimp está configurado
     */
    public function is_configured()
    {
        return ! empty($this->api_key) && ! empty($this->audience_id) && ! empty($this->server_prefix);
    }

    /**
     * Sincroniza un usuario con Mailchimp
     *
     * @param int   $user_id
     * @param array $roles Roles actuales del usuario
     * @return bool|WP_Error
     */
    public function sync_user($user_id, $roles = null)
    {
        if (! $this->is_configured()) {
            return new \WP_Error('not_configured', __('Mailchimp no está configurado.', 'mad-suite'));
        }

        $user = get_user_by('id', $user_id);
        if (! $user) {
            return new \WP_Error('invalid_user', __('Usuario no encontrado.', 'mad-suite'));
        }

        // Usar roles proporcionados o obtener roles actuales
        if ($roles === null) {
            $roles = $user->roles;
        }

        $email = $user->user_email;
        $subscriber_hash = md5(strtolower($email));

        // Obtener o crear contacto en Mailchimp
        $member = $this->get_or_create_member($email, $user);

        if (is_wp_error($member)) {
            return $member;
        }

        // Actualizar tags con roles
        $result = $this->update_member_tags($subscriber_hash, $roles);

        return $result;
    }

    /**
     * Obtiene o crea un miembro en Mailchimp
     *
     * @param string $email
     * @param WP_User $user
     * @return array|WP_Error
     */
    private function get_or_create_member($email, $user)
    {
        $subscriber_hash = md5(strtolower($email));
        $endpoint = "https://{$this->server_prefix}.api.mailchimp.com/3.0/lists/{$this->audience_id}/members/{$subscriber_hash}";

        // Intentar obtener miembro existente
        $response = wp_remote_get($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 15,
        ]);

        $status_code = wp_remote_retrieve_response_code($response);

        // Si existe, retornar
        if ($status_code === 200) {
            return json_decode(wp_remote_retrieve_body($response), true);
        }

        // Si no existe (404), crear sin suscripción
        if ($status_code === 404) {
            return $this->create_member($email, $user);
        }

        // Otro error
        return new \WP_Error(
            'mailchimp_error',
            sprintf(__('Error al obtener miembro de Mailchimp: %s', 'mad-suite'), wp_remote_retrieve_body($response))
        );
    }

    /**
     * Crea un nuevo miembro en Mailchimp (sin suscripción)
     *
     * @param string $email
     * @param WP_User $user
     * @return array|WP_Error
     */
    private function create_member($email, $user)
    {
        $endpoint = "https://{$this->server_prefix}.api.mailchimp.com/3.0/lists/{$this->audience_id}/members";

        $data = [
            'email_address' => $email,
            'status'        => 'transactional', // Sin suscripción pero disponible para tags
            'merge_fields'  => [
                'FNAME' => $user->first_name ?: '',
                'LNAME' => $user->last_name ?: '',
            ],
        ];

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($data),
            'timeout' => 15,
        ]);

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 200 || $status_code === 201) {
            return json_decode(wp_remote_retrieve_body($response), true);
        }

        return new \WP_Error(
            'mailchimp_create_error',
            sprintf(__('Error al crear miembro en Mailchimp: %s', 'mad-suite'), wp_remote_retrieve_body($response))
        );
    }

    /**
     * Actualiza los tags de un miembro con sus roles
     *
     * @param string $subscriber_hash
     * @param array  $roles
     * @return bool|WP_Error
     */
    private function update_member_tags($subscriber_hash, $roles)
    {
        $endpoint = "https://{$this->server_prefix}.api.mailchimp.com/3.0/lists/{$this->audience_id}/members/{$subscriber_hash}/tags";

        // Obtener todos los roles disponibles para remover tags antiguos
        $all_wordpress_roles = array_keys(RoleManager::instance()->get_editable_roles());

        // Tags a agregar (roles actuales con prefijo)
        $tags_to_add = array_map(function($role) {
            return [
                'name'   => 'role_' . $role,
                'status' => 'active',
            ];
        }, $roles);

        // Tags a remover (todos los otros roles con prefijo)
        $roles_to_remove = array_diff($all_wordpress_roles, $roles);
        $tags_to_remove = array_map(function($role) {
            return [
                'name'   => 'role_' . $role,
                'status' => 'inactive',
            ];
        }, $roles_to_remove);

        // Combinar ambas operaciones
        $tags = array_merge($tags_to_add, $tags_to_remove);

        $data = ['tags' => $tags];

        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode($data),
            'timeout' => 15,
        ]);

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 204) {
            return true;
        }

        return new \WP_Error(
            'mailchimp_tags_error',
            sprintf(__('Error al actualizar tags en Mailchimp: %s', 'mad-suite'), wp_remote_retrieve_body($response))
        );
    }

    /**
     * Sincroniza múltiples usuarios de forma masiva
     *
     * @param array $user_ids
     * @return array ['success' => int, 'errors' => array]
     */
    public function sync_users_bulk($user_ids)
    {
        $success = 0;
        $errors  = [];

        foreach ($user_ids as $user_id) {
            $result = $this->sync_user($user_id);

            if (is_wp_error($result)) {
                $errors[] = sprintf(__('Usuario %d: %s', 'mad-suite'), $user_id, $result->get_error_message());
            } else {
                $success++;
            }
        }

        return [
            'success' => $success,
            'errors'  => $errors,
        ];
    }

    /**
     * Prueba la conexión con Mailchimp
     *
     * @return true|WP_Error
     */
    public function test_connection()
    {
        if (! $this->is_configured()) {
            return new \WP_Error('not_configured', __('Mailchimp no está configurado.', 'mad-suite'));
        }

        $endpoint = "https://{$this->server_prefix}.api.mailchimp.com/3.0/ping";

        $response = wp_remote_get($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
            ],
            'timeout' => 10,
        ]);

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code === 200) {
            return true;
        }

        return new \WP_Error(
            'connection_failed',
            sprintf(__('Error de conexión: %s', 'mad-suite'), wp_remote_retrieve_body($response))
        );
    }

    /**
     * Guarda la configuración de Mailchimp
     *
     * @param array $settings
     * @return bool
     */
    public function save_settings($settings)
    {
        update_option(self::SETTINGS_KEY, $settings);
        $this->load_settings();

        return true;
    }

    /**
     * Obtiene la configuración actual
     *
     * @return array
     */
    public function get_settings()
    {
        return get_option(self::SETTINGS_KEY, [
            'api_key'     => '',
            'audience_id' => '',
            'auto_sync'   => true,
        ]);
    }
}
