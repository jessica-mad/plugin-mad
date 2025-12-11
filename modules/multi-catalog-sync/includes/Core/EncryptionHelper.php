<?php
namespace MAD_Suite\MultiCatalogSync\Core;

if ( ! defined('ABSPATH') ) exit;

/**
 * EncryptionHelper
 * Helper class for encrypting/decrypting sensitive data like OAuth tokens
 */
class EncryptionHelper {

    /**
     * Get encryption key from WordPress constants or generate one
     */
    private static function get_key(){
        // Use WordPress AUTH_KEY as encryption key base
        if (defined('AUTH_KEY') && AUTH_KEY !== 'put your unique phrase here') {
            return substr(hash('sha256', AUTH_KEY), 0, 32);
        }

        // Fallback: use a default key (not recommended for production)
        // In production, users should have proper AUTH_KEY in wp-config.php
        return substr(hash('sha256', 'madsuite-fallback-key-change-this'), 0, 32);
    }

    /**
     * Encrypt a string
     *
     * @param string $data Data to encrypt
     * @return string Encrypted data (base64)
     */
    public static function encrypt($data){
        if (empty($data)) {
            return '';
        }

        $key = self::get_key();
        $iv = openssl_random_pseudo_bytes(16);

        $encrypted = openssl_encrypt(
            $data,
            'AES-256-CBC',
            $key,
            0,
            $iv
        );

        // Combine IV and encrypted data
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt a string
     *
     * @param string $encrypted_data Encrypted data (base64)
     * @return string Decrypted data
     */
    public static function decrypt($encrypted_data){
        if (empty($encrypted_data)) {
            return '';
        }

        $key = self::get_key();
        $data = base64_decode($encrypted_data);

        // Extract IV (first 16 bytes)
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);

        $decrypted = openssl_decrypt(
            $encrypted,
            'AES-256-CBC',
            $key,
            0,
            $iv
        );

        return $decrypted;
    }

    /**
     * Check if encryption is available
     *
     * @return bool
     */
    public static function is_available(){
        return function_exists('openssl_encrypt') && function_exists('openssl_decrypt');
    }
}
