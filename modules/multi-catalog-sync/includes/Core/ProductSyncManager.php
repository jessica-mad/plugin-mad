<?php
namespace MAD_Suite\MultiCatalogSync\Core;

use MAD_Suite\MultiCatalogSync\Destinations\GoogleMerchantCenter;
use MAD_Suite\MultiCatalogSync\Destinations\FacebookCatalog;
use MAD_Suite\MultiCatalogSync\Destinations\PinterestCatalog;

if ( ! defined('ABSPATH') ) exit;

/**
 * ProductSyncManager
 * Orchestrates product synchronization to multiple destinations
 */
class ProductSyncManager {

    private $settings;
    private $destinations = [];
    private $feed_generator;
    private $logger;

    public function __construct($settings = []){
        $this->settings = $settings;
        $this->logger = new Logger();
        $this->feed_generator = new ProductFeedGenerator($settings);

        // Initialize enabled destinations
        $this->init_destinations();
    }

    /**
     * Initialize destination instances based on settings
     */
    private function init_destinations(){
        // Google Merchant Center
        if (!empty($this->settings['google_enabled'])) {
            $this->destinations['google'] = new GoogleMerchantCenter($this->settings);
        }

        // Facebook Catalog
        if (!empty($this->settings['facebook_enabled'])) {
            $this->destinations['facebook'] = new FacebookCatalog($this->settings);
        }

        // Pinterest Catalog
        if (!empty($this->settings['pinterest_enabled'])) {
            $this->destinations['pinterest'] = new PinterestCatalog($this->settings);
        }
    }

    /**
     * Get all initialized destinations
     */
    public function get_destinations(){
        return $this->destinations;
    }

    /**
     * Get a specific destination instance
     */
    public function get_destination($name){
        return isset($this->destinations[$name]) ? $this->destinations[$name] : null;
    }

    /**
     * Sync a single product to all enabled destinations
     *
     * @param int $product_id Product ID
     * @return array Results by destination
     */
    public function sync_product($product_id){
        $results = [];

        foreach ($this->destinations as $name => $destination) {
            $results[$name] = $this->sync_product_to_destination($product_id, $name);
        }

        // Update last sync timestamp
        update_post_meta($product_id, '_mcs_last_sync', current_time('timestamp'));

        return $results;
    }

    /**
     * Sync a product to a specific destination
     *
     * @param int $product_id Product ID
     * @param string $destination_name Destination name (google, facebook, pinterest)
     * @return array Result
     */
    public function sync_product_to_destination($product_id, $destination_name){
        $destination = $this->get_destination($destination_name);

        if (!$destination) {
            return [
                'success' => false,
                'message' => sprintf(__('Destino %s no disponible', 'mad-suite'), $destination_name),
            ];
        }

        // Generate feed data for this destination
        $product_data = $this->feed_generator->generate_product_feed($product_id, $destination_name);

        if (!$product_data) {
            return [
                'success' => false,
                'message' => __('Producto no disponible para sincronización', 'mad-suite'),
            ];
        }

        // Handle variable products (returns array of variations)
        if (isset($product_data[0]) && is_array($product_data[0])) {
            // Sync all variations
            $total = count($product_data);
            $synced = 0;
            $errors = [];

            foreach ($product_data as $variation_data) {
                $result = $destination->sync_product($variation_data);
                if ($result['success']) {
                    $synced++;
                } else {
                    $errors[] = $result['message'];
                }
            }

            return [
                'success' => count($errors) === 0,
                'message' => sprintf(
                    __('%d de %d variaciones sincronizadas', 'mad-suite'),
                    $synced,
                    $total
                ),
                'synced' => $synced,
                'failed' => $total - $synced,
                'errors' => $errors,
            ];
        } else {
            // Sync single product
            return $destination->sync_product($product_data);
        }
    }

    /**
     * Sync multiple products to all destinations
     *
     * @param array $product_ids Array of product IDs
     * @param string|null $destination_name Specific destination or null for all
     * @return array Results summary
     */
    public function sync_batch($product_ids, $destination_name = null){
        $start_time = microtime(true);

        $destinations_to_sync = $destination_name
            ? [$destination_name => $this->get_destination($destination_name)]
            : $this->destinations;

        $results = [];

        foreach ($destinations_to_sync as $name => $destination) {
            if (!$destination) {
                continue;
            }

            $this->logger->log_sync_start($name, count($product_ids));

            // Generate feed data for all products
            $products_data = $this->feed_generator->generate_bulk_feed($product_ids, $name);

            // Sync to destination
            $result = $destination->sync_batch($products_data);

            $duration = microtime(true) - $start_time;
            $this->logger->log_sync_complete($name, $result['synced'], $result['failed'], $duration);

            $results[$name] = $result;

            // Update destination counts
            $this->update_destination_counts($name, $result);
        }

        // Update global last sync timestamp
        update_option('mcs_last_full_sync', current_time('timestamp'));

        return $results;
    }

    /**
     * Sync all products in the store
     *
     * @param string|null $destination_name Specific destination or null for all
     * @return array Results summary
     */
    public function sync_all($destination_name = null){
        // Get all product IDs that should be synced
        $product_ids = $this->get_syncable_product_ids();

        return $this->sync_batch($product_ids, $destination_name);
    }

    /**
     * Update stock for a product across all destinations
     *
     * @param int $product_id Product ID
     * @param string $availability Availability status
     * @return array Results by destination
     */
    public function update_stock($product_id, $availability){
        $results = [];

        foreach ($this->destinations as $name => $destination) {
            $results[$name] = $destination->update_stock($product_id, $availability);
        }

        return $results;
    }

    /**
     * Delete a product from all destinations
     *
     * @param int $product_id Product ID
     * @return array Results by destination
     */
    public function delete_product($product_id){
        $results = [];

        foreach ($this->destinations as $name => $destination) {
            $results[$name] = $destination->delete_product($product_id);
        }

        return $results;
    }

    /**
     * Process sync queue
     * Syncs all products in the queue
     *
     * @return array Results summary
     */
    public function process_queue(){
        $queue = get_option('mcs_sync_queue', []);

        if (empty($queue)) {
            return [
                'success' => true,
                'message' => __('Cola vacía', 'mad-suite'),
                'processed' => 0,
            ];
        }

        // Sync queued products
        $results = $this->sync_batch($queue);

        // Clear queue
        delete_option('mcs_sync_queue');

        return [
            'success' => true,
            'message' => sprintf(__('%d productos procesados', 'mad-suite'), count($queue)),
            'processed' => count($queue),
            'results' => $results,
        ];
    }

    /**
     * Get all product IDs that should be synced
     *
     * @return array Product IDs
     */
    private function get_syncable_product_ids(){
        $args = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_mcs_sync_enabled',
                    'value' => '1',
                ],
                [
                    'key' => '_mcs_sync_enabled',
                    'compare' => 'NOT EXISTS',
                ],
            ],
        ];

        $query = new \WP_Query($args);
        return $query->posts;
    }

    /**
     * Update destination statistics
     *
     * @param string $destination_name Destination name
     * @param array $result Sync result
     */
    private function update_destination_counts($destination_name, $result){
        $counts = get_option('mcs_destination_counts', []);

        if (!isset($counts[$destination_name])) {
            $counts[$destination_name] = [
                'items' => 0,
                'errors' => 0,
            ];
        }

        // Update counts
        $counts[$destination_name]['items'] = isset($result['synced']) ? $result['synced'] : 0;
        $counts[$destination_name]['errors'] = isset($result['failed']) ? $result['failed'] : 0;

        update_option('mcs_destination_counts', $counts);
    }

    /**
     * Get sync status for all destinations
     *
     * @return array Status information
     */
    public function get_sync_status(){
        $status = [
            'destinations' => [],
            'summary' => [],
            'last_sync' => get_option('mcs_last_full_sync', 0),
        ];

        // Get status for each destination
        foreach ($this->destinations as $name => $destination) {
            $status['destinations'][$name] = [
                'connected' => $destination->is_connected(),
                'items' => $destination->get_product_count(),
                'errors' => $this->get_destination_error_count($name),
            ];
        }

        // Summary statistics
        $status['summary'] = [
            'total_products' => wp_count_posts('product')->publish,
            'total_variations' => $this->count_variations(),
            'synced_count' => $this->get_synced_count(),
            'excluded_count' => $this->get_excluded_count(),
            'error_count' => $this->get_total_error_count(),
        ];

        return $status;
    }

    /**
     * Get error count for a specific destination
     */
    private function get_destination_error_count($destination){
        $errors = get_option('mcs_sync_errors', []);
        $count = 0;

        foreach ($errors as $error) {
            if (isset($error['destination']) && $error['destination'] === $destination) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get total error count
     */
    private function get_total_error_count(){
        $errors = get_option('mcs_sync_errors', []);
        return count($errors);
    }

    /**
     * Count variations
     */
    private function count_variations(){
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product_variation' AND post_status = 'publish'"
        );
    }

    /**
     * Get synced count
     */
    private function get_synced_count(){
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
            WHERE meta_key = '_mcs_sync_enabled' AND meta_value = '1'"
        );
    }

    /**
     * Get excluded count
     */
    private function get_excluded_count(){
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
            WHERE meta_key = '_mcs_sync_enabled' AND meta_value = '0'"
        );
    }

    /**
     * Check if automatic sync is enabled
     */
    public function is_auto_sync_enabled(){
        return !empty($this->settings['sync_schedule']);
    }

    /**
     * Get sync schedule in hours
     */
    public function get_sync_schedule(){
        return isset($this->settings['sync_schedule']) ? (int) $this->settings['sync_schedule'] : 6;
    }
}
