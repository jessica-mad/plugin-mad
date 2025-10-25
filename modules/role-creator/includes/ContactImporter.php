<?php
namespace MAD_Suite\Modules\RoleCreator;

use WP_Error;

if (! defined('ABSPATH')) {
    exit;
}

class ContactImporter
{
    /**
     * Construye una colección de filas a partir de un archivo subido.
     *
     * @param array $file
     * @return array|WP_Error
     */
    public static function from_uploaded_file(array $file)
    {
        if (! empty($file['error'])) {
            return new WP_Error('mads_role_creator_upload', self::upload_error_message((int) $file['error']));
        }

        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ('csv' !== $extension) {
            return new WP_Error('mads_role_creator_extension', __('El archivo debe ser un CSV válido.', 'mad-suite'));
        }

        return self::parse_file($file['tmp_name']);
    }

    /**
     * Parsea un archivo CSV y normaliza los datos.
     *
     * @param string $path
     * @return array|WP_Error
     */
    public static function parse_file($path)
    {
        $handle = fopen($path, 'r');

        if (! $handle) {
            return new WP_Error('mads_role_creator_open', __('No fue posible abrir el archivo CSV.', 'mad-suite'));
        }

        $headers = [];
        $delimiter = ',';
        $rows = [];
        $line = 0;

        $required_headers_validated = false;

        while (($raw = fgets($handle)) !== false) {
            $line++;

            if (1 === $line) {
                $delimiter = self::detect_delimiter($raw);
            }

            if ('' === trim($raw)) {
                continue;
            }

            $row = str_getcsv($raw, $delimiter);

            if (1 === $line) {
                $headers = array_map([
                    __CLASS__,
                    'normalize_header',
                ], $row);

                if (! in_array('email', $headers, true)) {
                    fclose($handle);

                    return new WP_Error('mads_role_creator_headers', __('El archivo CSV debe incluir la columna email.', 'mad-suite'));
                }

                $required_headers_validated = true;

                continue;
            }

            if (! $headers || ! $required_headers_validated) {
                continue;
            }

            $normalized = self::combine_row($headers, $row, $line);

            if (empty($normalized)) {
                continue;
            }

            $rows[] = $normalized;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Proporciona filas de ejemplo para mostrar en la interfaz o descargar plantilla.
     *
     * @return array<int, array{email:string,first_name:string,last_name:string,display_name:string}>
     */
    public static function sample_rows()
    {
        return [
            [
                'email'        => 'cliente1@tienda.com',
                'first_name'   => 'María',
                'last_name'    => 'Pérez',
                'display_name' => 'María Pérez',
            ],
            [
                'email'        => 'cliente2@tienda.com',
                'first_name'   => 'Juan',
                'last_name'    => 'García',
                'display_name' => 'Juan García',
            ],
        ];
    }

    private static function combine_row(array $headers, array $values, $line)
    {
        $data = [];
        foreach ($headers as $index => $header) {
            $data[$header] = isset($values[$index]) ? trim($values[$index]) : '';
        }

        $email = sanitize_email($data['email'] ?? '');
        if (empty($email)) {
            return [];
        }

        $normalized = [
            'email'        => $email,
            'first_name'   => sanitize_text_field($data['first_name'] ?? ''),
            'last_name'    => sanitize_text_field($data['last_name'] ?? ''),
            'display_name' => sanitize_text_field($data['display_name'] ?? ''),
            'user_login'   => sanitize_user($data['user_login'] ?? ''),
            'user_pass'    => $data['user_pass'] ?? '',
            'line'         => (int) $line,
        ];

        foreach ($data as $key => $value) {
            if (0 === strpos($key, 'meta_')) {
                $meta_key = substr($key, 5);
                if ($meta_key) {
                    $normalized['meta'][$meta_key] = sanitize_text_field($value);
                }
            }
        }

        return $normalized;
    }

    private static function detect_delimiter($line)
    {
        $candidates = [',', ';', '\t', '|'];
        $selected = ',';
        $maxCount = 0;

        foreach ($candidates as $candidate) {
            $count = substr_count($line, $candidate);
            if ($count > $maxCount) {
                $maxCount = $count;
                $selected = $candidate;
            }
        }

        return $selected;
    }

    private static function normalize_header($value)
    {
        $value = strtolower(trim($value));
        $value = str_replace([' ', '-'], '_', $value);

        switch ($value) {
            case 'correo':
            case 'email_address':
                return 'email';
            case 'nombre':
            case 'first':
                return 'first_name';
            case 'apellidos':
            case 'apellido':
            case 'last':
                return 'last_name';
            case 'nombre_visible':
                return 'display_name';
            default:
                return $value;
        }
    }

    private static function upload_error_message($code)
    {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => __('El archivo excede el tamaño máximo permitido.', 'mad-suite'),
            UPLOAD_ERR_FORM_SIZE  => __('El archivo excede el tamaño máximo definido en el formulario.', 'mad-suite'),
            UPLOAD_ERR_PARTIAL    => __('La carga del archivo fue incompleta.', 'mad-suite'),
            UPLOAD_ERR_NO_FILE    => __('No se seleccionó ningún archivo para subir.', 'mad-suite'),
            UPLOAD_ERR_NO_TMP_DIR => __('Falta la carpeta temporal del servidor.', 'mad-suite'),
            UPLOAD_ERR_CANT_WRITE => __('No se pudo guardar el archivo en el disco.', 'mad-suite'),
            UPLOAD_ERR_EXTENSION  => __('Una extensión impidió la carga del archivo.', 'mad-suite'),
        ];

        return $messages[$code] ?? __('No se pudo cargar el archivo.', 'mad-suite');
    }
}