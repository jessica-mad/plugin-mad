<?php
namespace MAD_Suite\MultiCatalogSync\Destinations;

use MAD_Suite\MultiCatalogSync\Core\Logger;
use MAD_Suite\MultiCatalogSync\Core\ValidationHelper;

if ( ! defined('ABSPATH') ) exit;

/**
 * FacebookCatalog
 * Implementation for Facebook Catalog (Commerce Manager)
 */
class FacebookCatalog implements DestinationInterface {

    private $catalog_id;
    private $access_token;
    private $logger;
    private $api_base_url = 'https://graph.facebook.com/v21.0';

    public function __construct($settings = []){
        $this->catalog_id = isset($settings['facebook_catalog_id']) ? $settings['facebook_catalog_id'] : '';
        $this->access_token = isset($settings['facebook_access_token']) ? $settings['facebook_access_token'] : '';
        $this->logger = new Logger();
    }

    /**
     * Get destination name
     */
    public function get_name(){
        return 'facebook';
    }

    /**
     * Get destination display name
     */
    public function get_display_name(){
        return 'Facebook Catalog';
    }

    /**
     * Check if destination is properly configured
     */
    public function is_connected(){
        if (empty($this->catalog_id) || empty($this->access_token)) {
            return false;
        }

        // Validate token by making a test request
        $endpoint = sprintf('%s/%s', $this->api_base_url, $this->catalog_id);
        $response = $this->make_api_request('GET', $endpoint);

        return $response['success'];
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

        // Format product data for Facebook API
        $fb_product = $this->format_product_for_api($product_data);

        // Make API request (Facebook uses batch or items endpoint)
        $endpoint = sprintf('%s/%s/products', $this->api_base_url, $this->catalog_id);

        $response = $this->make_api_request('POST', $endpoint, $fb_product);

        if ($response['success']) {
            $this->logger->info(sprintf('Product %s synced to Facebook Catalog', $product_data['id']));
            return [
                'success' => true,
                'message' => __('Producto sincronizado exitosamente', 'mad-suite'),
            ];
        } else {
            $this->logger->log_product_error($product_data['id'], 'facebook', $response['message']);
            return [
                'success' => false,
                'message' => $response['message'],
            ];
        }
    }

    /**
     * Sync multiple products in batch
     * Facebook supports up to 5000 items per batch
     */
    public function sync_batch($products_data){
        $synced = 0;
        $failed = 0;
        $errors = [];

        // Facebook Batch API
        $batch_size = 100; // Process in chunks
        $chunks = array_chunk($products_data, $batch_size);

        foreach ($chunks as $chunk) {
            $result = $this->sync_batch_chunk($chunk);
            $synced += $result['synced'];
            $failed += $result['failed'];
            $errors = array_merge($errors, $result['errors']);
        }

        return [
            'success' => $failed === 0,
            'synced' => $synced,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Sync a chunk of products using Facebook Batch API
     */
    private function sync_batch_chunk($products_data){
        $synced = 0;
        $failed = 0;
        $errors = [];

        // Prepare batch request
        $batch_data = [];
        foreach ($products_data as $product_data) {
            $fb_product = $this->format_product_for_api($product_data);
            $batch_data[] = [
                'method' => 'UPDATE',
                'data' => $fb_product,
            ];
        }

        // Use batch endpoint
        $endpoint = sprintf('%s/%s/batch', $this->api_base_url, $this->catalog_id);

        $response = $this->make_api_request('POST', $endpoint, [
            'requests' => $batch_data,
        ]);

        if ($response['success']) {
            // Parse batch response
            $handles = isset($response['data']['handles']) ? $response['data']['handles'] : [];

            foreach ($handles as $index => $handle) {
                if (isset($handle['id'])) {
                    $synced++;
                } else {
                    $failed++;
                    $product_id = isset($products_data[$index]['id']) ? $products_data[$index]['id'] : 'unknown';
                    $errors[] = [
                        'product_id' => $product_id,
                        'message' => isset($handle['error']) ? $handle['error'] : __('Error desconocido', 'mad-suite'),
                    ];
                }
            }
        } else {
            // Entire batch failed
            $failed = count($products_data);
            foreach ($products_data as $product_data) {
                $errors[] = [
                    'product_id' => $product_data['id'],
                    'message' => $response['message'],
                ];
            }
        }

        return [
            'synced' => $synced,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Update only stock/availability
     */
    public function update_stock($product_id, $availability){
        // Facebook uses same endpoint for updates
        $endpoint = sprintf('%s/%s/products', $this->api_base_url, $this->catalog_id);

        $data = [
            'retailer_id' => $product_id,
            'availability' => $availability,
        ];

        $response = $this->make_api_request('POST', $endpoint, $data);

        return [
            'success' => $response['success'],
            'message' => $response['message'],
        ];
    }

    /**
     * Delete a product
     */
    public function delete_product($product_id){
        $endpoint = sprintf(
            '%s/%s/products',
            $this->api_base_url,
            $this->catalog_id
        );

        // Facebook uses batch delete
        $response = $this->make_api_request('POST', $endpoint, [
            'requests' => [
                [
                    'method' => 'DELETE',
                    'retailer_id' => $product_id,
                ]
            ]
        ]);

        return [
            'success' => $response['success'],
            'message' => $response['message'],
        ];
    }

    /**
     * Get product count
     */
    public function get_product_count(){
        // Return cached value
        $counts = get_option('mcs_destination_counts', []);
        return isset($counts['facebook']['items']) ? (int) $counts['facebook']['items'] : 0;
    }

    /**
     * Validate product data
     */
    public function validate($product_data){
        return ValidationHelper::validate($product_data, 'facebook');
    }

    /**
     * Get API rate limits
     */
    public function get_rate_limits(){
        return [
            'requests_per_second' => 200, // Facebook is more generous
            'batch_size' => 5000, // Max 5000 items per batch
        ];
    }

    /**
     * Make API request to Facebook
     */
    private function make_api_request($method, $endpoint, $data = null){
        $url = $endpoint;

        // Add access token to URL
        $separator = (strpos($url, '?') === false) ? '?' : '&';
        $url .= $separator . 'access_token=' . urlencode($this->access_token);

        $args = [
            'method' => $method,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ($data && $method !== 'GET') {
            $args['body'] = json_encode($data);
        }

        $this->logger->log_api_request('facebook', $method, $endpoint, $data);

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->log_api_response('facebook', 0, $error_message);
            return [
                'success' => false,
                'message' => $error_message,
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $this->logger->log_api_response('facebook', $status_code, $body);

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
     * Format product data for Facebook API
     */
    private function format_product_for_api($product_data){
        // Extract and format price correctly
        // Input: "145.00 EUR" or "145,00 EUR"
        $price_string = str_replace(' ', '', $product_data['price']);
        $price_string = str_replace(',', '.', $price_string); // Normalize decimal separator

        // Extract currency (last 3 characters) and value
        $currency = substr($product_data['price'], -3);
        $price_value = str_replace($currency, '', $price_string);
        $price_value = trim($price_value);

        // Format as "AMOUNT CURRENCY" (Facebook expects space between)
        $formatted_price = sprintf('%s %s', $price_value, $currency);

        // Normalize availability to Facebook format
        // Facebook expects: "in stock", "out of stock", "preorder", "available for order", "discontinued"
        $availability_map = [
            'IN_STOCK' => 'in stock',
            'IN STOCK' => 'in stock',
            'in stock' => 'in stock',
            'OUT_OF_STOCK' => 'out of stock',
            'OUT OF STOCK' => 'out of stock',
            'out of stock' => 'out of stock',
            'PREORDER' => 'preorder',
            'preorder' => 'preorder',
            'BACKORDER' => 'available for order',
            'backorder' => 'available for order',
        ];
        $availability = isset($availability_map[$product_data['availability']])
            ? $availability_map[$product_data['availability']]
            : 'in stock';

        // Normalize condition to Facebook format (lowercase)
        // Facebook expects: "new", "refurbished", "used"
        $condition = strtolower($product_data['condition']);

        $fb_product = [
            'retailer_id' => $product_data['id'],
            'title' => $product_data['title'],
            'description' => $product_data['description'],
            'url' => $product_data['link'],
            'image_url' => $product_data['image_link'],
            'availability' => $availability,
            'condition' => $condition,
            'price' => $formatted_price,
        ];

        // Optional fields
        if (!empty($product_data['brand'])) {
            $fb_product['brand'] = $product_data['brand'];
        }

        if (!empty($product_data['gtin'])) {
            $fb_product['gtin'] = $product_data['gtin'];
        }

        if (!empty($product_data['mpn'])) {
            $fb_product['mpn'] = $product_data['mpn'];
        }

        if (!empty($product_data['google_product_category'])) {
            $fb_product['google_product_category'] = $product_data['google_product_category'];
        }

        if (!empty($product_data['product_type'])) {
            $fb_product['product_type'] = $product_data['product_type'];
        }

        // Facebook uses 'item_group_id' (same as Google)
        if (!empty($product_data['item_group_id'])) {
            $fb_product['item_group_id'] = $product_data['item_group_id'];
        }

        // Variation attributes
        if (!empty($product_data['color'])) {
            $fb_product['color'] = $product_data['color'];
        }

        if (!empty($product_data['size'])) {
            $fb_product['size'] = $product_data['size'];
        }

        if (!empty($product_data['material'])) {
            $fb_product['material'] = $product_data['material'];
        }

        if (!empty($product_data['pattern'])) {
            $fb_product['pattern'] = $product_data['pattern'];
        }

        // Additional images
        if (!empty($product_data['additional_image_link'])) {
            $additional_images = explode(',', $product_data['additional_image_link']);
            $fb_product['additional_image_urls'] = $additional_images;
        }

        // Sale price (same format as regular price)
        if (!empty($product_data['sale_price'])) {
            $sale_price_string = str_replace(' ', '', $product_data['sale_price']);
            $sale_price_string = str_replace(',', '.', $sale_price_string);

            $sale_currency = substr($product_data['sale_price'], -3);
            $sale_price_value = str_replace($sale_currency, '', $sale_price_string);
            $sale_price_value = trim($sale_price_value);

            $fb_product['sale_price'] = sprintf('%s %s', $sale_price_value, $sale_currency);

            if (!empty($product_data['sale_price_effective_date'])) {
                $fb_product['sale_price_effective_date'] = $product_data['sale_price_effective_date'];
            }
        }

        // Custom labels
        for ($i = 0; $i <= 4; $i++) {
            $key = 'custom_label_' . $i;
            if (!empty($product_data[$key])) {
                $fb_product['custom_label_' . $i] = $product_data[$key];
            }
        }

        // Age group and gender
        if (!empty($product_data['age_group'])) {
            $fb_product['age_group'] = $product_data['age_group'];
        }

        if (!empty($product_data['gender'])) {
            $fb_product['gender'] = $product_data['gender'];
        }

        return $fb_product;
    }
}
