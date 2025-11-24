<?php
/**
 * Gestor de devoluciones FedEx
 */

if (!defined('ABSPATH')) exit;

class MAD_FedEx_Return_Manager {
    private $fedex_api;
    private $logger;

    public function __construct($fedex_api, $logger) {
        $this->fedex_api = $fedex_api;
        $this->logger = $logger;
    }

    /**
     * Crear devolución en FedEx
     */
    public function create_return($order, $return_items, $return_reason, $weight, $dimensions, $invoice_url = '') {
        if (!$order || !$order instanceof WC_Order) {
            return new WP_Error('invalid_order', __('Pedido inválido.', 'mad-suite'));
        }

        // Verificar que no exista ya una devolución
        $existing_return = $this->get_order_return_data($order->get_id());
        if ($existing_return) {
            return new WP_Error('return_exists', __('Ya existe una devolución para este pedido.', 'mad-suite'));
        }

        try {
            // Preparar datos del envío
            $shipment_data = $this->prepare_shipment_data($order, $return_items, $weight, $dimensions, $invoice_url);

            if (is_wp_error($shipment_data)) {
                return $shipment_data;
            }

            // Crear envío en FedEx
            $result = $this->fedex_api->create_return_shipment($shipment_data);

            if (is_wp_error($result)) {
                return $result;
            }

            // Guardar datos de devolución en el pedido
            $return_data = [
                'tracking_number' => $result['tracking_number'],
                'label_url' => $result['label_url'],
                'return_items' => $return_items,
                'return_reason' => $return_reason,
                'weight' => $weight,
                'dimensions' => $dimensions,
                'invoice_url' => $invoice_url,
                'status' => 'draft',
                'created_at' => current_time('mysql'),
                'created_by' => get_current_user_id(),
            ];

            update_post_meta($order->get_id(), '_fedex_return_data', $return_data);

            // Agregar nota al pedido
            $order->add_order_note(
                sprintf(
                    __('Devolución FedEx creada. Tracking: %s', 'mad-suite'),
                    $result['tracking_number']
                )
            );

            $this->logger->log(sprintf(
                'Devolución creada para pedido #%d - Tracking: %s',
                $order->get_id(),
                $result['tracking_number']
            ));

            return $return_data;

        } catch (Exception $e) {
            $this->logger->error('Error al crear devolución: ' . $e->getMessage());
            return new WP_Error('exception', $e->getMessage());
        }
    }

    /**
     * Preparar datos del envío para FedEx
     */
    private function prepare_shipment_data($order, $return_items, $weight, $dimensions, $invoice_url) {
        // Obtener configuración
        $settings = $this->get_module_settings();

        // Validar configuración del remitente
        if (empty($settings['sender_name']) || empty($settings['sender_address_line1'])) {
            return new WP_Error('missing_sender', __('Configuración del remitente incompleta.', 'mad-suite'));
        }

        // Datos del remitente (donde se devolverán los productos - tu almacén)
        $shipper = [
            'contact' => [
                'personName' => $settings['sender_name'],
                'phoneNumber' => $settings['sender_phone'],
                'companyName' => $settings['sender_company'],
            ],
            'address' => [
                'streetLines' => array_filter([
                    $settings['sender_address_line1'],
                    $settings['sender_address_line2'] ?? '',
                ]),
                'city' => $settings['sender_city'],
                'stateOrProvinceCode' => $settings['sender_state'],
                'postalCode' => $settings['sender_postal_code'],
                'countryCode' => $settings['sender_country'],
            ],
        ];

        // Validar dirección del remitente
        $validation = $this->fedex_api->validate_address($shipper['address']);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Datos del destinatario (cliente que devuelve)
        $recipient = [
            'contact' => [
                'personName' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'phoneNumber' => $order->get_billing_phone(),
                'companyName' => $order->get_billing_company(),
            ],
            'address' => [
                'streetLines' => array_filter([
                    $order->get_billing_address_1(),
                    $order->get_billing_address_2(),
                ]),
                'city' => $order->get_billing_city(),
                'stateOrProvinceCode' => $order->get_billing_state(),
                'postalCode' => $order->get_billing_postcode(),
                'countryCode' => $order->get_billing_country(),
            ],
        ];

        // Preparar paquetes
        $packages = $this->prepare_packages($return_items, $weight, $dimensions, $order);

        if (is_wp_error($packages)) {
            return $packages;
        }

        $shipment_data = [
            'shipper' => $shipper,
            'recipient' => $recipient,
            'packages' => $packages,
            'service_type' => $settings['default_service_type'] ?? 'FEDEX_GROUND',
            'packaging_type' => $settings['default_packaging_type'] ?? 'YOUR_PACKAGING',
        ];

        if (!empty($invoice_url)) {
            $shipment_data['invoice_url'] = $invoice_url;
        }

        return $shipment_data;
    }

    /**
     * Preparar información de paquetes
     */
    private function prepare_packages($return_items, $weight, $dimensions, $order) {
        $settings = $this->get_module_settings();

        // Calcular peso total si no se proporcionó
        if (empty($weight)) {
            $weight = $this->calculate_total_weight($return_items, $order);
        }

        // Si no hay peso, usar un valor por defecto
        if (empty($weight)) {
            $weight = 1; // 1 kg por defecto
        }

        // Dimensiones por defecto si no se proporcionaron
        if (empty($dimensions) || !isset($dimensions['length']) || !isset($dimensions['width']) || !isset($dimensions['height'])) {
            $dimensions = [
                'length' => 30,
                'width' => 30,
                'height' => 30,
            ];
        }

        $weight_unit = $settings['default_weight_unit'] ?? 'KG';
        $dimension_unit = $settings['default_dimension_unit'] ?? 'CM';

        $packages = [
            [
                'weight' => [
                    'units' => $weight_unit,
                    'value' => (float) $weight,
                ],
                'dimensions' => [
                    'length' => (int) $dimensions['length'],
                    'width' => (int) $dimensions['width'],
                    'height' => (int) $dimensions['height'],
                    'units' => $dimension_unit,
                ],
            ],
        ];

        return $packages;
    }

    /**
     * Calcular peso total de los items de devolución
     */
    private function calculate_total_weight($return_items, $order) {
        $total_weight = 0;

        foreach ($return_items as $item_data) {
            $item_id = $item_data['item_id'] ?? 0;
            $quantity = $item_data['quantity'] ?? 1;

            $item = $order->get_item($item_id);
            if (!$item) {
                continue;
            }

            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $weight = $product->get_weight();
            if ($weight) {
                $total_weight += floatval($weight) * $quantity;
            }
        }

        return $total_weight;
    }

    /**
     * Obtener datos de devolución de un pedido
     */
    public function get_order_return_data($order_id) {
        $return_data = get_post_meta($order_id, '_fedex_return_data', true);

        if (empty($return_data)) {
            return false;
        }

        return $return_data;
    }

    /**
     * Actualizar estado de devolución
     */
    public function update_return_status($order_id, $status) {
        $return_data = $this->get_order_return_data($order_id);

        if (!$return_data) {
            return new WP_Error('no_return', __('No se encontró devolución para este pedido.', 'mad-suite'));
        }

        $return_data['status'] = $status;
        $return_data['updated_at'] = current_time('mysql');

        update_post_meta($order_id, '_fedex_return_data', $return_data);

        $this->logger->log(sprintf(
            'Estado de devolución actualizado para pedido #%d: %s',
            $order_id,
            $status
        ));

        return true;
    }

    /**
     * Cancelar devolución
     */
    public function cancel_return($order_id) {
        $return_data = $this->get_order_return_data($order_id);

        if (!$return_data) {
            return new WP_Error('no_return', __('No se encontró devolución para este pedido.', 'mad-suite'));
        }

        // Marcar como cancelada
        $return_data['status'] = 'cancelled';
        $return_data['cancelled_at'] = current_time('mysql');
        $return_data['cancelled_by'] = get_current_user_id();

        update_post_meta($order_id, '_fedex_return_data', $return_data);

        // Agregar nota al pedido
        $order = wc_get_order($order_id);
        if ($order) {
            $order->add_order_note(__('Devolución FedEx cancelada.', 'mad-suite'));
        }

        $this->logger->log(sprintf('Devolución cancelada para pedido #%d', $order_id));

        return true;
    }

    /**
     * Sincronizar estado con FedEx
     */
    public function sync_return_status($order_id) {
        $return_data = $this->get_order_return_data($order_id);

        if (!$return_data) {
            return new WP_Error('no_return', __('No se encontró devolución para este pedido.', 'mad-suite'));
        }

        $tracking_number = $return_data['tracking_number'] ?? '';
        if (empty($tracking_number)) {
            return new WP_Error('no_tracking', __('No se encontró número de seguimiento.', 'mad-suite'));
        }

        // Consultar estado en FedEx
        $tracking_info = $this->fedex_api->track_shipment($tracking_number);

        if (is_wp_error($tracking_info)) {
            return $tracking_info;
        }

        // Actualizar datos de devolución con nueva información
        $return_data['last_status'] = $tracking_info['status'];
        $return_data['last_status_code'] = $tracking_info['status_code'];
        $return_data['last_sync'] = current_time('mysql');

        update_post_meta($order_id, '_fedex_return_data', $return_data);

        $this->logger->log(sprintf(
            'Estado sincronizado para pedido #%d: %s',
            $order_id,
            $tracking_info['status']
        ));

        return $tracking_info;
    }

    /**
     * Obtener configuración del módulo
     */
    private function get_module_settings() {
        $option_key = 'madsuite_fedex-returns_settings';
        return get_option($option_key, []);
    }
}
