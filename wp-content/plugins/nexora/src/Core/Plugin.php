<?php

namespace Nexora\Core;

if (!defined('ABSPATH')) exit;

/**
 * Single place that wires the plugin's modules. Each module registers its own hooks,
 * shortcodes and AJAX handlers in its constructor.
 */
class Plugin {

    /** Modules still living in the old includes/ files (removed as they are migrated). */
    const LEGACY_FILES = [
        'includes/class-cpt.php',
        'includes/class-registration.php',
        'includes/class-profile-page.php',
        'includes/class-profile-ajax.php',
        'includes/class-profile-helper.php',
        'includes/class-login.php',
        'includes/class-home-page.php',
        'includes/class-notification.php',
        'includes/class-better-message-chat.php',
        'includes/class-google-recaptcha.php',
        'includes/class-shortcodes.php',
        'includes/class-contact-form.php',
        'includes/class-ajax.php',
        'includes/class-rate-limiter.php',
        'includes/class-private-documents.php',
        'includes/class-upload-policy.php',
        'chat/class-chat-core.php',
    ];

    public static function boot() {

        foreach (self::LEGACY_FILES as $file) {
            require_once NEXORA_PATH . $file;
        }

        // Same order the modules have always registered their hooks in
        new \NEXORA_Registration();
        new \NEXORA_Login();
        new \NEXORA_CPT();
        new \NEXORA_PROFILE_PAGE();
        new \NEXORA_PROFILE_AJAX();
        new \Nexora_Private_Documents();
        new \Nexora_Upload_Policy();
        new \Nexora_Home_Page();
        new \Nexora_Better_Message_CHAT_Page();
        new \NEXORA_CHAT_CORE();
        new \Nexora_ReCaptcha();
        new \Nexora_Shortcodes();
        new \Nexora_Contact_Form();

        new Assets();
        new Access_Control();
    }
}
