<?php

namespace Nexora\Database;

if (!defined('ABSPATH')) exit;

/**
 * Creates the plugin's custom tables. Runs on plugin activation
 * (versioned migrations replace this in a later phase).
 */
class Installer {

    public static function activate() {

        // Notification table
        $notification = new \NEXORA_Notification();
        $notification->create_table();

        // Chat tables
        $chat_db = new \NEXORA_CHAT_DB();
        $chat_db->create_table();
    }
}
