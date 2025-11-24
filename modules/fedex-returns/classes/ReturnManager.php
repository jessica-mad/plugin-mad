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
    public function create_return($order, $return_items, $return_reason, $weight, $dimensions, $invoice_path = '', $original_shipment = []) {
        if (!$order || !$order instanceof WC_Order) {
            return new WP_Error('invalid_order', __('Pedido inválido.', 'mad-suite'));
        }

        // Verificar que no exista ya una devolución
        $existing_return = $this->get_order_return_data($order->get_id());
        if ($existing_return) {
            return new WP_Error('return_exists', __('Ya existe una devolución para este pedido.', 'mad-suite'));
        }

        try {
            $doc_id = '';
            $invoice_url = '';

            // Subir factura a FedEx si existe
            if (!empty($invoice_path) && file_exists($invoice_path)) {
                $this->logger->log(sprintf(
                    'Subiendo factura a FedEx para pedido #%d',
                    $order->get_id()
                ));

                $upload_result = $this->fedex_api->upload_document($invoice_path, 'COMMERCIAL_INVOICE', 'ETDPreShipment');

                if (!is_wp_error($upload_result)) {
                    $doc_id = $upload_result['doc_id'];
                    $invoice_url = $invoice_path; // Guardar ruta local también

                    $this->logger->log(sprintf(
                        'Factura subida exitosamente. Doc ID: %s',
                        $doc_id
                    ));
                } else {
                    $this->logger->warning(sprintf(
                        'No se pudo subir factura a FedEx: %s',
                        $upload_result->get_error_message()
                    ));
                }
            }

            // Preparar datos del envío
            $shipment_data = $this->prepare_shipment_data($order, $return_items, $weight, $dimensions, $doc_id, $original_shipment);

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
                'doc_id' => $doc_id,
                'original_shipment' => $original_shipment,
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
    private function prepare_shipment_data($order, $return_items, $weight, $dimensions, $doc_id, $original_shipment) {
        // Obtener configuración
        $settings = $this->get_module_settings();

        // Validar configuración del remitente
        if (empty($settings['sender_name']) || empty($settings['sender_address_line1']) || empty($settings['sender_phone'])) {
            return new WP_Error('missing_sender', __('Configuración del remitente incompleta. Asegúrate de completar nombre, dirección y teléfono en MAD Plugins > FedEx Returns > Información del Remitente.', 'mad-suite'));
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

        // Detectar si es envío internacional
        $is_international = $shipper['address']['countryCode'] !== $recipient['address']['countryCode'];

        // Preparar paquetes (sin customs)
        $packages = $this->prepare_packages($return_items, $weight, $dimensions, $order, $is_international);

        if (is_wp_error($packages)) {
            return $packages;
        }

        $shipment_data = [
            'shipper' => $shipper,
            'recipient' => $recipient,
            'packages' => $packages,
            'service_type' => $settings['default_service_type'] ?? 'FEDEX_GROUND',
            'packaging_type' => $settings['default_packaging_type'] ?? 'YOUR_PACKAGING',
            'is_international' => $is_international,
        ];

        // Agregar información de customs solo si es internacional
        if ($is_international) {
            $customs_detail = $this->prepare_customs_detail($return_items, $order);
            if ($customs_detail) {
                $shipment_data['customs_detail'] = $customs_detail;
                $this->logger->log('Envío internacional detectado - agregando información de customs');
            }
        } else {
            $this->logger->log('Envío doméstico detectado - no se requiere información de customs');
        }

        // Agregar document ID si existe
        if (!empty($doc_id)) {
            $shipment_data['doc_id'] = $doc_id;
        }

        // Agregar información del envío original si existe
        if (!empty($original_shipment['tracking_code'])) {
            $shipment_data['original_tracking'] = $original_shipment['tracking_code'];
            $shipment_data['original_dated'] = $original_shipment['dated'] ?? '';
            $shipment_data['original_dua'] = $original_shipment['dua_number'] ?? '';
        }

        return $shipment_data;
    }

    /**
     * Preparar información de paquetes
     */
    private function prepare_packages($return_items, $weight, $dimensions, $order, $is_international = false) {
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

        // NO agregar customsClearanceDetail aquí - se manejará en el nivel del shipment si es internacional
        // Los packages solo contienen peso y dimensiones

        return $packages;
    }

    /**
     * Preparar información de customs (solo para envíos internacionales)
     */
    private function prepare_customs_detail($return_items, $order) {
        // Extraer HS codes de los productos
        $commodities = $this->extract_hs_codes($return_items, $order);

        if (empty($commodities)) {
            return null;
        }

        return [
            'dutiesPayment' => [
                'paymentType' => 'SENDER'
            ],
            'commodities' => $commodities
        ];
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
     * Extraer HS codes de las variaciones de producto
     */
    private function extract_hs_codes($return_items, $order) {
        $commodities = [];

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

            // Obtener HS code del meta de la variación
            $hs_code = '';
            if ($product->is_type('variation')) {
                // Buscar en meta de la variación
                $variation_id = $product->get_id();
                $hs_code = get_post_meta($variation_id, 'hs_code', true);
            }

            // Si no es variación, buscar en el producto padre
            if (empty($hs_code)) {
                $parent_id = $product->get_parent_id();
                if ($parent_id) {
                    $hs_code = get_post_meta($parent_id, 'hs_code', true);
                } else {
                    $hs_code = get_post_meta($product->get_id(), 'hs_code', true);
                }
            }

            // Solo agregar si tiene HS code
            if (!empty($hs_code)) {
                $weight = $product->get_weight() ?: 0.1; // Peso mínimo si no está definido
                $value = floatval($item->get_total()) / $item->get_quantity() * $quantity;

                $commodities[] = [
                    'description' => $product->get_name(),
                    'harmonizedCode' => $hs_code,
                    'quantity' => (int) $quantity,
                    'quantityUnits' => 'PCS',
                    'weight' => [
                        'units' => $this->get_module_settings()['default_weight_unit'] ?? 'KG',
                        'value' => (float) $weight * $quantity
                    ],
                    'customsValue' => [
                        'amount' => (float) $value,
                        'currency' => $order->get_currency()
                    ],
                    'countryOfManufacture' => $order->get_billing_country(),
                ];

                $this->logger->log(sprintf(
                    'HS Code encontrado para producto %s (Variación ID: %d): %s',
                    $product->get_name(),
                    $product->get_id(),
                    $hs_code
                ));
            } else {
                $this->logger->warning(sprintf(
                    'No se encontró HS Code para producto %s (Variación ID: %d)',
                    $product->get_name(),
                    $product->get_id()
                ));
            }
        }

        return $commodities;
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
