<?php
/**
 * Gestor de facturas de devolución
 */

if (!defined('ABSPATH')) exit;

class MAD_FedEx_Invoice_Handler {
    private $logger;

    public function __construct($logger) {
        $this->logger = $logger;
    }

    /**
     * Crear factura de devolución
     */
    public function create_return_invoice($order, $return_items, $return_reason) {
        if (!$order || !$order instanceof WC_Order) {
            return new WP_Error('invalid_order', __('Pedido inválido.', 'mad-suite'));
        }

        try {
            // Generar contenido HTML de la factura
            $html = $this->generate_invoice_html($order, $return_items, $return_reason);

            // Convertir a PDF (usando biblioteca externa o wp_generate_pdf si está disponible)
            $pdf_result = $this->generate_pdf($html, $order->get_id());

            if (is_wp_error($pdf_result)) {
                return $pdf_result;
            }

            // Adjuntar PDF al pedido
            $attachment_id = $this->attach_pdf_to_order($pdf_result['path'], $order->get_id());

            if (is_wp_error($attachment_id)) {
                return $attachment_id;
            }

            $this->logger->log(sprintf(
                'Factura de devolución creada para pedido #%d - Attachment ID: %d',
                $order->get_id(),
                $attachment_id
            ));

            return [
                'attachment_id' => $attachment_id,
                'url' => wp_get_attachment_url($attachment_id),
                'path' => get_attached_file($attachment_id),
            ];

        } catch (Exception $e) {
            $this->logger->error('Error al crear factura: ' . $e->getMessage());
            return new WP_Error('invoice_error', $e->getMessage());
        }
    }

    /**
     * Generar HTML de la factura
     */
    private function generate_invoice_html($order, $return_items, $return_reason) {
        $settings = $this->get_module_settings();
        $site_name = get_bloginfo('name');
        $logo_url = $settings['invoice_logo_url'] ?? '';
        $footer_text = $settings['invoice_footer_text'] ?? '';

        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php echo esc_html__('Factura de Devolución', 'mad-suite'); ?> - <?php echo $order->get_order_number(); ?></title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    margin: 0;
                    padding: 20px;
                    color: #333;
                }
                .header {
                    text-align: center;
                    margin-bottom: 30px;
                    border-bottom: 2px solid #0073aa;
                    padding-bottom: 20px;
                }
                .logo {
                    max-width: 200px;
                    margin-bottom: 15px;
                }
                .invoice-title {
                    font-size: 24px;
                    font-weight: bold;
                    color: #0073aa;
                    margin: 10px 0;
                }
                .info-section {
                    margin-bottom: 30px;
                }
                .info-row {
                    display: flex;
                    justify-content: space-between;
                    margin-bottom: 20px;
                }
                .info-box {
                    width: 48%;
                }
                .info-box h3 {
                    font-size: 14px;
                    color: #0073aa;
                    margin-bottom: 10px;
                    border-bottom: 1px solid #ddd;
                    padding-bottom: 5px;
                }
                .info-box p {
                    margin: 5px 0;
                    font-size: 13px;
                }
                .table-container {
                    margin-bottom: 30px;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                }
                th {
                    background-color: #0073aa;
                    color: white;
                    padding: 10px;
                    text-align: left;
                    font-size: 13px;
                }
                td {
                    padding: 10px;
                    border-bottom: 1px solid #ddd;
                    font-size: 13px;
                }
                .reason-box {
                    background-color: #f9f9f9;
                    border: 1px solid #ddd;
                    padding: 15px;
                    margin-bottom: 30px;
                    border-radius: 5px;
                }
                .reason-box h3 {
                    margin-top: 0;
                    color: #0073aa;
                    font-size: 14px;
                }
                .footer {
                    text-align: center;
                    margin-top: 40px;
                    padding-top: 20px;
                    border-top: 1px solid #ddd;
                    font-size: 12px;
                    color: #666;
                }
                .total-row {
                    font-weight: bold;
                    background-color: #f5f5f5;
                }
            </style>
        </head>
        <body>
            <div class="header">
                <?php if ($logo_url): ?>
                    <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($site_name); ?>" class="logo">
                <?php else: ?>
                    <h1><?php echo esc_html($site_name); ?></h1>
                <?php endif; ?>
                <div class="invoice-title"><?php echo esc_html__('FACTURA DE DEVOLUCIÓN', 'mad-suite'); ?></div>
            </div>

            <div class="info-section">
                <div class="info-row">
                    <div class="info-box">
                        <h3><?php echo esc_html__('Información del Pedido', 'mad-suite'); ?></h3>
                        <p><strong><?php echo esc_html__('Número de Pedido:', 'mad-suite'); ?></strong> #<?php echo $order->get_order_number(); ?></p>
                        <p><strong><?php echo esc_html__('Fecha de Pedido:', 'mad-suite'); ?></strong> <?php echo $order->get_date_created()->date('d/m/Y'); ?></p>
                        <p><strong><?php echo esc_html__('Fecha de Devolución:', 'mad-suite'); ?></strong> <?php echo date('d/m/Y'); ?></p>
                    </div>
                    <div class="info-box">
                        <h3><?php echo esc_html__('Cliente', 'mad-suite'); ?></h3>
                        <p><strong><?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?></strong></p>
                        <p><?php echo esc_html($order->get_billing_address_1()); ?></p>
                        <?php if ($order->get_billing_address_2()): ?>
                            <p><?php echo esc_html($order->get_billing_address_2()); ?></p>
                        <?php endif; ?>
                        <p><?php echo esc_html($order->get_billing_city() . ', ' . $order->get_billing_state() . ' ' . $order->get_billing_postcode()); ?></p>
                        <p><?php echo esc_html($order->get_billing_country()); ?></p>
                        <p><?php echo esc_html__('Email:', 'mad-suite'); ?> <?php echo esc_html($order->get_billing_email()); ?></p>
                        <p><?php echo esc_html__('Teléfono:', 'mad-suite'); ?> <?php echo esc_html($order->get_billing_phone()); ?></p>
                    </div>
                </div>
            </div>

            <?php if (!empty($return_reason)): ?>
            <div class="reason-box">
                <h3><?php echo esc_html__('Motivo de Devolución', 'mad-suite'); ?></h3>
                <p><?php echo nl2br(esc_html($return_reason)); ?></p>
            </div>
            <?php endif; ?>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Producto', 'mad-suite'); ?></th>
                            <th><?php echo esc_html__('SKU', 'mad-suite'); ?></th>
                            <th><?php echo esc_html__('Cantidad', 'mad-suite'); ?></th>
                            <th><?php echo esc_html__('Precio Unitario', 'mad-suite'); ?></th>
                            <th><?php echo esc_html__('Total', 'mad-suite'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $total_return = 0;
                        foreach ($return_items as $item_data):
                            $item_id = $item_data['item_id'] ?? 0;
                            $quantity = $item_data['quantity'] ?? 1;

                            $item = $order->get_item($item_id);
                            if (!$item) continue;

                            $product = $item->get_product();
                            if (!$product) continue;

                            $item_total = $item->get_total() / $item->get_quantity() * $quantity;
                            $total_return += $item_total;
                        ?>
                        <tr>
                            <td><?php echo esc_html($item->get_name()); ?></td>
                            <td><?php echo esc_html($product->get_sku() ?: '-'); ?></td>
                            <td><?php echo esc_html($quantity); ?></td>
                            <td><?php echo wc_price($item->get_total() / $item->get_quantity()); ?></td>
                            <td><?php echo wc_price($item_total); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="total-row">
                            <td colspan="4" style="text-align: right;"><?php echo esc_html__('TOTAL A DEVOLVER:', 'mad-suite'); ?></td>
                            <td><?php echo wc_price($total_return); ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <?php if ($footer_text): ?>
            <div class="footer">
                <?php echo wp_kses_post(nl2br($footer_text)); ?>
            </div>
            <?php endif; ?>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Generar PDF desde HTML
     */
    private function generate_pdf($html, $order_id) {
        // Verificar si tenemos una biblioteca de PDF disponible
        // Opción 1: Usar DOMPDF (si está instalada)
        if (class_exists('Dompdf\Dompdf')) {
            return $this->generate_pdf_with_dompdf($html, $order_id);
        }

        // Opción 2: Usar wkhtmltopdf (si está disponible)
        if ($this->is_wkhtmltopdf_available()) {
            return $this->generate_pdf_with_wkhtmltopdf($html, $order_id);
        }

        // Opción 3: Guardar como HTML (fallback)
        return $this->save_as_html($html, $order_id);
    }

    /**
     * Generar PDF con DOMPDF
     */
    private function generate_pdf_with_dompdf($html, $order_id) {
        try {
            $dompdf = new \Dompdf\Dompdf();
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $upload_dir = wp_upload_dir();
            $pdf_dir = $upload_dir['basedir'] . '/fedex-returns-invoices';

            if (!file_exists($pdf_dir)) {
                wp_mkdir_p($pdf_dir);
            }

            $filename = 'return-invoice-' . $order_id . '-' . time() . '.pdf';
            $filepath = $pdf_dir . '/' . $filename;

            file_put_contents($filepath, $dompdf->output());

            return [
                'path' => $filepath,
                'filename' => $filename,
            ];

        } catch (Exception $e) {
            $this->logger->error('Error al generar PDF con DOMPDF: ' . $e->getMessage());
            return new WP_Error('pdf_error', $e->getMessage());
        }
    }

    /**
     * Generar PDF con wkhtmltopdf
     */
    private function generate_pdf_with_wkhtmltopdf($html, $order_id) {
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/temp';
        $pdf_dir = $upload_dir['basedir'] . '/fedex-returns-invoices';

        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
        if (!file_exists($pdf_dir)) {
            wp_mkdir_p($pdf_dir);
        }

        $html_filename = 'temp-' . $order_id . '-' . time() . '.html';
        $html_filepath = $temp_dir . '/' . $html_filename;

        $pdf_filename = 'return-invoice-' . $order_id . '-' . time() . '.pdf';
        $pdf_filepath = $pdf_dir . '/' . $pdf_filename;

        file_put_contents($html_filepath, $html);

        $command = sprintf(
            'wkhtmltopdf %s %s 2>&1',
            escapeshellarg($html_filepath),
            escapeshellarg($pdf_filepath)
        );

        exec($command, $output, $return_var);

        // Limpiar archivo temporal
        if (file_exists($html_filepath)) {
            unlink($html_filepath);
        }

        if ($return_var !== 0 || !file_exists($pdf_filepath)) {
            return new WP_Error('pdf_error', __('Error al generar PDF con wkhtmltopdf.', 'mad-suite'));
        }

        return [
            'path' => $pdf_filepath,
            'filename' => $pdf_filename,
        ];
    }

    /**
     * Guardar como HTML (fallback)
     */
    private function save_as_html($html, $order_id) {
        $upload_dir = wp_upload_dir();
        $html_dir = $upload_dir['basedir'] . '/fedex-returns-invoices';

        if (!file_exists($html_dir)) {
            wp_mkdir_p($html_dir);
        }

        $filename = 'return-invoice-' . $order_id . '-' . time() . '.html';
        $filepath = $html_dir . '/' . $filename;

        file_put_contents($filepath, $html);

        $this->logger->warning('PDF library not available, saving as HTML instead.');

        return [
            'path' => $filepath,
            'filename' => $filename,
        ];
    }

    /**
     * Verificar si wkhtmltopdf está disponible
     */
    private function is_wkhtmltopdf_available() {
        exec('which wkhtmltopdf', $output, $return_var);
        return $return_var === 0;
    }

    /**
     * Adjuntar PDF al pedido
     */
    private function attach_pdf_to_order($filepath, $order_id) {
        $filename = basename($filepath);
        $filetype = wp_check_filetype($filename, null);

        $attachment = [
            'guid' => wp_upload_dir()['url'] . '/' . $filename,
            'post_mime_type' => $filetype['type'] ?? 'application/pdf',
            'post_title' => sprintf(__('Factura de Devolución - Pedido #%d', 'mad-suite'), $order_id),
            'post_content' => '',
            'post_status' => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment, $filepath, $order_id);

        if (is_wp_error($attach_id)) {
            return $attach_id;
        }

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
    }

    /**
     * Obtener configuración del módulo
     */
    private function get_module_settings() {
        $option_key = 'madsuite_fedex-returns_settings';
        return get_option($option_key, []);
    }
}
