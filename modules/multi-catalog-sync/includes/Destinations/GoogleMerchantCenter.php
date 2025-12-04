<?php
namespace MAD_Suite\MultiCatalogSync\Destinations;

use MAD_Suite\MultiCatalogSync\Core\Logger;
use MAD_Suite\MultiCatalogSync\Core\ValidationHelper;

if ( ! defined('ABSPATH') ) exit;

/**
 * GoogleMerchantCenter
 * Implementation for Google Merchant Center Content API
 */
class GoogleMerchantCenter implements DestinationInterface {

    private $merchant_id;
    private $service_account_json;
    private $access_token;
    private $logger;
    private $api_base_url = 'https://shoppingcontent.googleapis.com/content/v2.1';

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

        // Format product data for Google API
        $api_product = $this->format_product_for_api($product_data);

        // Make API request
        $endpoint = sprintf('%s/%s/products', $this->api_base_url, $this->merchant_id);

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
        $endpoint = sprintf(
            '%s/%s/inventory/%s/set',
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

        $endpoint = sprintf(
            '%s/%s/products/%s',
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
     * Format product data for Google API
     */
    private function format_product_for_api($product_data){
        $api_product = [
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
                'value' => str_replace(' ', '', $product_data['price']),
                'currency' => substr($product_data['price'], -3),
            ],
        ];

        // Optional fields
        if (!empty($product_data['brand'])) {
            $api_product['brand'] = $product_data['brand'];
        }

        if (!empty($product_data['gtin'])) {
            $api_product['gtin'] = $product_data['gtin'];
        }

        if (!empty($product_data['mpn'])) {
            $api_product['mpn'] = $product_data['mpn'];
        }

        if (!empty($product_data['google_product_category'])) {
            $api_product['googleProductCategory'] = $product_data['google_product_category'];
        }

        if (!empty($product_data['product_type'])) {
            $api_product['productType'] = $product_data['product_type'];
        }

        if (!empty($product_data['item_group_id'])) {
            $api_product['itemGroupId'] = $product_data['item_group_id'];
        }

        // Variation attributes
        if (!empty($product_data['color'])) {
            $api_product['color'] = $product_data['color'];
        }

        if (!empty($product_data['size'])) {
            $api_product['size'] = $product_data['size'];
        }

        // Additional images
        if (!empty($product_data['additional_image_link'])) {
            $api_product['additionalImageLinks'] = explode(',', $product_data['additional_image_link']);
        }

        // Sale price
        if (!empty($product_data['sale_price'])) {
            $api_product['salePrice'] = [
                'value' => str_replace(' ', '', $product_data['sale_price']),
                'currency' => substr($product_data['sale_price'], -3),
            ];

            if (!empty($product_data['sale_price_effective_date'])) {
                $api_product['salePriceEffectiveDate'] = $product_data['sale_price_effective_date'];
            }
        }

        // Custom labels
        for ($i = 0; $i <= 4; $i++) {
            $key = 'custom_label_' . $i;
            if (!empty($product_data[$key])) {
                $api_product['customLabel' . $i] = $product_data[$key];
            }
        }

        return $api_product;
    }
}
