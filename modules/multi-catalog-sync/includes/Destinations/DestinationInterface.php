<?php
namespace MAD_Suite\MultiCatalogSync\Destinations;

if ( ! defined('ABSPATH') ) exit;

/**
 * DestinationInterface
 * Interface that all catalog destinations must implement
 */
interface DestinationInterface {

    /**
     * Get destination name
     *
     * @return string Destination name (e.g., 'google', 'facebook', 'pinterest')
     */
    public function get_name();

    /**
     * Get destination display name
     *
     * @return string Display name (e.g., 'Google Merchant Center')
     */
    public function get_display_name();

    /**
     * Check if destination is properly configured and authenticated
     *
     * @return bool True if connected and ready
     */
    public function is_connected();

    /**
     * Sync a single product to the destination
     *
     * @param array $product_data Product feed data
     * @return array ['success' => bool, 'message' => string]
     */
    public function sync_product($product_data);

    /**
     * Sync multiple products in a batch
     *
     * @param array $products_data Array of product feed data
     * @return array ['success' => bool, 'synced' => int, 'failed' => int, 'errors' => array]
     */
    public function sync_batch($products_data);

    /**
     * Update only stock/availability for a product
     *
     * @param string $product_id Product ID
     * @param string $availability Availability status
     * @return array ['success' => bool, 'message' => string]
     */
    public function update_stock($product_id, $availability);

    /**
     * Delete a product from the destination
     *
     * @param string $product_id Product ID
     * @return array ['success' => bool, 'message' => string]
     */
    public function delete_product($product_id);

    /**
     * Get product count in destination
     *
     * @return int Number of products
     */
    public function get_product_count();

    /**
     * Validate product data before syncing
     *
     * @param array $product_data Product feed data
     * @return array ['valid' => bool, 'errors' => array]
     */
    public function validate($product_data);

    /**
     * Get API rate limits info
     *
     * @return array ['requests_per_second' => int, 'batch_size' => int]
     */
    public function get_rate_limits();
}
