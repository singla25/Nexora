<?php

class Nexora_ReCaptcha {

    private $site_key;
    private $secret_key;
    private $enabled;

    public function __construct() {
        $this->site_key   = get_option('recaptcha_site_key');
        $this->secret_key = get_option('recaptcha_secret_key');
        $this->enabled    = get_option('recaptcha_enabled');
    }

    /**
     * Captcha is skipped only when WordPress itself reports a local environment
     * (WP_ENVIRONMENT_TYPE = local). The Host header is client controlled and
     * must never be used to decide this.
     */
    public function is_local() {
        return function_exists('wp_get_environment_type') && wp_get_environment_type() === 'local';
    }

    // 🔹 Check if captcha is enabled
    public function is_enabled() {
        return !empty($this->enabled) && !empty($this->site_key) && !empty($this->secret_key);
    }

    // 🔹 Render captcha HTML (Frontend)
    public function render() {

        if ($this->is_local()) {
            return ''; // ❌ hide captcha on local
        }

        if (!$this->is_enabled()) {
            return '';
        }

        return '<div class="g-recaptcha" style="margin: 15px 0;display: flex;justify-content: center;" 
                data-sitekey="' . esc_attr($this->site_key) . '"></div>';
    }

    // 🔹 Enqueue script (call once globally)
    public function enqueue_script() {

        if ($this->is_local()) return;

        if (!$this->is_enabled()) return;

        wp_enqueue_script(
            'google-recaptcha',
            'https://www.google.com/recaptcha/api.js',
            [],
            null,
            true
        );
    }

    // 🔹 Verify captcha (Backend)
    public function verify($captcha_response) {

        // 🔥 BYPASS ON LOCAL
        if ($this->is_local()) {
            return ['success' => true];
        }

        // If disabled → skip validation
        if (!$this->is_enabled()) {
            return [
                'success' => true
            ];
        }

        if (empty($captcha_response)) {
            return [
                'success' => false,
                'message' => 'Captcha is required'
            ];
        }

        $response = wp_remote_post(
            'https://www.google.com/recaptcha/api/siteverify',
            [
                'body' => [
                    'secret'   => $this->secret_key,
                    'response' => $captcha_response,
                    'remoteip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : ''
                ],
                'timeout' => 10
            ]
        );

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => 'Captcha request failed'
            ];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($body['success'])) {
            return [
                'success' => true
            ];
        }

        return [
            'success' => false,
            'message' => 'Captcha verification failed'
        ];
    }
}