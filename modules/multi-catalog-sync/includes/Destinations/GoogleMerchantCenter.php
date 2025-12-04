<?php
namespace MAD_Suite\MultiCatalogSync\Destinations;

use MAD_Suite\MultiCatalogSync\Core\Logger;
use MAD_Suite\MultiCatalogSync\Core\ValidationHelper;

if ( ! defined('ABSPATH') ) exit;

/**
 * GoogleMerchantCenter
 * Implementation for Google Merchant API (new API, replaces deprecated Content API)
 * Documentation: https://developers.google.com/merchant/api
 */
class GoogleMerchantCenter implements DestinationInterface {

    private $merchant_id;
    private $service_account_json;
    private $access_token;
    private $logger;
    private $api_base_url = 'https://merchantapi.googleapis.com/products/v1beta';

    public function __construct($settings = []){
        $this->merchant_id = isset($settings['google_merchant_id']) ? $settings['google_merchant_id'] : '';
        $this->service_account_json = isset($settings['google_service_account_json']) ? $settings['google_service_account_json'] : '';
        $this->logger = new Logger();
    }

    /**
     * Get destination name
     */
    public function get_name(){
        return 'google';
    }

    /**
     * Get destination display name
     */
    public function get_display_name(){
        return 'Google Merchant Center';
    }

    /**
     * Check if destination is properly configured
     */
    public function is_connected(){
        if (empty($this->merchant_id) || empty($this->service_account_json)) {
            return false;
        }

        // Try to get access token
        $token = $this->get_access_token();
        return !empty($token);
    }

    /**
     * Sync a single product
     */
    public function sync_product($product_data){
        // Validate product data
        $validation = $this->validate($product_data);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => implode(', ', $validation['errors']),
            ];
        }

        // Get access token
        $token = $this->get_access_token();
        if (!$token) {
            return [
                'success' => false,
                'message' => __('No se pudo obtener token de acceso de Google', 'mad-suite'),
            ];
        }

        // Format product data for new Merchant API
        $api_product = $this->format_product_for_api($product_data);

        // Make API request to new Merchant API
        // New endpoint format: accounts/{merchantId}/products:insert
        $endpoint = sprintf('%s/accounts/%s/products:insert', $this->api_base_url, $this->merchant_id);

        $response = $this->make_api_request('POST', $endpoint, $api_product, $token);

        if ($response['success']) {
            $this->logger->info(sprintf('Product %s synced to Google Merchant Center', $product_data['id']));
            return [
                'success' => true,
                'message' => __('Producto sincronizado exitosamente', 'mad-suite'),
            ];
        } else {
            $this->logger->log_product_error($product_data['id'], 'google', $response['message']);
            return [
                'success' => false,
                'message' => $response['message'],
            ];
        }
    }

    /**
     * Sync multiple products in batch
     */
    public function sync_batch($products_data){
        $synced = 0;
        $failed = 0;
        $errors = [];

        // Google supports batch requests but for simplicity, we'll sync one by one
        // In production, you'd want to use the batch API for better performance

        foreach ($products_data as $product_data) {
            $result = $this->sync_product($product_data);

            if ($result['success']) {
                $synced++;
            } else {
                $failed++;
                $errors[] = [
                    'product_id' => $product_data['id'],
                    'message' => $result['message'],
                ];
            }
        }

        return [
            'success' => $failed === 0,
            'synced' => $synced,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Update only stock/availability
     */
    public function update_stock($product_id, $availability){
        $token = $this->get_access_token();
        if (!$token) {
            return [
                'success' => false,
                'message' => __('No se pudo obtener token de acceso', 'mad-suite'),
            ];
        }

        // Use inventory API for faster stock updates
        // Note: Inventory API endpoint structure may differ in new Merchant API
        $endpoint = sprintf(
            '%s/accounts/%s/products/%s',
            $this->api_base_url,
            $this->merchant_id,
            $product_id
        );

        $data = [
            'availability' => $availability,
        ];

        $response = $this->make_api_request('POST', $endpoint, $data, $token);

        return [
            'success' => $response['success'],
            'message' => $response['message'],
        ];
    }

    /**
     * Delete a product
     */
    public function delete_product($product_id){
        $token = $this->get_access_token();
        if (!$token) {
            return [
                'success' => false,
                'message' => __('No se pudo obtener token de acceso', 'mad-suite'),
            ];
        }

        // New Merchant API delete endpoint
        $endpoint = sprintf(
            '%s/accounts/%s/products/%s',
            $this->api_base_url,
            $this->merchant_id,
            $product_id
        );

        $response = $this->make_api_request('DELETE', $endpoint, null, $token);

        return [
            'success' => $response['success'],
            'message' => $response['message'],
        ];
    }

    /**
     * Get product count
     */
    public function get_product_count(){
        // This would require calling the list API
        // For now, return cached value if available
        $counts = get_option('mcs_destination_counts', []);
        return isset($counts['google']['items']) ? (int) $counts['google']['items'] : 0;
    }

    /**
     * Validate product data
     */
    public function validate($product_data){
        return ValidationHelper::validate($product_data, 'google');
    }

    /**
     * Get API rate limits
     */
    public function get_rate_limits(){
        return [
            'requests_per_second' => 10, // Google allows ~10 requests/second
            'batch_size' => 1000, // Max 1000 items per batch request
        ];
    }

    /**
     * Get OAuth2 access token from service account
     */
    private function get_access_token(){
        // Check cache first
        $cached_token = get_transient('mcs_google_access_token');
        if ($cached_token) {
            return $cached_token;
        }

        // Parse service account JSON
        $service_account = json_decode($this->service_account_json, true);
        if (!$service_account || !isset($service_account['private_key'])) {
            $this->logger->error('Invalid service account JSON');
            return false;
        }

        // Create JWT
        $now = time();
        $jwt_header = base64_encode(json_encode([
            'alg' => 'RS256',
            'typ' => 'JWT',
        ]));

        $jwt_claim = base64_encode(json_encode([
            'iss' => $service_account['client_email'],
            'scope' => 'https://www.googleapis.com/auth/content',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now,
        ]));

        $jwt_signature = '';
        $data = $jwt_header . '.' . $jwt_claim;

        openssl_sign($data, $jwt_signature, $service_account['private_key'], 'SHA256');
        $jwt_signature = base64_encode($jwt_signature);

        $jwt = $data . '.' . $jwt_signature;

        // Request access token
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ],
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('Failed to get access token: ' . $response->get_error_message());
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['access_token'])) {
            $this->logger->error('No access token in response');
            return false;
        }

        // Cache token for 50 minutes (expires in 60)
        set_transient('mcs_google_access_token', $body['access_token'], 50 * MINUTE_IN_SECONDS);

        return $body['access_token'];
    }

    /**
     * Make API request to Google
     */
    private function make_api_request($method, $endpoint, $data, $token){
        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ($data && $method !== 'GET') {
            $args['body'] = json_encode($data);
        }

        $this->logger->log_api_request('google', $method, $endpoint, $data);

        $response = wp_remote_request($endpoint, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->log_api_response('google', 0, $error_message);
            return [
                'success' => false,
                'message' => $error_message,
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $this->logger->log_api_response('google', $status_code, $body);

        if ($status_code >= 200 && $status_code < 300) {
            return [
                'success' => true,
                'message' => __('Operación exitosa', 'mad-suite'),
                'data' => json_decode($body, true),
            ];
        } else {
            $error_data = json_decode($body, true);
            $error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : __('Error desconocido', 'mad-suite');

            return [
                'success' => false,
                'message' => $error_message,
            ];
        }
    }

    /**
     * Format product data for new Merchant API
     * New API uses a different structure with 'productId', 'dataSource', and 'attributes'
     */
    private function format_product_for_api($product_data){
        // Extract price and currency
        $price_value = str_replace(' ', '', $product_data['price']);
        $currency = substr($product_data['price'], -3);

        // New Merchant API structure
        $api_product = [
            'dataSource' => 'accounts/' . $this->merchant_id . '/dataSources/channel',
            'productId' => 'online:es:ES:' . $product_data['id'], // channel:contentLanguage:targetCountry:offerId
            'attributes' => [
                'offerId' => $product_data['id'],
                'title' => $product_data['title'],
                'description' => $product_data['description'],
                'link' => $product_data['link'],
                'imageLink' => $product_data['image_link'],
                'contentLanguage' => 'es', // TODO: Make configurable
                'targetCountry' => 'ES', // TODO: Make configurable
                'channel' => 'online',
                'availability' => $product_data['availability'],
                'condition' => $product_data['condition'],
                'price' => [
                    'amountMicros' => (int)(floatval($price_value) * 1000000), // New API uses micros
                    'currencyCode' => $currency,
                ],
            ],
        ];

        // Optional fields (all go inside 'attributes')
        if (!empty($product_data['brand'])) {
            $api_product['attributes']['brand'] = $product_data['brand'];
        }

        if (!empty($product_data['gtin'])) {
            $api_product['attributes']['gtin'] = $product_data['gtin'];
        }

        if (!empty($product_data['mpn'])) {
            $api_product['attributes']['mpn'] = $product_data['mpn'];
        }

        if (!empty($product_data['google_product_category'])) {
            $api_product['attributes']['googleProductCategory'] = $product_data['google_product_category'];
        }

        if (!empty($product_data['product_type'])) {
            $api_product['attributes']['productTypes'] = [$product_data['product_type']]; // Note: now an array
        }

        if (!empty($product_data['item_group_id'])) {
            $api_product['attributes']['itemGroupId'] = $product_data['item_group_id'];
        }

        // Variation attributes
        if (!empty($product_data['color'])) {
            $api_product['attributes']['color'] = $product_data['color'];
        }

        if (!empty($product_data['size'])) {
            $api_product['attributes']['sizes'] = [$product_data['size']]; // Note: now an array
        }

        // Additional images
        if (!empty($product_data['additional_image_link'])) {
            $api_product['attributes']['additionalImageLinks'] = explode(',', $product_data['additional_image_link']);
        }

        // Sale price
        if (!empty($product_data['sale_price'])) {
            $sale_price_value = str_replace(' ', '', $product_data['sale_price']);
            $sale_currency = substr($product_data['sale_price'], -3);

            $api_product['attributes']['salePrice'] = [
                'amountMicros' => (int)(floatval($sale_price_value) * 1000000),
                'currencyCode' => $sale_currency,
            ];

            if (!empty($product_data['sale_price_effective_date'])) {
                $api_product['attributes']['salePriceEffectiveDate'] = $product_data['sale_price_effective_date'];
            }
        }

        // Custom labels
        for ($i = 0; $i <= 4; $i++) {
            $key = 'custom_label_' . $i;
            if (!empty($product_data[$key])) {
                $api_product['attributes']['customLabel' . $i] = $product_data[$key];
            }
        }

        return $api_product;
    }
}
