<?php
/**
 * Old global class name => current namespaced class.
 * Loaded lazily by Nexora\Core\Autoloader; kept so existing code, tests and any custom
 * snippets that still use the old names keep working. Remove entries when nothing uses them.
 */

if (!defined('ABSPATH')) exit;

return [
    'NEXORA_System'                   => 'Nexora\Core\Assets',   // static helpers only: enqueue_tokens(), enqueue_sweetalert(), is_page_for()
    'Nexora_Ajax'                     => 'Nexora\Http\Ajax',
    'Nexora_Rate_Limiter'             => 'Nexora\Http\Rate_Limiter',
    'NEXORA_Notification'             => 'Nexora\Notifications\Repository',
    'NEXORA_CHAT_DB'                  => 'Nexora\Chat\Repository',
    'NEXORA_CHAT_AJAX'                => 'Nexora\Chat\Ajax',
    'NEXORA_CHAT_CORE'                => 'Nexora\Chat\Module',
    'NEXORA_PROFILE_HELPER'           => 'Nexora\Profile\Repository',
    'NEXORA_PROFILE_PAGE'             => 'Nexora\Profile\Page',
    'NEXORA_PROFILE_AJAX'             => 'Nexora\Profile\Ajax',
    'Nexora_Private_Documents'        => 'Nexora\Profile\Private_Documents',
    'Nexora_Upload_Policy'            => 'Nexora\Profile\Upload_Policy',
    'NEXORA_Login'                    => 'Nexora\Auth\Login',
    'NEXORA_Registration'             => 'Nexora\Auth\Registration',
    'Nexora_ReCaptcha'                => 'Nexora\Auth\Recaptcha',
    'Nexora_Home_Page'                => 'Nexora\Shortcodes\Home',
    'Nexora_Shortcodes'               => 'Nexora\Shortcodes\Stats',
    'Nexora_Contact_Form'             => 'Nexora\Shortcodes\Contact_Form',
    'Nexora_Better_Message_CHAT_Page' => 'Nexora\Integrations\Better_Messages',
];
