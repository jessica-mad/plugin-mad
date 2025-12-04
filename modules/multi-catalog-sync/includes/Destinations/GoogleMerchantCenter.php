<?php
namespace MAD_Suite\MultiCatalogSync\Destinations;

use MAD_Suite\MultiCatalogSync\Core\Logger;
use MAD_Suite\MultiCatalogSync\Core\ValidationHelper;

if ( ! defined('ABSPATH') ) exit;

/**
 * GoogleMerchantCenter
 * Implementation for Google Merchant API v1beta
 *
 * Uses the new Merchant API (merchantapi.googleapis.com) which replaces
 * the deprecated Content API for Shopping.
 *
 * Documentation: https://developers.google.com/merchant/api
 */
class GoogleMerchantCenter implements DestinationInterface {

    private $merchant_id;
    private $data_source_id;
    private $feed_label;
    private $service_account_json;
    private $access_token;
    private $logger;
    private $api_base_url = 'https://merchantapi.googleapis.com/products/v1beta';

    public function __construct($settings = []){
        $this->merchant_id = isset($settings['google_merchant_id']) ? $settings['google_merchant_id'] : '';
        $this->service_account_json = isset($settings['google_service_account_json']) ? $settings['google_service_account_json'] : '';
        $this->data_source_id = isset($settings['google_data_source_id']) ? $settings['google_data_source_id'] : '';
        $this->feed_label = isset($settings['google_feed_label']) ? $settings['google_feed_label'] : 'ES';
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

        // Format product data for Merchant API
        $api_product = $this->format_product_for_api($product_data);

        // Make API request to Merchant API
        // Endpoint: POST /products/v1beta/accounts/{account}/productInputs:insert
        $endpoint = sprintf('%s/accounts/%s/productInputs:insert', $this->api_base_url, $this->merchant_id);

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

        // Merchant API supports batch requests but for simplicity, we'll sync one by one
        // TODO: Implement proper batch API for better performance

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
        // For now, we'll do a full product update
        // TODO: Investigate if Merchant API has a specific inventory update endpoint
        $this->logger->warning('Stock update called for product ' . $product_id . ' - doing full sync instead');
        return ['success' => false, 'message' => 'Use full sync for now'];
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

        // Merchant API delete endpoint
        // DELETE /products/v1beta/accounts/{account}/products/{product}
        $product_name = sprintf('accounts/%s/products/%s', $this->merchant_id, $product_id);
        $endpoint = sprintf('%s/%s', $this->api_base_url, $product_name);

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
            'requests_per_second' => 10,
            'batch_size' => 1000,
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
     * Make API request to Google Merchant API
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
            $error_message = isset($error_data['error']['message'])
                ? $error_data['error']['message']
                : __('Error desconocido', 'mad-suite');

            return [
                'success' => false,
                'message' => $error_message,
            ];
        }
    }

    /**
     * Format product data for Merchant API v1beta
     *
     * New structure uses ProductInput with nested attributes
     */
    private function format_product_for_api($product_data){
        // Extract and format price
        $price_value = str_replace(' ', '', $product_data['price']);
        $price_value = str_replace(',', '.', $price_value); // Handle decimal separator
        $currency = substr($product_data['price'], -3);

        // Convert price to micros (multiply by 1,000,000)
        $price_micros = (int)(floatval($price_value) * 1000000);

        // Convert availability to uppercase format
        $availability = strtoupper(str_replace(' ', '_', $product_data['availability']));
        // IN_STOCK, OUT_OF_STOCK, PREORDER, BACKORDER

        // Convert condition to uppercase
        $condition = strtoupper($product_data['condition']);
        // NEW, USED, REFURBISHED

        // Build attributes object
        $attributes = [
            'title' => $product_data['title'],
            'description' => $product_data['description'],
            'link' => $product_data['link'],
            'imageLink' => $product_data['image_link'],
            'availability' => $availability,
            'condition' => $condition,
            'price' => [
                'amountMicros' => strval($price_micros), // Must be string
                'currencyCode' => $currency,
            ],
        ];

        // Optional: Brand
        if (!empty($product_data['brand'])) {
            $attributes['brand'] = $product_data['brand'];
        }

        // Optional: GTIN
        if (!empty($product_data['gtin'])) {
            $attributes['gtin'] = $product_data['gtin'];
        }

        // Optional: MPN
        if (!empty($product_data['mpn'])) {
            $attributes['mpn'] = $product_data['mpn'];
        }

        // Optional: Google Product Category
        if (!empty($product_data['google_product_category'])) {
            $attributes['googleProductCategory'] = $product_data['google_product_category'];
        }

        // Optional: Product Type (as array)
        if (!empty($product_data['product_type'])) {
            $attributes['productTypes'] = [$product_data['product_type']];
        }

        // Optional: Item Group ID (for variations)
        if (!empty($product_data['item_group_id'])) {
            $attributes['itemGroupId'] = $product_data['item_group_id'];
        }

        // Variation attributes
        if (!empty($product_data['color'])) {
            $attributes['color'] = $product_data['color'];
        }

        // Size as array (required format for Merchant API)
        if (!empty($product_data['size'])) {
            $attributes['sizes'] = [$product_data['size']];
        }

        // Optional: Material
        if (!empty($product_data['material'])) {
            $attributes['material'] = $product_data['material'];
        }

        // Optional: Pattern
        if (!empty($product_data['pattern'])) {
            $attributes['pattern'] = $product_data['pattern'];
        }

        // Additional images (as array)
        if (!empty($product_data['additional_image_link'])) {
            $attributes['additionalImageLinks'] = explode(',', $product_data['additional_image_link']);
        }

        // Sale price
        if (!empty($product_data['sale_price'])) {
            $sale_price_value = str_replace(' ', '', $product_data['sale_price']);
            $sale_price_value = str_replace(',', '.', $sale_price_value);
            $sale_price_micros = (int)(floatval($sale_price_value) * 1000000);

            $attributes['salePrice'] = [
                'amountMicros' => strval($sale_price_micros),
                'currencyCode' => $currency,
            ];

            if (!empty($product_data['sale_price_effective_date'])) {
                $attributes['salePriceEffectiveDate'] = $product_data['sale_price_effective_date'];
            }
        }

        // Custom labels
        for ($i = 0; $i <= 4; $i++) {
            $key = 'custom_label_' . $i;
            if (!empty($product_data[$key])) {
                $attributes['customLabel' . $i] = $product_data[$key];
            }
        }

        // Build the complete ProductInput structure
        $product_input = [
            'productInput' => [
                // Data source reference
                'dataSource' => sprintf('accounts/%s/dataSources/%s', $this->merchant_id, $this->data_source_id),

                // Feed configuration
                'feedLabel' => $this->feed_label,
                'contentLanguage' => 'es',
                'offerId' => $product_data['id'],
                'channel' => 'ONLINE',

                // All product attributes
                'attributes' => $attributes,
            ],
        ];

        return $product_input;
    }
}
