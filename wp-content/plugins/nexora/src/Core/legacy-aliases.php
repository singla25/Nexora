<?php
/**
 * Old global class name => current namespaced class.
 * Loaded lazily by Nexora\Core\Autoloader; kept so existing code, tests and any custom
 * snippets that still use the old names keep working. Remove entries when nothing uses them.
 */

if (!defined('ABSPATH')) exit;

return [
    'NEXORA_System' => 'Nexora\Core\Assets',   // static helpers only: enqueue_tokens(), enqueue_sweetalert(), is_page_for()
];
