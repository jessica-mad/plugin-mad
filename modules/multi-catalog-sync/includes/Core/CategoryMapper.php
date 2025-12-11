<?php
namespace MAD_Suite\MultiCatalogSync\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * CategoryMapper
 * Maps WooCommerce categories to Google Product Taxonomy
 */
class CategoryMapper {

    private $taxonomy_file;
    private $taxonomy_cache = null;
    private $cache_key = 'mcs_google_taxonomy_cache';

    public function __construct(){
        $this->taxonomy_file = dirname(__DIR__, 2) . '/data/google-taxonomy.json';
    }

    /**
     * Get Google category for a WooCommerce product
     *
     * @param int $product_id Product ID
     * @return array ['id' => string, 'path' => string] or null
     */
    public function get_product_category($product_id){
        $product = wc_get_product($product_id);
        if (!$product) {
            return null;
        }

        // Get product categories (hierarchical)
        $category_ids = $product->get_category_ids();
        if (empty($category_ids)) {
            return null;
        }

        // Check each category for Google mapping (starting with most specific)
        foreach ($category_ids as $category_id) {
            $google_category = $this->get_category_mapping($category_id);
            if ($google_category) {
                return $google_category;
            }

            // Check parent categories recursively
            $parent_category = $this->get_parent_category_mapping($category_id);
            if ($parent_category) {
                return $parent_category;
            }
        }

        return null;
    }

    /**
     * Get Google category mapping for a WooCommerce category
     *
     * @param int $category_id WooCommerce category term ID
     * @return array|null
     */
    public function get_category_mapping($category_id){
        $google_category_path = get_term_meta($category_id, '_mcs_google_category', true);
        $google_category_id = get_term_meta($category_id, '_mcs_google_category_id', true);

        if (empty($google_category_path) || empty($google_category_id)) {
            return null;
        }

        return [
            'id' => $google_category_id,
            'path' => $google_category_path,
        ];
    }

    /**
     * Recursively check parent categories for Google mapping
     *
     * @param int $category_id WooCommerce category term ID
     * @return array|null
     */
    private function get_parent_category_mapping($category_id){
        $term = get_term($category_id, 'product_cat');
        if (!$term || is_wp_error($term) || $term->parent == 0) {
            return null;
        }

        // Check parent category
        $parent_mapping = $this->get_category_mapping($term->parent);
        if ($parent_mapping) {
            return $parent_mapping;
        }

        // Recursively check parent's parent
        return $this->get_parent_category_mapping($term->parent);
    }

    /**
     * Search Google taxonomy
     *
     * @param string $search_term Search query
     * @param int $limit Maximum results to return
     * @return array Array of matching categories
     */
    public function search_taxonomy($search_term, $limit = 20){
        $taxonomy = $this->load_taxonomy();
        if (empty($taxonomy)) {
            return [];
        }

        $search_term = mb_strtolower($search_term);
        $results = [];

        foreach ($taxonomy as $id => $path) {
            $path_lower = mb_strtolower($path);

            // Check if search term is in category path
            if (strpos($path_lower, $search_term) !== false) {
                $results[] = [
                    'value' => $id,
                    'label' => $path,
                    'path' => $path,
                ];

                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        // Sort results by relevance (exact matches first)
        usort($results, function($a, $b) use ($search_term) {
            $a_lower = mb_strtolower($a['label']);
            $b_lower = mb_strtolower($b['label']);

            // Exact match at the end (most specific category)
            $a_end = substr($a_lower, -strlen($search_term)) === $search_term;
            $b_end = substr($b_lower, -strlen($search_term)) === $search_term;

            if ($a_end && !$b_end) return -1;
            if (!$a_end && $b_end) return 1;

            // Starts with search term
            $a_start = strpos($a_lower, $search_term) === 0;
            $b_start = strpos($b_lower, $search_term) === 0;

            if ($a_start && !$b_start) return -1;
            if (!$a_start && $b_start) return 1;

            // Shorter path is more relevant
            return strlen($a['path']) - strlen($b['path']);
        });

        return $results;
    }

    /**
     * Load Google taxonomy from file
     *
     * @return array Associative array [id => path]
     */
    private function load_taxonomy(){
        // Try cache first
        if ($this->taxonomy_cache !== null) {
            return $this->taxonomy_cache;
        }

        // Try persistent cache
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            $this->taxonomy_cache = $cached;
            return $cached;
        }

        // Load from file
        if (!file_exists($this->taxonomy_file)) {
            return [];
        }

        $contents = file_get_contents($this->taxonomy_file);
        $taxonomy = json_decode($contents, true);

        if (!is_array($taxonomy)) {
            return [];
        }

        // Cache for 24 hours
        set_transient($this->cache_key, $taxonomy, DAY_IN_SECONDS);
        $this->taxonomy_cache = $taxonomy;

        return $taxonomy;
    }

    /**
     * Clear taxonomy cache
     */
    public function clear_cache(){
        delete_transient($this->cache_key);
        $this->taxonomy_cache = null;
    }

    /**
     * Check if taxonomy file exists and is up to date
     *
     * @return array ['exists' => bool, 'count' => int, 'last_modified' => int|null, 'needs_update' => bool]
     */
    public function get_taxonomy_status(){
        $exists = file_exists($this->taxonomy_file);
        $last_modified = $exists ? filemtime($this->taxonomy_file) : null;

        $taxonomy = $this->load_taxonomy();
        $count = count($taxonomy);

        // Check if needs update (taxonomy file should have 6000+ categories)
        $needs_update = $count < 5000;

        // Check last update timestamp from options
        $last_check = get_option('mcs_taxonomy_last_check', 0);
        $days_since_check = ($last_check > 0) ? ((current_time('timestamp') - $last_check) / DAY_IN_SECONDS) : 999;

        // Warn if not checked in 90 days
        $needs_update = $needs_update || ($days_since_check > 90);

        return [
            'exists' => $exists,
            'count' => $count,
            'last_modified' => $last_modified,
            'last_check' => $last_check,
            'days_since_check' => round($days_since_check),
            'needs_update' => $needs_update,
        ];
    }

    /**
     * Mark taxonomy as checked (for update alerts)
     */
    public function mark_as_checked(){
        update_option('mcs_taxonomy_last_check', current_time('timestamp'));
    }

    /**
     * Import taxonomy from official TXT file
     *
     * Downloads and converts Google's official taxonomy file to JSON format
     *
     * @param string $txt_file_path Path to downloaded taxonomy-with-ids.txt file
     * @return array ['success' => bool, 'message' => string, 'count' => int]
     */
    public function import_from_txt($txt_file_path){
        if (!file_exists($txt_file_path)) {
            return [
                'success' => false,
                'message' => __('Archivo no encontrado', 'mad-suite'),
                'count' => 0,
            ];
        }

        $taxonomy = [];
        $lines = file($txt_file_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Skip comments and header
            if (empty($line) || $line[0] === '#') {
                continue;
            }

            // Format: "ID - Category > Subcategory > Item"
            if (preg_match('/^(\d+)\s+-\s+(.+)$/', $line, $matches)) {
                $id = $matches[1];
                $path = $matches[2];
                $taxonomy[$id] = $path;
            }
        }

        if (empty($taxonomy)) {
            return [
                'success' => false,
                'message' => __('No se pudieron parsear las categorías', 'mad-suite'),
                'count' => 0,
            ];
        }

        // Save to JSON file
        $json = json_encode($taxonomy, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $data_dir = dirname($this->taxonomy_file);

        if (!is_dir($data_dir)) {
            mkdir($data_dir, 0755, true);
        }

        file_put_contents($this->taxonomy_file, $json);

        // Clear cache
        $this->clear_cache();
        $this->mark_as_checked();

        return [
            'success' => true,
            'message' => sprintf(__('Se importaron %d categorías exitosamente', 'mad-suite'), count($taxonomy)),
            'count' => count($taxonomy),
        ];
    }

    /**
     * Get all categories for a specific parent path
     *
     * @param string $parent_path Parent category path (e.g., "Apparel & Accessories")
     * @return array Child categories
     */
    public function get_children($parent_path){
        $taxonomy = $this->load_taxonomy();
        $children = [];

        foreach ($taxonomy as $id => $path) {
            // Check if this category is a direct child of parent
            if (strpos($path, $parent_path . ' > ') === 0) {
                // Get the immediate child (not grandchildren)
                $relative = substr($path, strlen($parent_path) + 3);
                if (strpos($relative, ' > ') === false) {
                    $children[] = [
                        'id' => $id,
                        'path' => $path,
                        'name' => $relative,
                    ];
                }
            }
        }

        return $children;
    }
}
