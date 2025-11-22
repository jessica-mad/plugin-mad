<?php
/**
 * Clase FileHandler para MAD Gemini Assistant
 *
 * Maneja la carga, validación y procesamiento de archivos para enviar a Gemini API.
 * Soporta imágenes, PDFs y otros formatos compatibles.
 *
 * @package MAD_Gemini_Assistant
 */

if (!defined('ABSPATH')) exit;

class MAD_Gemini_FileHandler {
    /**
     * Tipos MIME permitidos
     */
    private const ALLOWED_MIME_TYPES = [
        // Imágenes
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
        'image/heic' => ['heic'],
        'image/heif' => ['heif'],
        // Documentos
        'application/pdf' => ['pdf'],
        'text/plain' => ['txt'],
    ];

    /**
     * Tamaño máximo por archivo (en bytes) - 20MB
     */
    private const MAX_FILE_SIZE = 20971520; // 20MB

    /**
     * Tamaño máximo total de petición (en bytes) - 18MB (dejamos margen)
     */
    private const MAX_TOTAL_SIZE = 18874368; // 18MB

    /**
     * Procesar archivos subidos
     *
     * @param array $files Array de $_FILES
     * @return array|WP_Error Array de archivos procesados o error
     */
    public function process_uploads($files) {
        if (empty($files) || !is_array($files)) {
            return [];
        }

        $processed = [];
        $total_size = 0;

        foreach ($files as $file) {
            // Validar archivo
            $validation = $this->validate_file($file);
            if (is_wp_error($validation)) {
                return $validation;
            }

            // Verificar tamaño individual
            if ($file['size'] > self::MAX_FILE_SIZE) {
                return new WP_Error(
                    'file_too_large',
                    sprintf(
                        __('El archivo %s excede el tamaño máximo de %s', 'mad-suite'),
                        $file['name'],
                        size_format(self::MAX_FILE_SIZE)
                    )
                );
            }

            // Verificar tamaño total
            $total_size += $file['size'];
            if ($total_size > self::MAX_TOTAL_SIZE) {
                return new WP_Error(
                    'total_size_exceeded',
                    sprintf(
                        __('El tamaño total de los archivos excede %s', 'mad-suite'),
                        size_format(self::MAX_TOTAL_SIZE)
                    )
                );
            }

            // Procesar archivo según tipo
            $processed_file = $this->process_file($file);
            if (is_wp_error($processed_file)) {
                return $processed_file;
            }

            $processed[] = $processed_file;
        }

        return $processed;
    }

    /**
     * Validar archivo
     *
     * @param array $file Archivo de $_FILES
     * @return bool|WP_Error
     */
    private function validate_file($file) {
        // Verificar errores de upload
        if (!isset($file['error']) || is_array($file['error'])) {
            return new WP_Error('invalid_file', __('Archivo inválido', 'mad-suite'));
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return new WP_Error('upload_error', $this->get_upload_error_message($file['error']));
        }

        // Verificar que existe
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return new WP_Error('file_not_found', __('No se encontró el archivo', 'mad-suite'));
        }

        // Verificar tipo MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!array_key_exists($mime_type, self::ALLOWED_MIME_TYPES)) {
            return new WP_Error(
                'invalid_type',
                sprintf(
                    __('Tipo de archivo no permitido: %s', 'mad-suite'),
                    $mime_type
                )
            );
        }

        return true;
    }

    /**
     * Procesar archivo individual
     *
     * @param array $file
     * @return array|WP_Error
     */
    private function process_file($file) {
        // Obtener tipo MIME
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        // Leer contenido y convertir a base64
        $content = file_get_contents($file['tmp_name']);
        if ($content === false) {
            return new WP_Error('read_error', __('No se pudo leer el archivo', 'mad-suite'));
        }

        $base64 = base64_encode($content);

        return [
            'name' => sanitize_file_name($file['name']),
            'type' => $mime_type,
            'size' => $file['size'],
            'inline_data' => [
                'mime_type' => $mime_type,
                'data' => $base64,
            ],
        ];
    }

    /**
     * Obtener mensaje de error de upload
     *
     * @param int $error Código de error
     * @return string
     */
    private function get_upload_error_message($error) {
        switch ($error) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return __('El archivo excede el tamaño máximo permitido', 'mad-suite');
            case UPLOAD_ERR_PARTIAL:
                return __('El archivo se subió parcialmente', 'mad-suite');
            case UPLOAD_ERR_NO_FILE:
                return __('No se subió ningún archivo', 'mad-suite');
            case UPLOAD_ERR_NO_TMP_DIR:
                return __('Falta carpeta temporal', 'mad-suite');
            case UPLOAD_ERR_CANT_WRITE:
                return __('Error al escribir el archivo', 'mad-suite');
            case UPLOAD_ERR_EXTENSION:
                return __('Extensión de PHP detuvo la subida', 'mad-suite');
            default:
                return __('Error desconocido al subir archivo', 'mad-suite');
        }
    }

    /**
     * Validar extensión de archivo
     *
     * @param string $filename
     * @return bool
     */
    public function is_allowed_extension($filename) {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        foreach (self::ALLOWED_MIME_TYPES as $mime => $extensions) {
            if (in_array($extension, $extensions)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Obtener extensiones permitidas
     *
     * @return array
     */
    public static function get_allowed_extensions() {
        $extensions = [];
        foreach (self::ALLOWED_MIME_TYPES as $mime => $exts) {
            $extensions = array_merge($extensions, $exts);
        }
        return $extensions;
    }

    /**
     * Obtener extensiones permitidas como string
     *
     * @return string Para usar en accept de input file
     */
    public static function get_allowed_extensions_string() {
        $extensions = self::get_allowed_extensions();
        return '.' . implode(',.', $extensions);
    }

    /**
     * Determinar si un archivo es imagen
     *
     * @param string $mime_type
     * @return bool
     */
    public static function is_image($mime_type) {
        return strpos($mime_type, 'image/') === 0;
    }

    /**
     * Obtener icono según tipo de archivo
     *
     * @param string $mime_type
     * @return string Clase de dashicon
     */
    public static function get_file_icon($mime_type) {
        if (self::is_image($mime_type)) {
            return 'dashicons-format-image';
        }

        if ($mime_type === 'application/pdf') {
            return 'dashicons-pdf';
        }

        if ($mime_type === 'text/plain') {
            return 'dashicons-media-text';
        }

        return 'dashicons-media-default';
    }
}
