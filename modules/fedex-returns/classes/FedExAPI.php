<?php
/**
 * Clase para manejar la API de FedEx
 */

if (!defined('ABSPATH')) exit;

class MAD_FedEx_API {
    private $settings;
    private $logger;
    private $api_url;
    private $access_token;
    private $token_expires_at;

    // Endpoints de la API de FedEx
    const ENDPOINT_AUTH = '/oauth/token';
    const ENDPOINT_SHIP = '/ship/v1/shipments';
    const ENDPOINT_TRACK = '/track/v1/trackingnumbers';
    const ENDPOINT_RATE = '/rate/v1/rates/quotes';
    const ENDPOINT_UPLOAD_DOCS = '/document/v1/etds/upload';

    public function __construct($settings, $logger) {
        $this->settings = $settings;
        $this->logger = $logger;

        // Determinar URL de API según el ambiente
        $is_production = isset($settings['fedex_environment']) && $settings['fedex_environment'] === 'production';
        $this->api_url = $is_production
            ? 'https://apis.fedex.com'
            : 'https://apis-sandbox.fedex.com';
    }

    /**
     * Obtener token de acceso OAuth
     */
    private function get_access_token() {
        // Verificar si tenemos un token válido en cache
        if ($this->access_token && $this->token_expires_at && time() < $this->token_expires_at) {
            return $this->access_token;
        }

        $api_key = $this->settings['fedex_api_key'] ?? '';
        $api_secret = $this->settings['fedex_api_secret'] ?? '';

        if (empty($api_key) || empty($api_secret)) {
            return new WP_Error('missing_credentials', __('Credenciales de FedEx no configuradas.', 'mad-suite'));
        }

        $url = $this->api_url . self::ENDPOINT_AUTH;

        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type' => 'client_credentials',
                'client_id' => $api_key,
                'client_secret' => $api_secret,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('Error al obtener token de FedEx: ' . $response->get_error_message());
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            $error_message = $body['errors'][0]['message'] ?? __('Error desconocido al autenticar con FedEx.', 'mad-suite');
            $error_code = $body['errors'][0]['code'] ?? 'UNKNOWN';

            // Log detallado para debugging
            $this->logger->error(sprintf(
                'Error de autenticación FedEx [%d]: %s (Código: %s) - API Key: %s... - Ambiente: %s',
                $status_code,
                $error_message,
                $error_code,
                substr($api_key, 0, 8),
                $this->settings['fedex_environment'] ?? 'test'
            ));

            // Log de toda la respuesta para debugging
            $this->logger->error('Respuesta completa de FedEx: ' . wp_json_encode($body));

            return new WP_Error('auth_failed', sprintf(
                __('Error de autenticación FedEx: %s (Código: %s)', 'mad-suite'),
                $error_message,
                $error_code
            ));
        }

        $this->access_token = $body['access_token'];
        $this->token_expires_at = time() + ($body['expires_in'] ?? 3600) - 60; // 60s de margen

        if ($this->settings['log_api_requests'] ?? false) {
            $this->logger->log_api_call(self::ENDPOINT_AUTH, ['grant_type' => 'client_credentials'], $body);
        }

        return $this->access_token;
    }

    /**
     * Realizar llamada a la API de FedEx
     */
    private function make_request($endpoint, $method = 'POST', $data = []) {
        $token = $this->get_access_token();

        if (is_wp_error($token)) {
            return $token;
        }

        $url = $this->api_url . $endpoint;

        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'X-locale' => 'es_MX',
            ],
            'timeout' => 45,
        ];

        if (!empty($data)) {
            $args['body'] = json_encode($data);
        }

        if ($this->settings['log_api_requests'] ?? false) {
            $this->logger->debug('Request a FedEx: ' . $endpoint);
            $this->logger->debug('Data: ' . json_encode($data, JSON_PRETTY_PRINT));
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->logger->error('Error en llamada a FedEx API: ' . $response->get_error_message());
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $status_code = wp_remote_retrieve_response_code($response);

        if ($this->settings['log_api_requests'] ?? false) {
            $this->logger->log_api_call($endpoint, $data, $body, $status_code >= 200 && $status_code < 300);
        }

        if ($status_code < 200 || $status_code >= 300) {
            $error_message = $this->extract_error_message($body);
            $this->logger->error(sprintf('Error FedEx (Status %d): %s', $status_code, $error_message));
            return new WP_Error('api_error', $error_message);
        }

        return $body;
    }

    /**
     * Extraer mensaje de error de respuesta de FedEx
     */
    private function extract_error_message($body) {
        if (isset($body['errors']) && is_array($body['errors']) && !empty($body['errors'])) {
            return $body['errors'][0]['message'] ?? __('Error desconocido de FedEx.', 'mad-suite');
        }

        if (isset($body['message'])) {
            return $body['message'];
        }

        return __('Error desconocido de FedEx.', 'mad-suite');
    }

    /**
     * Crear envío de devolución en FedEx
     */
    public function create_return_shipment($shipment_data) {
        // Validar datos requeridos
        $required_fields = ['shipper', 'recipient', 'packages'];
        foreach ($required_fields as $field) {
            if (empty($shipment_data[$field])) {
                return new WP_Error('missing_data', sprintf(__('Falta el campo requerido: %s', 'mad-suite'), $field));
            }
        }

        // Construir payload para FedEx
        $payload = [
            'labelResponseOptions' => 'URL_ONLY',
            'requestedShipment' => [
                'shipper' => $shipment_data['shipper'],
                'recipients' => [$shipment_data['recipient']],
                'shipDatestamp' => date('Y-m-d'),
                'serviceType' => $shipment_data['service_type'] ?? $this->settings['default_service_type'],
                'packagingType' => $shipment_data['packaging_type'] ?? $this->settings['default_packaging_type'],
                'pickupType' => 'USE_SCHEDULED_PICKUP',
                'blockInsightVisibility' => false,
                'shippingChargesPayment' => [
                    'paymentType' => 'SENDER',
                ],
                'labelSpecification' => [
                    'imageType' => 'PDF',
                    'labelStockType' => 'PAPER_85X11_TOP_HALF_LABEL',
                ],
                'requestedPackageLineItems' => $shipment_data['packages'],
            ],
            'accountNumber' => [
                'value' => $this->settings['fedex_account_number'] ?? '',
            ],
        ];

        // Agregar ETD (Electronic Trade Documents) si hay document ID
        if (!empty($shipment_data['doc_id'])) {
            $payload['requestedShipment']['shipmentSpecialServices'] = [
                'specialServiceTypes' => ['ELECTRONIC_TRADE_DOCUMENTS'],
                'etdDetail' => [
                    'attachedDocuments' => [
                        [
                            'documentId' => $shipment_data['doc_id'],
                            'documentType' => 'COMMERCIAL_INVOICE'
                        ]
                    ]
                ]
            ];
        }

        // Agregar información del envío original si existe (para devoluciones)
        if (!empty($shipment_data['original_tracking'])) {
            if (!isset($payload['requestedShipment']['shipmentSpecialServices'])) {
                $payload['requestedShipment']['shipmentSpecialServices'] = [
                    'specialServiceTypes' => []
                ];
            }

            // Agregar servicio de devolución
            if (!in_array('RETURN_SHIPMENT', $payload['requestedShipment']['shipmentSpecialServices']['specialServiceTypes'])) {
                $payload['requestedShipment']['shipmentSpecialServices']['specialServiceTypes'][] = 'RETURN_SHIPMENT';
            }

            $payload['requestedShipment']['shipmentSpecialServices']['returnShipmentDetail'] = [
                'returnType' => 'PRINT_RETURN_LABEL'
            ];
        }

        $response = $this->make_request(self::ENDPOINT_SHIP, 'POST', $payload);

        if (is_wp_error($response)) {
            return $response;
        }

        // Extraer información relevante de la respuesta
        $output = $response['output'] ?? [];
        $transactionShipments = $output['transactionShipments'] ?? [];

        if (empty($transactionShipments)) {
            return new WP_Error('no_shipment', __('No se pudo crear el envío en FedEx.', 'mad-suite'));
        }

        $shipment = $transactionShipments[0];
        $masterTrackingNumber = $shipment['masterTrackingNumber'] ?? '';
        $pieceResponses = $shipment['pieceResponses'] ?? [];

        $result = [
            'tracking_number' => $masterTrackingNumber,
            'label_url' => '',
            'raw_response' => $response,
        ];

        // Obtener URL de la etiqueta
        if (!empty($pieceResponses)) {
            $label = $pieceResponses[0]['packageDocuments'][0] ?? null;
            if ($label && isset($label['url'])) {
                $result['label_url'] = $label['url'];
            }
        }

        return $result;
    }

    /**
     * Rastrear envío
     */
    public function track_shipment($tracking_number) {
        if (empty($tracking_number)) {
            return new WP_Error('missing_tracking', __('Número de seguimiento requerido.', 'mad-suite'));
        }

        $payload = [
            'includeDetailedScans' => true,
            'trackingInfo' => [
                [
                    'trackingNumberInfo' => [
                        'trackingNumber' => $tracking_number,
                    ],
                ],
            ],
        ];

        $response = $this->make_request(self::ENDPOINT_TRACK, 'POST', $payload);

        if (is_wp_error($response)) {
            return $response;
        }

        // Extraer información de seguimiento
        $output = $response['output'] ?? [];
        $completeTrackResults = $output['completeTrackResults'] ?? [];

        if (empty($completeTrackResults)) {
            return new WP_Error('no_tracking', __('No se encontró información de seguimiento.', 'mad-suite'));
        }

        $trackResult = $completeTrackResults[0]['trackResults'][0] ?? [];

        return [
            'tracking_number' => $tracking_number,
            'status' => $trackResult['latestStatusDetail']['description'] ?? __('Desconocido', 'mad-suite'),
            'status_code' => $trackResult['latestStatusDetail']['code'] ?? '',
            'delivery_date' => $trackResult['estimatedDeliveryTimeWindow']['window']['ends'] ?? '',
            'events' => $trackResult['scanEvents'] ?? [],
            'raw_response' => $response,
        ];
    }

    /**
     * Obtener cotización de envío
     */
    public function get_rate_quote($rate_data) {
        $payload = [
            'accountNumber' => [
                'value' => $this->settings['fedex_account_number'] ?? '',
            ],
            'requestedShipment' => [
                'shipper' => $rate_data['shipper'],
                'recipient' => $rate_data['recipient'],
                'pickupType' => 'USE_SCHEDULED_PICKUP',
                'serviceType' => $rate_data['service_type'] ?? $this->settings['default_service_type'],
                'rateRequestType' => ['LIST'],
                'requestedPackageLineItems' => $rate_data['packages'],
            ],
        ];

        $response = $this->make_request(self::ENDPOINT_RATE, 'POST', $payload);

        if (is_wp_error($response)) {
            return $response;
        }

        $output = $response['output'] ?? [];
        $rateReplyDetails = $output['rateReplyDetails'] ?? [];

        if (empty($rateReplyDetails)) {
            return new WP_Error('no_rates', __('No se encontraron tarifas disponibles.', 'mad-suite'));
        }

        return $rateReplyDetails;
    }

    /**
     * Subir documento a FedEx (ETD - Electronic Trade Documents)
     */
    public function upload_document($file_path, $document_type = 'COMMERCIAL_INVOICE', $workflow = 'ETDPreShipment') {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return new WP_Error('file_not_found', __('Archivo no encontrado o no legible.', 'mad-suite'));
        }

        // Verificar tamaño del archivo (máximo 5MB)
        $file_size = filesize($file_path);
        $max_size = 5 * 1024 * 1024; // 5MB

        if ($file_size > $max_size) {
            return new WP_Error('file_too_large', sprintf(
                __('El archivo es demasiado grande (%s). Máximo permitido: 5MB', 'mad-suite'),
                size_format($file_size)
            ));
        }

        // Leer y codificar el archivo en Base64
        $file_content = file_get_contents($file_path);
        if ($file_content === false) {
            return new WP_Error('file_read_error', __('No se pudo leer el archivo.', 'mad-suite'));
        }

        $file_base64 = base64_encode($file_content);
        $filename = basename($file_path);

        // Preparar payload para FedEx
        $payload = [
            'workflowName' => $workflow,
            'name' => pathinfo($filename, PATHINFO_FILENAME),
            'contentType' => 'application/pdf',
            'meta' => [
                'imageType' => 'PDF',
                'imageIndex' => 'IMAGE_1'
            ],
            'rules' => [
                'workflowName' => $workflow,
                'carrierCode' => 'FDXE'
            ],
            'document' => [
                'name' => $filename,
                'contentType' => 'application/pdf',
                'content' => $file_base64
            ],
            'shipDocumentType' => $document_type
        ];

        $this->logger->log(sprintf(
            'Subiendo documento a FedEx: %s (%s)',
            $filename,
            size_format($file_size)
        ));

        $response = $this->make_request(self::ENDPOINT_UPLOAD_DOCS, 'POST', $payload);

        if (is_wp_error($response)) {
            $this->logger->error('Error al subir documento a FedEx: ' . $response->get_error_message());
            return $response;
        }

        // Extraer document ID de la respuesta
        $output = $response['output'] ?? [];
        $doc_id = $output['documentId'] ?? $output['docId'] ?? '';

        if (empty($doc_id)) {
            $this->logger->error('FedEx no devolvió document ID');
            return new WP_Error('no_doc_id', __('FedEx no devolvió el ID del documento.', 'mad-suite'));
        }

        $this->logger->log(sprintf(
            'Documento subido exitosamente a FedEx. Document ID: %s',
            $doc_id
        ));

        return [
            'success' => true,
            'doc_id' => $doc_id,
            'filename' => $filename,
            'size' => $file_size,
        ];
    }

    /**
     * Probar conexión con FedEx
     */
    public function test_connection() {
        $token = $this->get_access_token();

        if (is_wp_error($token)) {
            return $token;
        }

        return [
            'success' => true,
            'message' => __('Conexión exitosa con FedEx API.', 'mad-suite'),
            'environment' => $this->settings['fedex_environment'] ?? 'test',
            'api_url' => $this->api_url,
        ];
    }

    /**
     * Validar dirección
     */
    public function validate_address($address_data) {
        // FedEx tiene un endpoint de validación de direcciones
        // Por ahora, haremos una validación básica
        $required_fields = ['streetLines', 'city', 'postalCode', 'countryCode'];
        foreach ($required_fields as $field) {
            if (empty($address_data[$field])) {
                return new WP_Error('invalid_address', sprintf(__('Falta el campo de dirección: %s', 'mad-suite'), $field));
            }
        }

        return true;
    }
}
