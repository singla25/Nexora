<?php
/**
 * Nexora theme bootstrap.
 *
 * @package Nexora_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NXT_VERSION', '1.0.0' );
define( 'NXT_DIR', get_template_directory() );
define( 'NXT_URI', get_template_directory_uri() );

require NXT_DIR . '/inc/theme-setup.php';
require NXT_DIR . '/inc/helpers.php';
require NXT_DIR . '/inc/settings-page.php';
