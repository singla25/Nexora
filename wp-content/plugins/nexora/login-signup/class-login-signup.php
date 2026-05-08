<?php
/**
 * login-signup/login-signup.php
 *
 * Single include point for the entire login-signup module.
 * Drop ONE line in your main plugin file:  require_once NEXORA_PATH . '/login-signup/login-signup.php';
 * 
 */

if ( ! defined( 'ABSPATH' ) ) exit;


// ── Autoload classes ──────────────────────────────────────────────────────────
require_once __DIR__ . '/class-login.php';
require_once __DIR__ . '/class-registration.php';
require_once __DIR__ . '/class-vendor-registration.php';

// ── Instantiate (hooks registered inside constructors) ────────────────────────
new NEXORA_Login();
new NEXORA_Registration();
new NEXORA_Vendor_Registration();


