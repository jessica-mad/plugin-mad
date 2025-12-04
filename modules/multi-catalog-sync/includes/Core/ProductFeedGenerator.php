<?php
namespace MAD_Suite\MultiCatalogSync\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * ProductFeedGenerator
 * Generates product feed data for all catalog destinations
 */
class ProductFeedGenerator {

    private $settings;
    private $category_mapper;
    private $tag_mapper;
    private $logger;

    public function __construct($settings = []){
        $this->settings = $settings;
        $this->category_mapper = new CategoryMapper();
        $this->tag_mapper = new TagMapper($settings);
        $this->logger = new Logger();
    }

    /**
     * Generate feed data for a single product
     *
     * @param int $product_id Product ID
     * @param string $destination Destination (google, facebook, pinterest, or 'all')
     * @return array|null Product feed data or null if product shouldn't be synced
     */
    public function generate_product_feed($product_id, $destination = 'all'){
        $product = wc_get_product($product_id);
        if (!$product) {
            return null;
        }

        // Check if sync is enabled for this product
        if (!$this->is_product_sync_enabled($product_id)) {
            return null;
        }

        // Handle variable products
        if ($product->is_type('variable')) {
            return $this->generate_variable_product_feed($product, $destination);
        }

        // Handle variation products
        if ($product->is_type('variation')) {
            return $this->generate_variation_feed($product, $destination);
        }

        // Handle simple products
        return $this->generate_simple_product_feed($product, $destination);
    }

    /**
     * Generate feed for a simple product
     *
     * @param WC_Product $product
     * @param string $destination
     * @return array Product data
     */
    private function generate_simple_product_feed($product, $destination){
        $data = $this->get_base_product_data($product);

        // Add destination-specific formatting
        if ($destination !== 'all') {
            $data = $this->format_for_destination($data, $destination);
        }

        return $data;
    }

    /**
     * Generate feed for a variable product (parent)
     * Returns array of all variations
     *
     * @param WC_Product_Variable $product
     * @param string $destination
     * @return array Array of variation data
     */
    private function generate_variable_product_feed($product, $destination){
        $variations_data = [];

        $variation_ids = $product->get_children();

        foreach ($variation_ids as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation || !$variation->is_purchasable()) {
                continue;
            }

            $variation_data = $this->generate_variation_feed($variation, $destination, $product);
            if ($variation_data) {
                $variations_data[] = $variation_data;
            }
        }

        return $variations_data;
    }

    /**
     * Generate feed for a product variation
     *
     * @param WC_Product_Variation $variation
     * @param string $destination
     * @param WC_Product_Variable|null $parent_product
     * @return array Variation data
     */
    private function generate_variation_feed($variation, $destination, $parent_product = null){
        if (!$parent_product) {
            $parent_product = wc_get_product($variation->get_parent_id());
        }

        $data = $this->get_base_product_data($variation);

        // Add item_group_id to group variations
        $data['item_group_id'] = (string) $parent_product->get_id();

        // Add variation attributes (color, size, etc.)
        $attributes = $variation->get_variation_attributes();
        foreach ($attributes as $attribute_name => $attribute_value) {
            $clean_name = str_replace('attribute_', '', strtolower($attribute_name));

            // Map common attributes to standard fields
            switch ($clean_name) {
                case 'pa_color':
                case 'color':
                    $data['color'] = $attribute_value;
                    break;
                case 'pa_size':
                case 'pa_talla':
                case 'size':
                case 'talla':
                    $data['size'] = $attribute_value;
                    break;
                case 'pa_material':
                case 'material':
                    $data['material'] = $attribute_value;
                    break;
                case 'pa_pattern':
                case 'pattern':
                    $data['pattern'] = $attribute_value;
                    break;
                default:
                    // Store as variant attribute
                    $data[$clean_name] = $attribute_value;
            }
        }

        // Override title to include variation attributes
        $data['title'] = $this->get_variation_title($variation, $parent_product);

        // Add destination-specific formatting
        if ($destination !== 'all') {
            $data = $this->format_for_destination($data, $destination);
        }

        return $data;
    }

    /**
     * Get base product data (common fields for all products)
     *
     * @param WC_Product $product
     * @return array Base product data
     */
    private function get_base_product_data($product){
        $product_id = $product->get_id();

        // Basic required fields
        $data = [
            'id' => (string) $product_id,
            'title' => $this->get_product_title($product),
            'description' => $this->get_product_description($product),
            'link' => $product->get_permalink(),
            'image_link' => $this->get_product_image($product),
            'price' => $this->format_price($product),
            'availability' => $this->get_availability($product),
            'condition' => 'new', // Default condition
        ];

        // Brand
        $data['brand'] = $this->get_product_brand($product_id);

        // Additional images
        $additional_images = $this->get_additional_images($product);
        if (!empty($additional_images)) {
            $data['additional_image_link'] = implode(',', $additional_images);
        }

        // Sale price (if applicable)
        $sale_price = $product->get_sale_price();
        if (!empty($sale_price) && $sale_price < $product->get_regular_price()) {
            $data['sale_price'] = $this->format_price($product, 'sale');

            // Sale price effective dates
            $date_on_sale_from = $product->get_date_on_sale_from();
            $date_on_sale_to = $product->get_date_on_sale_to();

            if ($date_on_sale_from && $date_on_sale_to) {
                $data['sale_price_effective_date'] = sprintf(
                    '%s/%s',
                    $date_on_sale_from->format('c'),
                    $date_on_sale_to->format('c')
                );
            }
        }

        // GTIN / EAN
        $gtin = get_post_meta($product_id, '_mcs_gtin', true);
        if (!empty($gtin)) {
            $data['gtin'] = $gtin;
        }

        // MPN (Manufacturer Part Number)
        $mpn = get_post_meta($product_id, '_mcs_mpn', true);
        if (!empty($mpn)) {
            $data['mpn'] = $mpn;
        } elseif (!isset($data['gtin'])) {
            // Use SKU as fallback if no GTIN
            $sku = $product->get_sku();
            if (!empty($sku)) {
                $data['mpn'] = $sku;
            }
        }

        // Google Product Category
        $google_category = $this->category_mapper->get_product_category($product_id);
        if ($google_category) {
            $data['google_product_category'] = $google_category['id'];
        }

        // Product Type (WooCommerce categories as breadcrumb)
        $product_type = $this->get_product_type_breadcrumb($product);
        if (!empty($product_type)) {
            $data['product_type'] = $product_type;
        }

        // Custom Labels (from tags)
        $custom_labels = $this->tag_mapper->get_product_custom_labels($product_id);
        if (!empty($custom_labels)) {
            $formatted_labels = $this->tag_mapper->format_for_feed($custom_labels);
            $data = array_merge($data, $formatted_labels);
        }

        // Weight (if available)
        if ($product->has_weight()) {
            $data['shipping_weight'] = $product->get_weight() . ' ' . get_option('woocommerce_weight_unit');
        }

        // Age group (default to adult)
        $data['age_group'] = 'adult';

        // Gender (default to unisex)
        $data['gender'] = 'unisex';

        return $data;
    }

    /**
     * Get product title
     */
    private function get_product_title($product){
        $title = $product->get_name();
        return ValidationHelper::sanitize_title($title, 150);
    }

    /**
     * Get variation title (includes variation attributes)
     */
    private function get_variation_title($variation, $parent_product){
        $title = $parent_product->get_name();

        // Add variation attributes
        $attributes = $variation->get_variation_attributes();
        if (!empty($attributes)) {
            $attr_strings = [];
            foreach ($attributes as $attr_name => $attr_value) {
                if (!empty($attr_value)) {
                    $attr_strings[] = $attr_value;
                }
            }
            if (!empty($attr_strings)) {
                $title .= ' - ' . implode(', ', $attr_strings);
            }
        }

        return ValidationHelper::sanitize_title($title, 150);
    }

    /**
     * Get product description
     */
    private function get_product_description($product){
        // Try short description first
        $description = $product->get_short_description();

        // Fallback to full description
        if (empty($description)) {
            $description = $product->get_description();
        }

        // If still empty, use title
        if (empty($description)) {
            $description = $product->get_name();
        }

        return ValidationHelper::sanitize_description($description, 5000);
    }

    /**
     * Get product main image
     */
    private function get_product_image($product){
        $image_id = $product->get_image_id();
        if (!$image_id) {
            return '';
        }

        $image_url = wp_get_attachment_image_url($image_id, 'full');
        return $image_url ? $image_url : '';
    }

    /**
     * Get additional product images
     */
    private function get_additional_images($product){
        $gallery_ids = $product->get_gallery_image_ids();
        $images = [];

        foreach ($gallery_ids as $image_id) {
            $image_url = wp_get_attachment_image_url($image_id, 'full');
            if ($image_url) {
                $images[] = $image_url;
            }

            // Limit to 10 additional images (Google's limit)
            if (count($images) >= 10) {
                break;
            }
        }

        return $images;
    }

    /**
     * Format product price
     */
    private function format_price($product, $price_type = 'regular'){
        $price = ($price_type === 'sale') ? $product->get_sale_price() : $product->get_price();

        if (empty($price)) {
            $price = $product->get_regular_price();
        }

        $currency = get_woocommerce_currency();

        return ValidationHelper::format_price($price, $currency);
    }

    /**
     * Get product availability
     */
    private function get_availability($product){
        if (!$product->is_in_stock()) {
            return 'out of stock';
        }

        if ($product->is_on_backorder()) {
            return 'preorder';
        }

        return 'in stock';
    }

    /**
     * Get product brand
     */
    private function get_product_brand($product_id){
        // Check for custom brand
        $custom_brand = get_post_meta($product_id, '_mcs_custom_brand', true);
        if (!empty($custom_brand)) {
            return $custom_brand;
        }

        // Use default brand from settings
        $default_brand = isset($this->settings['default_brand']) ? $this->settings['default_brand'] : '';
        if (!empty($default_brand)) {
            return $default_brand;
        }

        // Fallback to site name
        return get_bloginfo('name');
    }

    /**
     * Get product type breadcrumb (WooCommerce categories)
     */
    private function get_product_type_breadcrumb($product){
        $categories = get_the_terms($product->get_id(), 'product_cat');
        if (!$categories || is_wp_error($categories)) {
            return '';
        }

        // Get the most specific category (deepest in hierarchy)
        $deepest_category = null;
        $max_depth = 0;

        foreach ($categories as $category) {
            $depth = $this->get_category_depth($category->term_id);
            if ($depth > $max_depth) {
                $max_depth = $depth;
                $deepest_category = $category;
            }
        }

        if (!$deepest_category) {
            return '';
        }

        // Build breadcrumb from root to deepest
        $breadcrumb = [];
        $current_id = $deepest_category->term_id;

        while ($current_id > 0) {
            $term = get_term($current_id, 'product_cat');
            if (!$term || is_wp_error($term)) {
                break;
            }

            array_unshift($breadcrumb, $term->name);
            $current_id = $term->parent;
        }

        return implode(' > ', $breadcrumb);
    }

    /**
     * Get category depth in hierarchy
     */
    private function get_category_depth($category_id, $depth = 0){
        $term = get_term($category_id, 'product_cat');
        if (!$term || is_wp_error($term) || $term->parent == 0) {
            return $depth;
        }

        return $this->get_category_depth($term->parent, $depth + 1);
    }

    /**
     * Check if product sync is enabled
     */
    private function is_product_sync_enabled($product_id){
        $sync_enabled = get_post_meta($product_id, '_mcs_sync_enabled', true);

        // Default to enabled if not set
        return $sync_enabled === '' || $sync_enabled === '1';
    }

    /**
     * Format data for specific destination
     *
     * @param array $data Product data
     * @param string $destination Destination (google, facebook, pinterest)
     * @return array Formatted data
     */
    private function format_for_destination($data, $destination){
        switch ($destination) {
            case 'facebook':
                return $this->format_for_facebook($data);
            case 'pinterest':
                return $this->format_for_pinterest($data);
            case 'google':
            default:
                return $this->format_for_google($data);
        }
    }

    /**
     * Format data for Google Merchant Center
     */
    private function format_for_google($data){
        // Google uses 'item_group_id'
        // No changes needed, already in Google format
        return $data;
    }

    /**
     * Format data for Facebook Catalog
     */
    private function format_for_facebook($data){
        // Facebook uses 'product_group' instead of 'item_group_id'
        if (isset($data['item_group_id'])) {
            $data['product_group'] = $data['item_group_id'];
            unset($data['item_group_id']);
        }

        return $data;
    }

    /**
     * Format data for Pinterest Catalog
     */
    private function format_for_pinterest($data){
        // Pinterest is similar to Google
        // Remove promotional text from title if present
        if (isset($data['title'])) {
            $promo_words = ['gratis', 'free', 'descuento', 'discount', 'oferta', 'sale'];
            foreach ($promo_words as $word) {
                $data['title'] = preg_replace('/\b' . preg_quote($word, '/') . '\b/i', '', $data['title']);
            }
            $data['title'] = trim(preg_replace('/\s+/', ' ', $data['title']));
        }

        return $data;
    }

    /**
     * Generate feed for multiple products
     *
     * @param array $product_ids Array of product IDs
     * @param string $destination Destination
     * @return array Array of product feed data
     */
    public function generate_bulk_feed($product_ids, $destination = 'all'){
        $feed_data = [];
        $skipped_count = 0;
        $variation_count = 0;

        foreach ($product_ids as $product_id) {
            $product_data = $this->generate_product_feed($product_id, $destination);

            // Handle variable products (returns array of variations)
            if (is_array($product_data) && isset($product_data[0]) && is_array($product_data[0])) {
                $var_count = count($product_data);
                $variation_count += $var_count;
                foreach ($product_data as $variation_data) {
                    $feed_data[] = $variation_data;
                }
            } elseif ($product_data) {
                $feed_data[] = $product_data;
            } else {
                $skipped_count++;
            }
        }

        $this->logger->debug(sprintf(
            'Feed generation: %d products processed, %d feed items generated (%d variations), %d skipped',
            count($product_ids),
            count($feed_data),
            $variation_count,
            $skipped_count
        ));

        return $feed_data;
    }
}
