<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'nexora' );

/** Database username */
define( 'DB_USER', 'admin' );

/** Database password */
define( 'DB_PASSWORD', 'redhat' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '<b+HsL<K:-[J}1SzN.]ZW+vF1Lb$4*Jkp#qHwPN|:S1/XIHH,Nb*y*Y!N%WuA&t ' );
define( 'SECURE_AUTH_KEY',  ')Y7V.5]|1zsFjt#}w)47Kcv)kL<fC%A.I7{wa1V_;/_;!HH#(>DWwYYB#W:>jkPb' );
define( 'LOGGED_IN_KEY',    'VE0fK{3J(0Vc2K_erbU0%,TPvt@E?CI)2!b_8#67h|spOawf ?5&9]u<sJmIwXKz' );
define( 'NONCE_KEY',        'R%(vQ=(kg<RGI1xaNao.w*]$keMoIJd9 a1%*w7p+L80dk~I,`Dudp[2/IRIN]i%' );
define( 'AUTH_SALT',        'vDwxFZ58?02omX=J;S ek&ty9K8#Zd#RhANo2Q0h[ut#=4a4qpAB9W!#E_Ex0sO%' );
define( 'SECURE_AUTH_SALT', '=VYruo<b%PHm+mk<kN+E}k;-6.:o<$ti4;&DioGNWb;(hMfryBIYt-EN(S#z&=c:' );
define( 'LOGGED_IN_SALT',   '1 ]fpdD<*KP1gJ,CjH6I{e]i,gtT.F=:3qF^zm*uZ6{&b:m0@WkNRZ|}_oX5e7uG' );
define( 'NONCE_SALT',       ',zepTblQ]VZ#T5|0ZZZFjyF|B.=c^[Nl1D4a}Hueb+k%ecIC~s/[c8SYgTvQ 7Ln' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

/* Add any custom values between this line and the "stop editing" line. */


define( 'FS_METHOD', 'direct' );
/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
