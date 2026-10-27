<?php
/**
 * wp-config for the Docker setup. (committed as wp-config.docker.php because .gitignore ignores every wp-config.php) docker-compose.yml mounts this over the repository's wp-config.php INSIDE the container only;
 * the real (git-ignored, XAMPP) wp-config.php on the host is never touched. Local development only: the keys below are not secrets.
 *
 * @package Nexora
 */

$nx_env = static function ( $name, $fallback ) {
	$value = getenv( $name );
	return false === $value || '' === $value ? $fallback : $value;
};

define( 'DB_NAME', $nx_env( 'NEXORA_DB_NAME', 'nexora' ) );
define( 'DB_USER', $nx_env( 'NEXORA_DB_USER', 'nexora' ) );
define( 'DB_PASSWORD', $nx_env( 'NEXORA_DB_PASSWORD', 'nexora' ) );
define( 'DB_HOST', $nx_env( 'NEXORA_DB_HOST', 'db' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );

foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' ) as $nx_key ) {
	define( $nx_key, hash( 'sha256', 'nexora-docker-dev-' . $nx_key ) );
}

$table_prefix = 'wp_';

define( 'WP_HOME', 'http://localhost:' . $nx_env( 'NEXORA_PORT', '8080' ) . '/nexora' );
define( 'WP_SITEURL', WP_HOME );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', false );
define( 'WP_DEBUG_DISPLAY', false );
define( 'FS_METHOD', 'direct' );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once ABSPATH . 'wp-settings.php';
