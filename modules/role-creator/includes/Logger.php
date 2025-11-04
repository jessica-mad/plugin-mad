<?php

namespace MAD_Suite\Modules\RoleCreator;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Sistema de logging para el módulo Role Creator
 * Registra eventos de asignación de roles y sincronización con Mailchimp
 */
class Logger
{
    /**
     * Clave de opción para almacenar logs
     */
    const OPTION_KEY = 'madsuite_role_creator_logs';

    /**
     * Número máximo de logs a mantener
     */
    const MAX_LOGS = 500;

    /**
     * Niveles de log
     */
    const LEVEL_INFO    = 'info';
    const LEVEL_SUCCESS = 'success';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR   = 'error';
    const LEVEL_DEBUG   = 'debug';

    /**
     * Instancia singleton
     *
     * @var Logger
     */
    private static $instance = null;

    /**
     * Obtener instancia singleton
     *
     * @return Logger
     */
    public static function instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor privado para singleton
     */
    private function __construct()
    {
        // Constructor vacío
    }

    /**
     * Registra un mensaje de log
     *
     * @param string $level   Nivel del log (info, success, warning, error, debug)
     * @param string $message Mensaje a registrar
     * @param array  $context Datos adicionales de contexto
     * @return void
     */
    public function log($level, $message, $context = [])
    {
        $entry = [
            'timestamp' => current_time('mysql'),
            'level'     => $level,
            'message'   => $message,
            'context'   => $context,
            'user_id'   => get_current_user_id(),
        ];

        // Obtener logs existentes
        $logs = $this->get_logs();

        // Agregar nuevo log al inicio
        array_unshift($logs, $entry);

        // Limitar el número de logs
        if (count($logs) > self::MAX_LOGS) {
            $logs = array_slice($logs, 0, self::MAX_LOGS);
        }

        // Guardar logs
        update_option(self::OPTION_KEY, $logs, false);

        // También escribir a error_log de WordPress si está habilitado el debug
        if (defined('WP_DEBUG') && WP_DEBUG && defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            $context_str = ! empty($context) ? ' | Context: ' . wp_json_encode($context) : '';
            error_log(sprintf('[MAD Role Creator] [%s] %s%s', strtoupper($level), $message, $context_str));
        }
    }

    /**
     * Log de información
     *
     * @param string $message Mensaje
     * @param array  $context Contexto
     * @return void
     */
    public function info($message, $context = [])
    {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Log de éxito
     *
     * @param string $message Mensaje
     * @param array  $context Contexto
     * @return void
     */
    public function success($message, $context = [])
    {
        $this->log(self::LEVEL_SUCCESS, $message, $context);
    }

    /**
     * Log de advertencia
     *
     * @param string $message Mensaje
     * @param array  $context Contexto
     * @return void
     */
    public function warning($message, $context = [])
    {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Log de error
     *
     * @param string $message Mensaje
     * @param array  $context Contexto
     * @return void
     */
    public function error($message, $context = [])
    {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Log de debug
     *
     * @param string $message Mensaje
     * @param array  $context Contexto
     * @return void
     */
    public function debug($message, $context = [])
    {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Obtiene todos los logs
     *
     * @param int    $limit  Límite de logs a obtener (0 = todos)
     * @param string $level  Filtrar por nivel (opcional)
     * @return array
     */
    public function get_logs($limit = 0, $level = '')
    {
        $logs = get_option(self::OPTION_KEY, []);

        if (! is_array($logs)) {
            $logs = [];
        }

        // Filtrar por nivel si se especifica
        if (! empty($level)) {
            $logs = array_filter($logs, function ($log) use ($level) {
                return isset($log['level']) && $log['level'] === $level;
            });
        }

        // Aplicar límite si se especifica
        if ($limit > 0) {
            $logs = array_slice($logs, 0, $limit);
        }

        return $logs;
    }

    /**
     * Limpia todos los logs
     *
     * @return bool
     */
    public function clear_logs()
    {
        return delete_option(self::OPTION_KEY);
    }

    /**
     * Obtiene estadísticas de logs
     *
     * @return array
     */
    public function get_stats()
    {
        $logs = $this->get_logs();

        $stats = [
            'total'   => count($logs),
            'info'    => 0,
            'success' => 0,
            'warning' => 0,
            'error'   => 0,
            'debug'   => 0,
        ];

        foreach ($logs as $log) {
            if (isset($log['level']) && isset($stats[$log['level']])) {
                $stats[$log['level']]++;
            }
        }

        return $stats;
    }

    /**
     * Exporta logs como JSON
     *
     * @return string
     */
    public function export_json()
    {
        $logs = $this->get_logs();
        return wp_json_encode($logs, JSON_PRETTY_PRINT);
    }

    /**
     * Obtiene logs de las últimas N horas
     *
     * @param int $hours Número de horas
     * @return array
     */
    public function get_recent_logs($hours = 24)
    {
        $logs = $this->get_logs();
        $cutoff_time = strtotime('-' . $hours . ' hours');

        return array_filter($logs, function ($log) use ($cutoff_time) {
            if (! isset($log['timestamp'])) {
                return false;
            }
            return strtotime($log['timestamp']) >= $cutoff_time;
        });
    }

    /**
     * Log específico para asignación de roles
     *
     * @param int    $user_id    ID del usuario
     * @param string $role       Rol asignado
     * @param string $method     Método de asignación (automatic, manual, csv)
     * @param mixed  $rule_id    ID de la regla (si aplica)
     * @return void
     */
    public function log_role_assignment($user_id, $role, $method = 'automatic', $rule_id = null)
    {
        $user = get_userdata($user_id);
        $context = [
            'user_id'    => $user_id,
            'user_email' => $user ? $user->user_email : 'unknown',
            'role'       => $role,
            'method'     => $method,
        ];

        if ($rule_id) {
            $context['rule_id'] = $rule_id;
        }

        $this->success(
            sprintf('Rol "%s" asignado a usuario #%d (%s) vía %s', $role, $user_id, $user ? $user->user_email : 'unknown', $method),
            $context
        );
    }

    /**
     * Log específico para sincronización con Mailchimp
     *
     * @param int    $user_id ID del usuario
     * @param bool   $success Si la sincronización fue exitosa
     * @param string $message Mensaje adicional
     * @param array  $context Contexto adicional
     * @return void
     */
    public function log_mailchimp_sync($user_id, $success, $message = '', $context = [])
    {
        $user = get_userdata($user_id);
        $base_context = [
            'user_id'    => $user_id,
            'user_email' => $user ? $user->user_email : 'unknown',
            'service'    => 'mailchimp',
        ];

        $full_context = array_merge($base_context, $context);

        if ($success) {
            $this->success(
                sprintf('Usuario #%d (%s) sincronizado con Mailchimp: %s', $user_id, $user ? $user->user_email : 'unknown', $message),
                $full_context
            );
        } else {
            $this->error(
                sprintf('Error al sincronizar usuario #%d (%s) con Mailchimp: %s', $user_id, $user ? $user->user_email : 'unknown', $message),
                $full_context
            );
        }
    }
}
