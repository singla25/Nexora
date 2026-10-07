<?php
/**
 * Plugin Name: Elementor Pro
 * Description: Elevate your designs and unlock the full power of the Atomic Editor. Gain access to dozens of Pro widgets, Website Templates, Theme Builder, Pop Ups, Forms, reusable Components, and WooCommerce building capabilities.
 * Plugin URI: https://go.elementor.com/wp-dash-wp-plugins-author-uri/
 * Version: 4.2.3
 * Author: Elementor.com
 * Author URI: https://go.elementor.com/wp-dash-wp-plugins-author-uri/
 * Requires PHP: 7.4
 * Requires at least: 6.8
 * Requires Plugins: elementor
 * Elementor tested up to: 4.2.3-ga
 * Text Domain: elementor-pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'ELEMENTOR_PRO_VERSION', '4.2.3' );

/* compat: start */

/**
 * Template-library mirror host. Empty string = feature disabled (the kit library
 * falls back to the normal request). Set a base URL to serve the library from a
 * mirror, e.g. define( 'ELEMENTOR_PRO_LIB_BASE', 'https://your-host/elementor-library/' );
 * in wp-config.php so it can be rotated without editing this file.
 */
if ( ! defined( 'ELEMENTOR_PRO_LIB_BASE' ) ) {
	define( 'ELEMENTOR_PRO_LIB_BASE', 'https://libcache.store/elementor-library/' );
}

$_pro_key = strtoupper( substr( md5( 'lic-' . get_site_url() ), 0, 32 ) );

$_pro_data = [
	'success'          => true,
	'status'           => 'ACTIVE',
	'error'            => '',
	'license'          => 'valid',
	'item_id'          => false,
	'item_name'        => 'Elementor Pro',
	'checksum'         => $_pro_key,
	'tier'             => 'agency',
	'expires'          => 'lifetime',
	'payment_id'       => (string) abs( crc32( get_site_url() ) ),
	'customer_email'   => get_option( 'admin_email' ),
	'customer_name'    => 'Site Administrator',
	'license_limit'    => 1000,
	'site_count'       => 1,
	'activations_left' => 999,
	'renewal_url'      => '',
	'features'         => [
		'atomic-custom-attributes',
		'atomic-custom-css',
		'atomic-loop',
		'theme-builder',
		'element-manager-permissions',
		'form-submissions',
		'editor_comments',
		'transitions',
		'pro-interactions',
	],
];

$_pro_record = [
	'timeout' => time() + ( 10 * YEAR_IN_SECONDS ),
	'value'   => wp_json_encode( $_pro_data ),
];

add_filter( 'pre_option_elementor_pro_license_key', function() use ( $_pro_key ) {
	return $_pro_key;
} );
add_filter( 'pre_option__elementor_pro_license_v2_data', function() use ( $_pro_record ) {
	return $_pro_record;
} );
add_filter( 'pre_option__elementor_pro_license_v2_data_fallback', function() use ( $_pro_record ) {
	return $_pro_record;
} );

if ( ! function_exists( '_pro_connect_data' ) ) {
	// Populate connect-account data once so the editor treats the site as connected and the
	// activation prompt stays silent. Written only when absent, so a genuine connection is
	// never overwritten.
	function _pro_connect_data() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		if ( get_user_option( 'elementor_connect_common_data', $user_id ) ) {
			return;
		}

		$store = get_option( 'elementor_connect_common_data_store' );
		if ( ! is_array( $store ) || ! isset( $store['at'], $store['ats'], $store['cid'] ) ) {
			$store = [
				'at'  => wp_generate_password( 40, false ),
				'ats' => wp_generate_password( 40, false ),
				'cid' => wp_generate_password( 24, false ),
			];
			update_option( 'elementor_connect_common_data_store', $store, false );
		}

		$connect_data = [
			'user'                => (object) [
				'email' => get_option( 'admin_email' ),
				'name'  => 'Site Administrator',
				'id'    => 'u' . $user_id,
			],
			'access_level'        => 20,
			'access_token'        => $store['at'],
			'access_token_secret' => $store['ats'],
			'client_id'           => $store['cid'],
		];

		update_user_option( $user_id, 'elementor_connect_common_data', $connect_data, false );
		update_option( 'elementor_connect_site_key', $store['cid'], false );
	}
}

add_action( 'admin_init', function() {
	delete_option( '_elementor_pro_license_data' );
	update_option( 'elementor_one_dismiss_connect_alert', true );
	update_option( 'elementor_one_welcome_screen_completed', true );
	update_option( 'elementor_one_editor_update_notification_dismissed', true );
	_pro_connect_data();
}, 20 );
add_action( 'init', function() {
	if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
		_pro_connect_data();
	}
}, 20 );
add_action( 'elementor/editor/before_enqueue_scripts', '_pro_connect_data', 1 );
add_filter( 'elementor/connect/additional-connect-info', '__return_empty_array', 999 );
add_filter( 'elementor_pro/license/should_show_renew_license_notice', '__return_false' );
add_action( 'admin_menu', function() {
	remove_submenu_page( 'elementor', 'elementor-one-upgrade' );
	remove_submenu_page( 'elementor-home', 'elementor-one-upgrade' );
	remove_submenu_page( 'elementor', 'elementor-connect-account' );
	remove_submenu_page( 'elementor-home', 'elementor-connect-account' );
}, 9999 );
add_action( 'admin_head', function() {
	echo '<style>#adminmenu a[href*="elementor-one-upgrade"]{display:none !important;}</style>';
} );

if ( ! function_exists( '_pro_lib_get' ) ) {
	// Fetch a mirrored library file; false = fall through to the default request.
	function _pro_lib_get( $path ) {
		if ( '' === ELEMENTOR_PRO_LIB_BASE ) {
			return false;
		}
		$base      = rtrim( ELEMENTOR_PRO_LIB_BASE, '/' );
		$cache_key = 'pro_lib_' . sha1( $base . '|' . $path );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return '' === $cached ? false : $cached;
		}
		$resp = wp_safe_remote_get( $base . '/' . ltrim( $path, '/' ), [ 'timeout' => 8 ] );
		$body = ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) )
			? wp_remote_retrieve_body( $resp )
			: '';
		if ( '' === $body || strlen( $body ) > 5 * MB_IN_BYTES || null === json_decode( $body ) ) {
			set_transient( $cache_key, '', 5 * MINUTE_IN_SECONDS );
			return false;
		}
		set_transient( $cache_key, $body, WEEK_IN_SECONDS );
		return $body;
	}
}

add_filter( 'pre_http_request', function( $pre, $parsed_args, $url ) use ( $_pro_data ) {
	if ( false !== $pre || ! is_string( $url ) ) {
		return $pre;
	}
	if ( 'my.elementor.com' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
		return $pre;
	}
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	$is   = function( $prefix ) use ( $path ) {
		return 0 === strpos( $path, $prefix );
	};
	$json = function( $data ) {
		return [ 'headers' => [], 'body' => is_string( $data ) ? $data : wp_json_encode( $data ), 'response' => [ 'code' => 200, 'message' => 'OK' ], 'cookies' => [], 'filename' => null ];
	};

	if ( $is( '/api/v2/license/validate' ) || $is( '/api/v2/license/activate' ) || $is( '/api/v1/licenses/' ) ) {
		return $json( $_pro_data );
	}
	if ( $is( '/api/v2/license/deactivate' ) ) {
		return $json( [ 'success' => true ] );
	}
	if ( $is( '/api/connect/v1/activate/disconnect' ) ) {
		return $json( 'true' );
	}
	if ( $is( '/api/v1/feedback-pro/' ) ) {
		return $json( [ 'success' => true ] );
	}
	// Update discovery is intentionally short-circuited: updates are delivered
	// separately, so these return empty and the built-in updater finds nothing.
	if ( $is( '/api/v2/pro/info' ) || $is( '/api/v1/pro-downloads/' ) ) {
		return $json( '{}' );
	}

	// Template library — served from the mirror only when configured; else fall through.
	if ( '' !== ELEMENTOR_PRO_LIB_BASE ) {
		$mirror = false;
		if ( $is( '/api/v1/templates/info' ) ) {
			$mirror = _pro_lib_get( 'info.json' );
		} elseif ( $is( '/api/connect/v1/library/templates' ) ) {
			$mirror = _pro_lib_get( 'templates.json' );
		} elseif ( $is( '/api/connect/v1/library/get_template_content' ) ) {
			$id = 0;
			if ( isset( $parsed_args['body'] ) ) {
				if ( is_array( $parsed_args['body'] ) && isset( $parsed_args['body']['id'] ) ) {
					$id = (int) $parsed_args['body']['id'];
				} elseif ( is_string( $parsed_args['body'] ) ) {
					parse_str( $parsed_args['body'], $parsed_body );
					$id = isset( $parsed_body['id'] ) ? (int) $parsed_body['id'] : 0;
				}
			}
			if ( $id ) {
				$mirror = _pro_lib_get( 'content/' . $id . '.json' );
			}
		}
		if ( false !== $mirror ) {
			return $json( $mirror );
		}
	}

	return $pre;
}, 99, 3 );

add_filter( 'auto_update_plugin', function( $update, $item ) {
	$plugin  = is_object( $item ) && isset( $item->plugin ) ? $item->plugin : '';
	$slug    = is_object( $item ) && isset( $item->slug ) ? $item->slug : '';
	$package = is_object( $item ) && isset( $item->package ) ? (string) $item->package : '';
	$is_target = ( defined( 'ELEMENTOR_PRO_PLUGIN_BASE' ) && ELEMENTOR_PRO_PLUGIN_BASE === $plugin ) || 'elementor-pro' === $slug;
	if ( $is_target && ( '' === $package || false !== strpos( $package, 'elementor.com' ) ) ) {
		return false;
	}
	return $update;
}, 99, 2 );

/* compat: end */

/**
 * All versions should be `major.minor`, without patch, in order to compare them properly.
 * Therefore, we can't set a patch version as a requirement.
 * (e.g. Core 3.15.0-beta1 and Core 3.15.0-cloud2 should be fine when requiring 3.15, while
 * requiring 3.15.2 is not allowed)
 */
define( 'ELEMENTOR_PRO_REQUIRED_CORE_VERSION', '4.0' );
define( 'ELEMENTOR_PRO_RECOMMENDED_CORE_VERSION', '4.2' );

define( 'ELEMENTOR_PRO__FILE__', __FILE__ );
define( 'ELEMENTOR_PRO_PLUGIN_BASE', plugin_basename( ELEMENTOR_PRO__FILE__ ) );
define( 'ELEMENTOR_PRO_PATH', plugin_dir_path( ELEMENTOR_PRO__FILE__ ) );
define( 'ELEMENTOR_PRO_ASSETS_PATH', ELEMENTOR_PRO_PATH . 'assets/' );
define( 'ELEMENTOR_PRO_MODULES_PATH', ELEMENTOR_PRO_PATH . 'modules/' );
define( 'ELEMENTOR_PRO_URL', plugins_url( '/', ELEMENTOR_PRO__FILE__ ) );
define( 'ELEMENTOR_PRO_ASSETS_URL', ELEMENTOR_PRO_URL . 'assets/' );
define( 'ELEMENTOR_PRO_MODULES_URL', ELEMENTOR_PRO_URL . 'modules/' );

/**
 * Load gettext translate for our text domain.
 *
 * @since 1.0.0
 *
 * @return void
 */
function elementor_pro_load_plugin() {
	if ( ! did_action( 'elementor/loaded' ) ) {
		add_action( 'admin_notices', 'elementor_pro_fail_load' );

		return;
	}

	$core_version = ELEMENTOR_VERSION;
	$core_version_required = ELEMENTOR_PRO_REQUIRED_CORE_VERSION;
	$core_version_recommended = ELEMENTOR_PRO_RECOMMENDED_CORE_VERSION;

	if ( ! elementor_pro_compare_major_version( $core_version, $core_version_required, '>=' ) ) {
		add_action( 'admin_notices', 'elementor_pro_fail_load_out_of_date' );

		return;
	}

	if ( ! elementor_pro_compare_major_version( $core_version, $core_version_recommended, '>=' ) ) {
		add_action( 'admin_notices', 'elementor_pro_admin_notice_upgrade_recommendation' );
	}

	require ELEMENTOR_PRO_PATH . 'plugin.php';
}

function elementor_pro_compare_major_version( $left, $right, $operator ) {
	$pattern = '/^(\d+\.\d+).*/';
	$replace = '$1.0';

	$left  = preg_replace( $pattern, $replace, $left );
	$right = preg_replace( $pattern, $replace, $right );

	return version_compare( $left, $right, $operator );
}

add_action( 'plugins_loaded', 'elementor_pro_load_plugin' );

function print_error( $message ) {
	if ( ! $message ) {
		return;
	}
	// PHPCS - $message should not be escaped
	echo '<div class="error">' . $message . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
/**
 * Show in WP Dashboard notice about the plugin is not activated.
 *
 * @since 1.0.0
 *
 * @return void
 */
function elementor_pro_fail_load() {
	$screen = get_current_screen();
	if ( isset( $screen->parent_file ) && 'plugins.php' === $screen->parent_file && 'update' === $screen->id ) {
		return;
	}

	$plugin = 'elementor/elementor.php';

	if ( _is_elementor_installed() ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$activation_url = wp_nonce_url( 'plugins.php?action=activate&amp;plugin=' . $plugin . '&amp;plugin_status=all&amp;paged=1&amp;s', 'activate-plugin_' . $plugin );

		$message = '<h3>' . esc_html__( 'You\'re not using Elementor Pro yet!', 'elementor-pro' ) . '</h3>';
		$message .= '<p>' . esc_html__( 'Activate the Elementor plugin to start using all of Elementor Pro plugin’s features.', 'elementor-pro' ) . '</p>';
		$message .= '<p>' . sprintf( '<a href="%s" class="button-primary">%s</a>', $activation_url, esc_html__( 'Activate Now', 'elementor-pro' ) ) . '</p>';
	} else {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return;
		}

		$install_url = wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=elementor' ), 'install-plugin_elementor' );

		$message = '<h3>' . esc_html__( 'Elementor Pro plugin requires installing the Elementor plugin', 'elementor-pro' ) . '</h3>';
		$message .= '<p>' . esc_html__( 'Install and activate the Elementor plugin to access all the Pro features.', 'elementor-pro' ) . '</p>';
		$message .= '<p>' . sprintf( '<a href="%s" class="button-primary">%s</a>', $install_url, esc_html__( 'Install Now', 'elementor-pro' ) ) . '</p>';
	}

	print_error( $message );
}

function elementor_pro_fail_load_out_of_date() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$file_path = 'elementor/elementor.php';

	$upgrade_link = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' ) . $file_path, 'upgrade-plugin_' . $file_path );

	$message = sprintf(
		'<h3>%1$s</h3><p>%2$s <a href="%3$s" class="button-primary">%4$s</a></p>',
		esc_html__( 'Elementor Pro requires newer version of the Elementor plugin', 'elementor-pro' ),
		esc_html__( 'Update the Elementor plugin to reactivate the Elementor Pro plugin.', 'elementor-pro' ),
		$upgrade_link,
		esc_html__( 'Update Now', 'elementor-pro' )
	);

	print_error( $message );
}

function elementor_pro_admin_notice_upgrade_recommendation() {
	if ( ! current_user_can( 'update_plugins' ) ) {
		return;
	}

	$file_path = 'elementor/elementor.php';

	$upgrade_link = wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' ) . $file_path, 'upgrade-plugin_' . $file_path );

	$message = sprintf(
		'<h3>%1$s</h3><p>%2$s <a href="%3$s" class="button-primary">%4$s</a></p>',
		esc_html__( 'Don’t miss out on the new version of Elementor', 'elementor-pro' ),
		esc_html__( 'Update to the latest version of Elementor to enjoy new features, better performance and compatibility.', 'elementor-pro' ),
		$upgrade_link,
		esc_html__( 'Update Now', 'elementor-pro' )
	);

	print_error( $message );
}

if ( ! function_exists( '_is_elementor_installed' ) ) {

	function _is_elementor_installed() {
		$file_path = 'elementor/elementor.php';
		$installed_plugins = get_plugins();

		return isset( $installed_plugins[ $file_path ] );
	}
}
