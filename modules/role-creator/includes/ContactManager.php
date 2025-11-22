<?php
namespace MAD_Suite\Modules\RoleCreator;

if (! defined('ABSPATH')) {
    exit;
}

class ContactManager
{
    private static $instance;

    public static function instance()
    {
        if (! self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Crea o actualiza contactos (usuarios) desde filas importadas.
     *
     * @param array<int, array> $rows
     * @param string            $role
     * @param string            $mode Modo: 'sync' (crear+actualizar), 'create_only' (solo crear), 'update_only' (solo actualizar)
     * @return array{created:int,updated:int,skipped:int,errors:array}
     */
    public function sync_contacts(array $rows, $role, $mode = 'sync')
    {
        $results = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors'  => [],
        ];

        foreach ($rows as $row) {
            $email = $row['email'];
            $line  = isset($row['line']) ? (int) $row['line'] : 0;

            $existing_user = get_user_by('email', $email);

            if ($existing_user) {
                // Usuario existente - verificar si debemos actualizarlo según el modo
                if ($mode === 'create_only') {
                    // Modo "solo crear" - saltar usuarios existentes
                    $results['skipped']++;
                    continue;
                }

                // Modo "sync" o "update_only" - actualizar usuario
                $update = [
                    'ID'         => $existing_user->ID,
                    'user_email' => $email,
                ];

                $this->maybe_assign_basic_fields($update, $row);

                if (! empty($row['user_pass'])) {
                    $update['user_pass'] = $row['user_pass'];
                }

                $update['role'] = $role;

                $user_id = wp_update_user($update);

                if (is_wp_error($user_id)) {
                    $results['errors'][] = sprintf(__('Fila %1$d: %2$s', 'mad-suite'), $line, $user_id->get_error_message());
                    continue;
                }

                $results['updated']++;
                $this->maybe_update_meta($existing_user->ID, $row);
            } else {
                // Usuario NO existente - verificar si debemos crearlo según el modo
                if ($mode === 'update_only') {
                    // Modo "solo actualizar" - saltar usuarios nuevos
                    $results['skipped']++;
                    continue;
                }

                // Modo "sync" o "create_only" - crear usuario nuevo
                $user_login = $row['user_login'];
                if (! $user_login) {
                    $user_login = sanitize_user(current(explode('@', $email)) ?: $email, true);
                }

                if (! $user_login) {
                    $results['errors'][] = sprintf(__('Fila %1$d: no se pudo determinar el usuario para %2$s.', 'mad-suite'), $line, $email);
                    continue;
                }

                $password = $row['user_pass'];
                if (! $password) {
                    $password = wp_generate_password();
                }

                $create = [
                    'user_login' => $user_login,
                    'user_email' => $email,
                    'user_pass'  => $password,
                    'role'       => $role,
                ];

                $this->maybe_assign_basic_fields($create, $row);

                $user_id = wp_insert_user($create);

                if (is_wp_error($user_id)) {
                    $results['errors'][] = sprintf(__('Fila %1$d: %2$s', 'mad-suite'), $line, $user_id->get_error_message());
                    continue;
                }

                $results['created']++;
                $this->maybe_update_meta($user_id, $row);
            }
        }

        return $results;
    }

    private function maybe_assign_basic_fields(array &$payload, array $row)
    {
        if (! empty($row['first_name'])) {
            $payload['first_name'] = $row['first_name'];
        }

        if (! empty($row['last_name'])) {
            $payload['last_name'] = $row['last_name'];
        }

        if (! empty($row['display_name'])) {
            $payload['display_name'] = $row['display_name'];
        }
    }

    private function maybe_update_meta($user_id, array $row)
    {
        if (empty($row['meta']) || ! is_array($row['meta'])) {
            return;
        }

        foreach ($row['meta'] as $meta_key => $meta_value) {
            update_user_meta($user_id, $meta_key, $meta_value);
        }
    }
}