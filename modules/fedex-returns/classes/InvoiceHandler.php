<?php
/**
 * Gestor de facturas de devolución
 * Obtiene las facturas existentes generadas por plugins externos
 */

if (!defined('ABSPATH')) exit;

class MAD_FedEx_Invoice_Handler {
    private $logger;

    public function __construct($logger) {
        $this->logger = $logger;
    }

    /**
     * Obtener factura de devolución desde el pedido
     * Busca facturas PDF adjuntas al pedido por plugins externos
     */
    public function get_invoice_from_order($order) {
        if (!$order || !$order instanceof WC_Order) {
            return new WP_Error('invalid_order', __('Pedido inválido.', 'mad-suite'));
        }

        $order_id = $order->get_id();

        // Método 1: Buscar adjuntos del pedido (común en plugins de facturas)
        $attachments = get_posts([
            'post_type' => 'attachment',
            'post_parent' => $order_id,
            'post_mime_type' => 'application/pdf',
            'numberposts' => -1,
        ]);

        if (!empty($attachments)) {
            // Buscar específicamente facturas (invoice/factura en el nombre)
            foreach ($attachments as $attachment) {
                $filename = basename($attachment->guid);
                if (stripos($filename, 'invoice') !== false ||
                    stripos($filename, 'factura') !== false) {

                    $file_path = get_attached_file($attachment->ID);

                    if (file_exists($file_path)) {
                        $this->logger->log(sprintf(
                            'Factura encontrada para pedido #%d: %s',
                            $order_id,
                            $filename
                        ));

                        return [
                            'attachment_id' => $attachment->ID,
                            'url' => wp_get_attachment_url($attachment->ID),
                            'path' => $file_path,
                            'filename' => $filename,
                        ];
                    }
                }
            }
        }

        // Método 2: Buscar en meta del pedido (algunos plugins guardan la ruta)
        $invoice_meta_keys = [
            '_wcpdf_invoice_number',
            '_invoice_number',
            'invoice_pdf',
            '_bewpi_invoice_pdf_path',
        ];

        foreach ($invoice_meta_keys as $meta_key) {
            $invoice_path = get_post_meta($order_id, $meta_key, true);
            if (!empty($invoice_path) && file_exists($invoice_path)) {
                $this->logger->log(sprintf(
                    'Factura encontrada en meta para pedido #%d: %s',
                    $order_id,
                    basename($invoice_path)
                ));

                return [
                    'attachment_id' => 0,
                    'url' => $this->convert_path_to_url($invoice_path),
                    'path' => $invoice_path,
                    'filename' => basename($invoice_path),
                ];
            }
        }

        // Método 3: Buscar en directorio de uploads de WooCommerce
        $upload_dir = wp_upload_dir();
        $possible_dirs = [
            $upload_dir['basedir'] . '/woocommerce_pdf_invoices',
            $upload_dir['basedir'] . '/invoices',
            $upload_dir['basedir'] . '/wpo_wcpdf',
        ];

        foreach ($possible_dirs as $dir) {
            if (is_dir($dir)) {
                $invoice_file = $this->find_invoice_in_directory($dir, $order_id, $order->get_order_number());
                if ($invoice_file) {
                    $this->logger->log(sprintf(
                        'Factura encontrada en directorio para pedido #%d: %s',
                        $order_id,
                        basename($invoice_file)
                    ));

                    return [
                        'attachment_id' => 0,
                        'url' => $this->convert_path_to_url($invoice_file),
                        'path' => $invoice_file,
                        'filename' => basename($invoice_file),
                    ];
                }
            }
        }

        $this->logger->warning(sprintf(
            'No se encontró factura para pedido #%d',
            $order_id
        ));

        return false;
    }

    /**
     * Buscar archivo de factura en un directorio
     */
    private function find_invoice_in_directory($dir, $order_id, $order_number) {
        $patterns = [
            sprintf('*invoice*%d*.pdf', $order_id),
            sprintf('*invoice*%s*.pdf', $order_number),
            sprintf('*factura*%d*.pdf', $order_id),
            sprintf('*factura*%s*.pdf', $order_number),
        ];

        foreach ($patterns as $pattern) {
            $files = glob($dir . '/' . $pattern);
            if (!empty($files)) {
                // Ordenar por fecha de modificación (más reciente primero)
                usort($files, function($a, $b) {
                    return filemtime($b) - filemtime($a);
                });
                return $files[0];
            }
        }

        return false;
    }

    /**
     * Convertir ruta de archivo a URL
     */
    private function convert_path_to_url($file_path) {
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'];
        $base_url = $upload_dir['baseurl'];

        if (strpos($file_path, $base_dir) === 0) {
            return str_replace($base_dir, $base_url, $file_path);
        }

        return '';
    }

    /**
     * Generar URL de factura para el pedido usando el plugin instalado
     */
    public function get_invoice_url($order) {
        // Intentar obtener la URL desde el plugin WooCommerce PDF Invoices
        if (class_exists('WPO_WCPDF')) {
            try {
                // Plugin: PDF Invoices & Packing Slips for WooCommerce
                $invoice = wcpdf_get_invoice($order);
                if ($invoice && method_exists($invoice, 'get_pdf')) {
                    $pdf = $invoice->get_pdf();
                    if ($pdf) {
                        return $invoice->get_pdf_url();
                    }
                }
            } catch (Exception $e) {
                $this->logger->error('Error al obtener URL de factura: ' . $e->getMessage());
            }
        }

        // Intentar obtener desde meta del pedido
        $invoice_data = $this->get_invoice_from_order($order);
        if ($invoice_data && !is_wp_error($invoice_data)) {
            return $invoice_data['url'];
        }

        return false;
    }

    /**
     * Verificar si existe factura para el pedido
     */
    public function has_invoice($order) {
        $invoice_data = $this->get_invoice_from_order($order);
        return !empty($invoice_data) && !is_wp_error($invoice_data);
    }

    /**
     * Obtener path de la factura
     */
    public function get_invoice_path($order) {
        $invoice_data = $this->get_invoice_from_order($order);
        if ($invoice_data && !is_wp_error($invoice_data)) {
            return $invoice_data['path'];
        }
        return false;
    }

    /**
     * Obtener información de factura para adjuntar a FedEx
     */
    public function prepare_invoice_for_fedex($order) {
        $invoice_data = $this->get_invoice_from_order($order);

        if (!$invoice_data || is_wp_error($invoice_data)) {
            return false;
        }

        // Verificar que el archivo existe y es accesible
        if (!file_exists($invoice_data['path']) || !is_readable($invoice_data['path'])) {
            $this->logger->error(sprintf(
                'Archivo de factura no accesible: %s',
                $invoice_data['path']
            ));
            return false;
        }

        // Verificar tamaño del archivo (FedEx tiene límites)
        $file_size = filesize($invoice_data['path']);
        $max_size = 2 * 1024 * 1024; // 2MB

        if ($file_size > $max_size) {
            $this->logger->warning(sprintf(
                'Factura demasiado grande (%s), puede no ser aceptada por FedEx',
                size_format($file_size)
            ));
        }

        return [
            'url' => $invoice_data['url'],
            'path' => $invoice_data['path'],
            'filename' => $invoice_data['filename'],
            'size' => $file_size,
        ];
    }

    /**
     * Crear factura de devolución (wrapper para compatibilidad)
     */
    public function create_return_invoice($order, $return_items, $return_reason) {
        // Como las facturas ya existen, solo obtenemos la información
        $invoice_data = $this->get_invoice_from_order($order);

        if (!$invoice_data || is_wp_error($invoice_data)) {
            $this->logger->warning(sprintf(
                'No se encontró factura existente para pedido #%d',
                $order->get_id()
            ));
            return new WP_Error(
                'no_invoice',
                __('No se encontró factura para este pedido. Asegúrate de que el plugin de facturas esté activo y haya generado la factura.', 'mad-suite')
            );
        }

        return $invoice_data;
    }
}
