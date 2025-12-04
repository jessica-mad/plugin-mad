<?php
namespace MAD_Suite\MultiCatalogSync\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * ValidationHelper
 * Validates product data before sending to catalogs
 */
class ValidationHelper {

    /**
     * Validate product data for a specific destination
     *
     * @param array $product_data Product data array
     * @param string $destination Destination (google, facebook, pinterest)
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validate($product_data, $destination){
        $errors = [];

        // Common required fields for all destinations
        $required_fields = [
            'id' => __('ID del producto', 'mad-suite'),
            'title' => __('Título', 'mad-suite'),
            'description' => __('Descripción', 'mad-suite'),
            'link' => __('URL del producto', 'mad-suite'),
            'image_link' => __('Imagen principal', 'mad-suite'),
            'price' => __('Precio', 'mad-suite'),
            'availability' => __('Disponibilidad', 'mad-suite'),
            'brand' => __('Marca', 'mad-suite'),
        ];

        // Check required fields
        foreach ($required_fields as $field => $label) {
            if (empty($product_data[$field])) {
                $errors[] = sprintf(__('Falta campo requerido: %s', 'mad-suite'), $label);
            }
        }

        // Validate specific fields
        if (!empty($product_data['price'])) {
            if (!self::validate_price($product_data['price'])) {
                $errors[] = __('Formato de precio inválido', 'mad-suite');
            }
        }

        if (!empty($product_data['link'])) {
            if (!self::validate_url($product_data['link'])) {
                $errors[] = __('URL del producto inválida', 'mad-suite');
            }
        }

        if (!empty($product_data['image_link'])) {
            if (!self::validate_url($product_data['image_link'])) {
                $errors[] = __('URL de imagen inválida', 'mad-suite');
            }
        }

        // Google-specific validation
        if ($destination === 'google') {
            $errors = array_merge($errors, self::validate_google($product_data));
        }

        // Facebook-specific validation
        if ($destination === 'facebook') {
            $errors = array_merge($errors, self::validate_facebook($product_data));
        }

        // Pinterest-specific validation
        if ($destination === 'pinterest') {
            $errors = array_merge($errors, self::validate_pinterest($product_data));
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Google Merchant Center specific validation
     */
    private static function validate_google($product_data){
        $errors = [];

        // Google Product Category is required
        if (empty($product_data['google_product_category'])) {
            $errors[] = __('Falta categoría de Google (google_product_category)', 'mad-suite');
        }

        // GTIN or MPN required for known brands
        $known_brands = self::is_known_brand($product_data['brand'] ?? '');
        if ($known_brands && empty($product_data['gtin']) && empty($product_data['mpn'])) {
            $errors[] = __('Marcas conocidas requieren GTIN o MPN', 'mad-suite');
        }

        // Condition required
        if (empty($product_data['condition'])) {
            $errors[] = __('Falta condición del producto (condition)', 'mad-suite');
        }

        // Validate GTIN format if present
        if (!empty($product_data['gtin']) && !self::validate_gtin($product_data['gtin'])) {
            $errors[] = __('Formato de GTIN inválido (debe ser 8, 12, 13 o 14 dígitos)', 'mad-suite');
        }

        // Title length (max 150 characters)
        if (!empty($product_data['title']) && mb_strlen($product_data['title']) > 150) {
            $errors[] = __('El título no puede exceder 150 caracteres', 'mad-suite');
        }

        // Description length (max 5000 characters)
        if (!empty($product_data['description']) && mb_strlen($product_data['description']) > 5000) {
            $errors[] = __('La descripción no puede exceder 5000 caracteres', 'mad-suite');
        }

        return $errors;
    }

    /**
     * Facebook Catalog specific validation
     */
    private static function validate_facebook($product_data){
        $errors = [];

        // Facebook is more lenient than Google
        // Most fields are optional except the basics

        // Title length (max 200 characters)
        if (!empty($product_data['title']) && mb_strlen($product_data['title']) > 200) {
            $errors[] = __('El título no puede exceder 200 caracteres', 'mad-suite');
        }

        return $errors;
    }

    /**
     * Pinterest Catalog specific validation
     */
    private static function validate_pinterest($product_data){
        $errors = [];

        // Pinterest requirements are similar to Google

        // Title should not have promotional text
        if (!empty($product_data['title'])) {
            $promo_words = ['gratis', 'free', 'descuento', 'discount', 'oferta', 'sale'];
            $title_lower = mb_strtolower($product_data['title']);

            foreach ($promo_words as $word) {
                if (strpos($title_lower, $word) !== false) {
                    $errors[] = __('El título no debe contener texto promocional', 'mad-suite');
                    break;
                }
            }
        }

        // Description required and must be meaningful
        if (!empty($product_data['description']) && mb_strlen($product_data['description']) < 50) {
            $errors[] = __('La descripción debe tener al menos 50 caracteres', 'mad-suite');
        }

        return $errors;
    }

    /**
     * Validate price format
     */
    private static function validate_price($price){
        // Should be in format: "10.00 USD" or "10.00"
        if (is_numeric($price)) {
            return $price > 0;
        }

        if (preg_match('/^\d+(\.\d{2})?\s+[A-Z]{3}$/', $price)) {
            return true;
        }

        return false;
    }

    /**
     * Validate URL
     */
    private static function validate_url($url){
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Validate GTIN format
     */
    private static function validate_gtin($gtin){
        // GTIN can be 8, 12, 13, or 14 digits
        $gtin = preg_replace('/\D/', '', $gtin); // Remove non-digits
        $length = strlen($gtin);

        return in_array($length, [8, 12, 13, 14], true);
    }

    /**
     * Check if brand is a known brand (requires GTIN)
     *
     * This is a simplified check. In production, you'd want to
     * check against a list of known brands.
     */
    private static function is_known_brand($brand){
        if (empty($brand)) {
            return false;
        }

        // List of major brands that require GTIN
        // You can expand this list or make it configurable
        $known_brands = [
            'nike', 'adidas', 'apple', 'samsung', 'sony',
            'lg', 'panasonic', 'canon', 'nikon', 'microsoft',
            'dell', 'hp', 'lenovo', 'asus', 'acer',
        ];

        $brand_lower = mb_strtolower($brand);

        foreach ($known_brands as $known) {
            if (strpos($brand_lower, $known) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize product title
     */
    public static function sanitize_title($title, $max_length = 150){
        // Remove HTML tags
        $title = strip_tags($title);

        // Remove extra whitespace
        $title = preg_replace('/\s+/', ' ', $title);
        $title = trim($title);

        // Truncate to max length
        if (mb_strlen($title) > $max_length) {
            $title = mb_substr($title, 0, $max_length - 3) . '...';
        }

        return $title;
    }

    /**
     * Sanitize product description
     */
    public static function sanitize_description($description, $max_length = 5000){
        // Remove HTML tags but preserve line breaks
        $description = strip_tags($description);

        // Remove extra whitespace
        $description = preg_replace('/\s+/', ' ', $description);
        $description = trim($description);

        // Truncate to max length
        if (mb_strlen($description) > $max_length) {
            $description = mb_substr($description, 0, $max_length - 3) . '...';
        }

        return $description;
    }

    /**
     * Format price for feeds
     */
    public static function format_price($price, $currency){
        return number_format((float) $price, 2, '.', '') . ' ' . strtoupper($currency);
    }

    /**
     * Validate and format image URL
     */
    public static function validate_image_url($url){
        if (empty($url)) {
            return false;
        }

        // Check if URL is valid
        if (!self::validate_url($url)) {
            return false;
        }

        // Check if image extension is valid
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $path = parse_url($url, PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowed_extensions, true)) {
            return false;
        }

        return $url;
    }
}
