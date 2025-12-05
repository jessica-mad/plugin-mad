<?php
namespace MAD_Suite\MultiCatalogSync\Destinations;

use MAD_Suite\MultiCatalogSync\Core\Logger;
use MAD_Suite\MultiCatalogSync\Core\EncryptionHelper;

if ( ! defined('ABSPATH') ) exit;

/**
 * GoogleOAuthHandler
 * Handles OAuth2 authentication for Google Merchant Center
 * Supports both MAD Suite centralized app and custom user apps
 */
class GoogleOAuthHandler {

    private $settings;
    private $logger;
    private $use_custom_app;

    // MAD Suite OAuth App Credentials (hardcoded)
    // TODO: Replace these with your actual OAuth App credentials from Google Cloud Console
    const MAD_SUITE_CLIENT_ID = 'YOUR_CLIENT_ID.apps.googleusercontent.com';
    const MAD_SUITE_CLIENT_SECRET = 'YOUR_CLIENT_SECRET';

    // OAuth endpoints
    const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    // Required scopes for Merchant API
    const REQUIRED_SCOPES = 'https://www.googleapis.com/auth/content';

    public function __construct($settings = []){
        $this->settings = $settings;
        $this->logger = new Logger();

        // Determine if using custom OAuth app or MAD Suite app
        $this->use_custom_app = isset($settings['google_oauth_use_custom']) && $settings['google_oauth_use_custom'] === '1';
    }

    /**
     * Get the Client ID (MAD Suite or custom)
     */
    private function get_client_id(){
        if ($this->use_custom_app) {
            return isset($this->settings['google_oauth_client_id']) ? $this->settings['google_oauth_client_id'] : '';
        }
        return self::MAD_SUITE_CLIENT_ID;
    }

    /**
     * Get the Client Secret (MAD Suite or custom)
     */
    private function get_client_secret(){
        if ($this->use_custom_app) {
            return isset($this->settings['google_oauth_client_secret']) ? $this->settings['google_oauth_client_secret'] : '';
        }
        return self::MAD_SUITE_CLIENT_SECRET;
    }

    /**
     * Get the redirect URI for OAuth callback
     */
    public function get_redirect_uri(){
        return admin_url('admin.php?page=madsuite-multi-catalog-sync&action=google_oauth_callback');
    }

    /**
     * Generate authorization URL for user to grant access
     */
    public function get_authorization_url(){
        $client_id = $this->get_client_id();

        if (empty($client_id)) {
            return false;
        }

        $params = [
            'client_id' => $client_id,
            'redirect_uri' => $this->get_redirect_uri(),
            'response_type' => 'code',
            'scope' => self::REQUIRED_SCOPES,
            'access_type' => 'offline', // Important: to get refresh token
            'prompt' => 'consent', // Force consent screen to ensure refresh token
            'state' => wp_create_nonce('google_oauth_state'), // CSRF protection
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token and refresh token
     *
     * @param string $code Authorization code from OAuth callback
     * @return array|false Token data or false on error
     */
    public function exchange_code_for_tokens($code){
        $client_id = $this->get_client_id();
        $client_secret = $this->get_client_secret();

        if (empty($client_id) || empty($client_secret)) {
            $this->logger->error('OAuth: Missing client credentials');
            return false;
        }

        $post_data = [
            'code' => $code,
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'redirect_uri' => $this->get_redirect_uri(),
            'grant_type' => 'authorization_code',
        ];

        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => $post_data,
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('OAuth token exchange failed: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['error'])) {
            $this->logger->error('OAuth token exchange error: ' . $data['error']);
            return false;
        }

        if (!isset($data['access_token']) || !isset($data['refresh_token'])) {
            $this->logger->error('OAuth: Missing tokens in response');
            return false;
        }

        $this->logger->info('OAuth: Successfully exchanged code for tokens');

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'],
            'expires_in' => isset($data['expires_in']) ? (int) $data['expires_in'] : 3600,
            'token_type' => isset($data['token_type']) ? $data['token_type'] : 'Bearer',
        ];
    }

    /**
     * Refresh access token using refresh token
     *
     * @param string $refresh_token Refresh token
     * @return array|false New token data or false on error
     */
    public function refresh_access_token($refresh_token){
        $client_id = $this->get_client_id();
        $client_secret = $this->get_client_secret();

        if (empty($refresh_token)) {
            return false;
        }

        $post_data = [
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token,
            'grant_type' => 'refresh_token',
        ];

        $response = wp_remote_post(self::TOKEN_URL, [
            'body' => $post_data,
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $this->logger->error('OAuth token refresh failed: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['error'])) {
            $this->logger->error('OAuth token refresh error: ' . $data['error']);
            return false;
        }

        if (!isset($data['access_token'])) {
            $this->logger->error('OAuth: Missing access token in refresh response');
            return false;
        }

        return [
            'access_token' => $data['access_token'],
            'expires_in' => isset($data['expires_in']) ? (int) $data['expires_in'] : 3600,
            'token_type' => isset($data['token_type']) ? $data['token_type'] : 'Bearer',
        ];
    }

    /**
     * Save OAuth tokens to database (encrypted)
     *
     * @param array $tokens Token data
     * @return bool Success
     */
    public function save_tokens($tokens){
        if (!isset($tokens['refresh_token'])) {
            return false;
        }

        $app_type = $this->use_custom_app ? 'custom' : 'madsuite';

        // Encrypt refresh token
        $encrypted_refresh_token = EncryptionHelper::encrypt($tokens['refresh_token']);

        // Save encrypted refresh token
        update_option("mcs_google_oauth_refresh_token_{$app_type}", $encrypted_refresh_token);

        // Cache access token with expiry
        $expires_at = time() + $tokens['expires_in'] - 300; // 5 min buffer
        set_transient("mcs_google_oauth_access_token_{$app_type}", $tokens['access_token'], $tokens['expires_in'] - 300);
        update_option("mcs_google_oauth_token_expires_{$app_type}", $expires_at);

        // Save user info if available
        if (isset($tokens['id_token'])) {
            // Decode JWT to get user email (optional)
            // For now, just mark as connected
        }

        update_option("mcs_google_oauth_connected_{$app_type}", true);
        update_option("mcs_google_oauth_connected_at_{$app_type}", current_time('timestamp'));

        $this->logger->info("OAuth: Tokens saved successfully ({$app_type} app)");

        return true;
    }

    /**
     * Get a valid access token (from cache or refresh if expired)
     *
     * @return string|false Access token or false
     */
    public function get_valid_access_token(){
        $app_type = $this->use_custom_app ? 'custom' : 'madsuite';

        // Check cached access token
        $cached_token = get_transient("mcs_google_oauth_access_token_{$app_type}");
        if ($cached_token) {
            return $cached_token;
        }

        // Token expired, refresh it
        $encrypted_refresh_token = get_option("mcs_google_oauth_refresh_token_{$app_type}");
        if (!$encrypted_refresh_token) {
            $this->logger->error('OAuth: No refresh token found');
            return false;
        }

        // Decrypt refresh token
        $refresh_token = EncryptionHelper::decrypt($encrypted_refresh_token);
        if (!$refresh_token) {
            $this->logger->error('OAuth: Failed to decrypt refresh token');
            return false;
        }

        // Refresh access token
        $new_tokens = $this->refresh_access_token($refresh_token);
        if (!$new_tokens) {
            return false;
        }

        // Cache new access token
        set_transient("mcs_google_oauth_access_token_{$app_type}", $new_tokens['access_token'], $new_tokens['expires_in'] - 300);
        $expires_at = time() + $new_tokens['expires_in'] - 300;
        update_option("mcs_google_oauth_token_expires_{$app_type}", $expires_at);

        $this->logger->info('OAuth: Access token refreshed successfully');

        return $new_tokens['access_token'];
    }

    /**
     * Check if OAuth is connected
     *
     * @return bool
     */
    public function is_connected(){
        $app_type = $this->use_custom_app ? 'custom' : 'madsuite';
        $connected = get_option("mcs_google_oauth_connected_{$app_type}");
        $has_refresh_token = get_option("mcs_google_oauth_refresh_token_{$app_type}");

        return $connected && $has_refresh_token;
    }

    /**
     * Disconnect OAuth (remove tokens)
     */
    public function disconnect(){
        $app_type = $this->use_custom_app ? 'custom' : 'madsuite';

        delete_option("mcs_google_oauth_refresh_token_{$app_type}");
        delete_transient("mcs_google_oauth_access_token_{$app_type}");
        delete_option("mcs_google_oauth_token_expires_{$app_type}");
        delete_option("mcs_google_oauth_connected_{$app_type}");
        delete_option("mcs_google_oauth_connected_at_{$app_type}");

        $this->logger->info("OAuth: Disconnected ({$app_type} app)");
    }

    /**
     * Get connection info
     *
     * @return array Connection status info
     */
    public function get_connection_info(){
        $app_type = $this->use_custom_app ? 'custom' : 'madsuite';

        $connected = $this->is_connected();
        $connected_at = get_option("mcs_google_oauth_connected_at_{$app_type}");
        $expires_at = get_option("mcs_google_oauth_token_expires_{$app_type}");

        return [
            'connected' => $connected,
            'app_type' => $app_type,
            'connected_at' => $connected_at,
            'token_expires_at' => $expires_at,
            'token_valid' => $expires_at ? ($expires_at > time()) : false,
        ];
    }
}
