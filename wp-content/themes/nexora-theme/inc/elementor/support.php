<?php
/**
 * Elementor integration: theme locations (so Elementor Pro's header /
 * footer / 404 templates replace the built-in ones), stylesheet loading in
 * the editor and on the site, and the small shortcodes the templates use.
 *
 * @package Nexora_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Let Elementor Pro (Theme Builder) take over header, footer, single, etc.
 */
function nxt_register_elementor_locations( $manager ) {
	$manager->register_all_core_location();
}
add_action( 'elementor/theme/register_locations', 'nxt_register_elementor_locations' );

/**
 * Section styles used by the bundled templates.
 */
function nxt_enqueue_elementor_css() {
	wp_enqueue_style( 'nxt-elementor', NXT_URI . '/assets/css/elementor.css', array( 'nxt-style' ), NXT_VERSION );
}
add_action( 'wp_enqueue_scripts', 'nxt_enqueue_elementor_css', 20 );

function nxt_enqueue_elementor_editor_css() {
	wp_enqueue_style( 'nxt-fonts', 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap', array(), null );
	wp_enqueue_style( 'nxt-style', NXT_URI . '/assets/css/style.css', array( 'nxt-fonts' ), NXT_VERSION );
	wp_enqueue_style( 'nxt-elementor', NXT_URI . '/assets/css/elementor.css', array( 'nxt-style' ), NXT_VERSION );
}
add_action( 'elementor/editor/after_enqueue_styles', 'nxt_enqueue_elementor_editor_css' );
add_action( 'elementor/preview/enqueue_styles', 'nxt_enqueue_elementor_editor_css' );

/* ---------- Shortcodes (dynamic site data) ---------- */

/**
 * [nxt_setting key="email" default="" link="mailto|tel"]
 * key: tagline, email, phone, address, site_name, or any key saved on
 * Appearance > Nexora Settings.
 */
function nxt_setting_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'key'     => '',
			'default' => '',
			'link'    => '',
		),
		$atts,
		'nxt_setting'
	);

	$key = sanitize_key( $atts['key'] );

	if ( 'site_name' === $key ) {
		return esc_html( get_bloginfo( 'name' ) );
	}

	$value = nxt_setting( $key, $atts['default'] );

	if ( '' === $value ) {
		return '';
	}

	if ( 'mailto' === $atts['link'] && is_email( $value ) ) {
		return '<a href="mailto:' . esc_attr( antispambot( $value ) ) . '">' . esc_html( antispambot( $value ) ) . '</a>';
	}

	if ( 'tel' === $atts['link'] ) {
		return '<a href="tel:' . esc_attr( preg_replace( '/[^0-9+]/', '', $value ) ) . '">' . esc_html( $value ) . '</a>';
	}

	return esc_html( $value );
}
add_shortcode( 'nxt_setting', 'nxt_setting_shortcode' );

/**
 * [nxt_logo] custom logo, or the site name with a letter mark.
 */
function nxt_logo_shortcode() {
	ob_start();
	?>
	<a class="nxt-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
		<?php if ( has_custom_logo() ) : ?>
			<?php the_custom_logo(); ?>
		<?php else : ?>
			<span class="nxt-logo__mark" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( get_bloginfo( 'name' ), 0, 1 ) ) ); ?></span>
			<span><?php bloginfo( 'name' ); ?></span>
		<?php endif; ?>
	</a>
	<?php
	return ob_get_clean();
}
add_shortcode( 'nxt_logo', 'nxt_logo_shortcode' );

/**
 * [nxt_year] current year.
 */
function nxt_year_shortcode() {
	return esc_html( gmdate( 'Y' ) );
}
add_shortcode( 'nxt_year', 'nxt_year_shortcode' );
