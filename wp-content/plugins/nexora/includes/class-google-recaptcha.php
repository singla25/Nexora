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

    public function is_local() {
        $host = $_SERVER['HTTP_HOST'];

        return (
            strpos($host, 'localhost') !== false ||
            strpos($host, '.local') !== false ||   // 🔥 this handles your case
            in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'])
        );
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

        return '<div class="g-recaptcha" data-sitekey="' . esc_attr($this->site_key) . '"></div>';
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
                    'remoteip' => $_SERVER['REMOTE_ADDR']
                ]
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