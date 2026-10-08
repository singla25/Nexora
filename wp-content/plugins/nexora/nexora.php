<?php
/**
 * Plugin Name: Nexora
 * Description: Handles User Registration, Login, Profile Dashboard and User Connections
 * Version: 1.0
 * Author: Sahil Singla
 */

if (!defined('ABSPATH')) exit;

define('NEXORA_PATH', plugin_dir_path(__FILE__));
define('NEXORA_URL', plugin_dir_url(__FILE__));
define('NEXORA_VERSION', '1.0.2');

require_once NEXORA_PATH . 'src/Core/Autoloader.php';

Nexora\Core\Autoloader::register(NEXORA_PATH . 'src/', require NEXORA_PATH . 'src/Core/legacy-aliases.php');

register_activation_hook(__FILE__, ['Nexora\Database\Installer', 'activate']);

Nexora\Core\Plugin::boot();
