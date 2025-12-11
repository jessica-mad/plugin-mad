<?php
namespace MAD_Suite\MultiCatalogSync\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * TagMapper
 * Maps WooCommerce tags to custom_label fields for catalog feeds
 */
class TagMapper {

    private $settings;

    public function __construct($settings = []){
        $this->settings = $settings;
    }

    /**
     * Get custom labels for a product
     *
     * @param int $product_id Product ID
     * @return array Associative array ['custom_label_0' => 'value', ...]
     */
    public function get_product_custom_labels($product_id){
        $product = wc_get_product($product_id);
        if (!$product) {
            return [];
        }

        $custom_labels = [];

        // Get product tags
        $tag_ids = $product->get_tag_ids();
        if (empty($tag_ids)) {
            return [];
        }

        // Check each tag for custom label mapping
        foreach ($tag_ids as $tag_id) {
            $mapping = $this->get_tag_mapping($tag_id);
            if ($mapping) {
                $label_index = $mapping['custom_label'];
                $label_key = 'custom_label_' . $label_index;

                // Only add if this label slot isn't already filled
                if (!isset($custom_labels[$label_key])) {
                    $custom_labels[$label_key] = $mapping['value'];
                }
            }
        }

        return $custom_labels;
    }

    /**
     * Get custom label mapping for a tag
     *
     * @param int $tag_id Tag term ID
     * @return array|null ['custom_label' => int, 'value' => string, 'name' => string]
     */
    public function get_tag_mapping($tag_id){
        // Check if tag is enabled for sync
        $sync_enabled = get_term_meta($tag_id, '_mcs_sync_enabled', true);
        if ($sync_enabled !== '1') {
            return null;
        }

        // Get custom label assignment
        $custom_label_index = get_term_meta($tag_id, '_mcs_custom_label', true);
        if ($custom_label_index === '' || $custom_label_index === false) {
            return null;
        }

        // Get tag term
        $tag = get_term($tag_id, 'product_tag');
        if (!$tag || is_wp_error($tag)) {
            return null;
        }

        // Get custom label name from settings
        $label_name_key = 'custom_label_' . $custom_label_index . '_name';
        $label_name = isset($this->settings[$label_name_key]) ? $this->settings[$label_name_key] : '';

        return [
            'custom_label' => (int) $custom_label_index,
            'value' => $tag->slug, // Use slug as value (clean, no spaces)
            'name' => $label_name,
            'tag_name' => $tag->name,
        ];
    }

    /**
     * Get all tags mapped to a specific custom label
     *
     * @param int $label_index Custom label index (0-4)
     * @return array Array of tag objects with mapping info
     */
    public function get_tags_for_label($label_index){
        $tags = get_terms([
            'taxonomy' => 'product_tag',
            'hide_empty' => false,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_mcs_sync_enabled',
                    'value' => '1',
                ],
                [
                    'key' => '_mcs_custom_label',
                    'value' => $label_index,
                ],
            ],
        ]);

        if (is_wp_error($tags)) {
            return [];
        }

        $result = [];
        foreach ($tags as $tag) {
            $result[] = [
                'term_id' => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'count' => $tag->count,
            ];
        }

        return $result;
    }

    /**
     * Get summary of all custom label mappings
     *
     * @return array Summary of custom labels usage
     */
    public function get_mapping_summary(){
        $summary = [];

        for ($i = 0; $i <= 4; $i++) {
            $label_name_key = 'custom_label_' . $i . '_name';
            $label_name = isset($this->settings[$label_name_key]) ? $this->settings[$label_name_key] : '';

            if (empty($label_name)) {
                continue;
            }

            $tags = $this->get_tags_for_label($i);

            $summary[] = [
                'index' => $i,
                'name' => $label_name,
                'tag_count' => count($tags),
                'tags' => $tags,
            ];
        }

        return $summary;
    }

    /**
     * Get available custom label slots
     *
     * @return array Available custom label configurations
     */
    public function get_available_labels(){
        $labels = [];

        for ($i = 0; $i <= 4; $i++) {
            $label_name_key = 'custom_label_' . $i . '_name';
            $label_name = isset($this->settings[$label_name_key]) ? $this->settings[$label_name_key] : '';

            if (empty($label_name)) {
                continue;
            }

            $labels[$i] = $label_name;
        }

        return $labels;
    }

    /**
     * Check if a custom label slot is available
     *
     * @param int $label_index Custom label index (0-4)
     * @return bool
     */
    public function is_label_available($label_index){
        $label_name_key = 'custom_label_' . $label_index . '_name';
        $label_name = isset($this->settings[$label_name_key]) ? $this->settings[$label_name_key] : '';

        return !empty($label_name);
    }

    /**
     * Format custom labels for feed export
     *
     * @param array $custom_labels Raw custom labels array
     * @return array Formatted custom labels (values only, sanitized)
     */
    public function format_for_feed($custom_labels){
        $formatted = [];

        foreach ($custom_labels as $key => $value) {
            if (strpos($key, 'custom_label_') === 0 && !empty($value)) {
                // Sanitize value for feed
                $sanitized_value = $this->sanitize_label_value($value);
                if (!empty($sanitized_value)) {
                    $formatted[$key] = $sanitized_value;
                }
            }
        }

        return $formatted;
    }

    /**
     * Sanitize custom label value
     *
     * @param string $value Raw value
     * @return string Sanitized value
     */
    private function sanitize_label_value($value){
        // Convert to lowercase
        $value = mb_strtolower($value);

        // Replace spaces and special characters with hyphens
        $value = preg_replace('/[^a-z0-9-_]/', '-', $value);

        // Remove multiple consecutive hyphens
        $value = preg_replace('/-+/', '-', $value);

        // Trim hyphens from start and end
        $value = trim($value, '-');

        // Limit length to 1000 characters (Google's limit)
        if (mb_strlen($value) > 1000) {
            $value = mb_substr($value, 0, 1000);
        }

        return $value;
    }

    /**
     * Get all active sync-enabled tags
     *
     * @return array Array of tag objects
     */
    public function get_all_active_tags(){
        $tags = get_terms([
            'taxonomy' => 'product_tag',
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key' => '_mcs_sync_enabled',
                    'value' => '1',
                ],
            ],
        ]);

        if (is_wp_error($tags)) {
            return [];
        }

        return $tags;
    }

    /**
     * Count products using a specific tag with custom label
     *
     * @param int $tag_id Tag term ID
     * @return int Product count
     */
    public function count_products_with_tag($tag_id){
        $products = wc_get_products([
            'limit' => -1,
            'return' => 'ids',
            'tag' => [$tag_id],
        ]);

        return count($products);
    }
}
