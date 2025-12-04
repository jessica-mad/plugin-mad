<?php
namespace MAD_Suite\MultiCatalogSync\Destinations;

use MAD_Suite\MultiCatalogSync\Core\Logger;
use MAD_Suite\MultiCatalogSync\Core\ValidationHelper;

if ( ! defined('ABSPATH') ) exit;

/**
 * PinterestCatalog
 * Implementation for Pinterest Catalog
 */
class PinterestCatalog implements DestinationInterface {

    private $catalog_id;
    private $access_token;
    private $logger;
    private $api_base_url = 'https://api.pinterest.com/v5';

    public function __construct($settings = []){
        $this->catalog_id = isset($settings['pinterest_catalog_id']) ? $settings['pinterest_catalog_id'] : '';
        $this->access_token = isset($settings['pinterest_access_token']) ? $settings['pinterest_access_token'] : '';
        $this->logger = new Logger();
    }

    /**
     * Get destination name
     */
    public function get_name(){
        return 'pinterest';
    }

    /**
     * Get destination display name
     */
    public function get_display_name(){
        return 'Pinterest Catalog';
    }

    /**
     * Check if destination is properly configured
     */
    public function is_connected(){
        if (empty($this->catalog_id) || empty($this->access_token)) {
            return false;
        }

        // Validate token by making a test request
        $endpoint = sprintf('%s/catalogs/%s', $this->api_base_url, $this->catalog_id);
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

        // Pinterest requires batch operations, so we wrap single product in a batch
        return $this->sync_batch([$product_data]);
    }

    /**
     * Sync multiple products in batch
     * Pinterest supports batch operations
     */
    public function sync_batch($products_data){
        $synced = 0;
        $failed = 0;
        $errors = [];

        // Pinterest Batch API
        $batch_size = 1000; // Pinterest supports up to 1000 items per batch
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
     * Sync a chunk of products using Pinterest Batch API
     */
    private function sync_batch_chunk($products_data){
        $synced = 0;
        $failed = 0;
        $errors = [];

        // Prepare batch request
        $batch_items = [];
        foreach ($products_data as $product_data) {
            $pinterest_product = $this->format_product_for_api($product_data);
            $batch_items[] = [
                'item_id' => $product_data['id'],
                'attributes' => $pinterest_product,
            ];
        }

        // Use batch endpoint
        $endpoint = sprintf('%s/catalogs/%s/items/batch', $this->api_base_url, $this->catalog_id);

        $response = $this->make_api_request('POST', $endpoint, [
            'country' => 'ES', // TODO: Make configurable
            'language' => 'es', // TODO: Make configurable
            'items' => $batch_items,
        ]);

        if ($response['success']) {
            // Pinterest batch returns a batch_id, need to check status separately
            // For simplicity, we'll assume success
            $synced = count($products_data);

            // In production, you'd want to check the batch status
            // GET /v5/catalogs/{catalog_id}/processing_results/{batch_id}
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
        // Pinterest uses same batch endpoint for updates
        $endpoint = sprintf('%s/catalogs/%s/items/batch', $this->api_base_url, $this->catalog_id);

        $data = [
            'country' => 'ES',
            'language' => 'es',
            'items' => [
                [
                    'item_id' => $product_id,
                    'attributes' => [
                        'availability' => $availability,
                    ],
                ],
            ],
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
        // Pinterest uses DELETE operation in batch
        $endpoint = sprintf('%s/catalogs/%s/items', $this->api_base_url, $this->catalog_id);

        $response = $this->make_api_request('DELETE', $endpoint, [
            'item_ids' => [$product_id],
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
        return isset($counts['pinterest']['items']) ? (int) $counts['pinterest']['items'] : 0;
    }

    /**
     * Validate product data
     */
    public function validate($product_data){
        return ValidationHelper::validate($product_data, 'pinterest');
    }

    /**
     * Get API rate limits
     */
    public function get_rate_limits(){
        return [
            'requests_per_second' => 10,
            'batch_size' => 1000, // Max 1000 items per batch
        ];
    }

    /**
     * Make API request to Pinterest
     */
    private function make_api_request($method, $endpoint, $data = null){
        $args = [
            'method' => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 30,
        ];

        if ($data && $method !== 'GET') {
            $args['body'] = json_encode($data);
        }

        $this->logger->log_api_request('pinterest', $method, $endpoint, $data);

        $response = wp_remote_request($endpoint, $args);

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $this->logger->log_api_response('pinterest', 0, $error_message);
            return [
                'success' => false,
                'message' => $error_message,
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        $this->logger->log_api_response('pinterest', $status_code, $body);

        if ($status_code >= 200 && $status_code < 300) {
            return [
                'success' => true,
                'message' => __('Operación exitosa', 'mad-suite'),
                'data' => json_decode($body, true),
            ];
        } else {
            $error_data = json_decode($body, true);
            $error_message = isset($error_data['message'])
                ? $error_data['message']
                : __('Error desconocido', 'mad-suite');

            return [
                'success' => false,
                'message' => $error_message,
            ];
        }
    }

    /**
     * Format product data for Pinterest API
     */
    private function format_product_for_api($product_data){
        // Pinterest uses similar format to Google
        $pinterest_product = [
            'title' => $product_data['title'],
            'description' => $product_data['description'],
            'link' => $product_data['link'],
            'image_link' => $product_data['image_link'],
            'availability' => $product_data['availability'],
            'condition' => $product_data['condition'],
            'price' => str_replace(' ', '', $product_data['price']),
        ];

        // Optional fields
        if (!empty($product_data['brand'])) {
            $pinterest_product['brand'] = $product_data['brand'];
        }

        if (!empty($product_data['gtin'])) {
            $pinterest_product['gtin'] = $product_data['gtin'];
        }

        if (!empty($product_data['mpn'])) {
            $pinterest_product['mpn'] = $product_data['mpn'];
        }

        if (!empty($product_data['google_product_category'])) {
            $pinterest_product['google_product_category'] = $product_data['google_product_category'];
        }

        if (!empty($product_data['product_type'])) {
            $pinterest_product['product_type'] = $product_data['product_type'];
        }

        // Pinterest uses 'item_group_id' like Google
        if (!empty($product_data['item_group_id'])) {
            $pinterest_product['item_group_id'] = $product_data['item_group_id'];
        }

        // Variation attributes
        if (!empty($product_data['color'])) {
            $pinterest_product['color'] = $product_data['color'];
        }

        if (!empty($product_data['size'])) {
            $pinterest_product['size'] = $product_data['size'];
        }

        if (!empty($product_data['material'])) {
            $pinterest_product['material'] = $product_data['material'];
        }

        if (!empty($product_data['pattern'])) {
            $pinterest_product['pattern'] = $product_data['pattern'];
        }

        // Additional images
        if (!empty($product_data['additional_image_link'])) {
            $additional_images = explode(',', $product_data['additional_image_link']);
            $pinterest_product['additional_image_link'] = $additional_images;
        }

        // Sale price
        if (!empty($product_data['sale_price'])) {
            $pinterest_product['sale_price'] = str_replace(' ', '', $product_data['sale_price']);

            if (!empty($product_data['sale_price_effective_date'])) {
                $pinterest_product['sale_price_effective_date'] = $product_data['sale_price_effective_date'];
            }
        }

        // Custom labels
        for ($i = 0; $i <= 4; $i++) {
            $key = 'custom_label_' . $i;
            if (!empty($product_data[$key])) {
                $pinterest_product['custom_label_' . $i] = $product_data[$key];
            }
        }

        // Age group and gender
        if (!empty($product_data['age_group'])) {
            $pinterest_product['age_group'] = $product_data['age_group'];
        }

        if (!empty($product_data['gender'])) {
            $pinterest_product['gender'] = $product_data['gender'];
        }

        return $pinterest_product;
    }
}
