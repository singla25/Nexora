<?php
/**
 * Escaping-safe output helpers. Every helper escapes its own output.
 *
 * @package Nexora_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read a value from the theme's settings option (Appearance > Nexora Settings).
 */
function nxt_setting( $key, $default = '' ) {
	$options = get_option( 'nxt_settings', array() );

	if ( is_array( $options ) && isset( $options[ $key ] ) && '' !== $options[ $key ] ) {
		return $options[ $key ];
	}

	return $default;
}

/**
 * URL of the logged-in member's profile page (admins land on /profile-page).
 */
function nxt_profile_url() {
	if ( ! is_user_logged_in() ) {
		return home_url( '/login-page/' );
	}

	$user = wp_get_current_user();

	if ( user_can( $user, 'manage_options' ) ) {
		return home_url( '/profile-page/' );
	}

	return home_url( '/profile-page/' . rawurlencode( $user->user_login ) );
}

/**
 * Avatar URL for the current member (profile image from the Nexora plugin, else Gravatar).
 */
function nxt_current_avatar() {
	$profile_id = (int) get_user_meta( get_current_user_id(), '_profile_id', true );
	$image_id   = $profile_id ? (int) get_post_meta( $profile_id, 'profile_image', true ) : 0;

	if ( $image_id ) {
		$url = wp_get_attachment_image_url( $image_id, 'thumbnail' );
		if ( $url ) {
			return $url;
		}
	}

	return get_avatar_url( get_current_user_id(), array( 'size' => 68 ) );
}

/**
 * Button / CTA link.
 *
 * @param string $style primary | secondary | ghost-inverse | nav
 */
function nxt_button( $text, $url, $style = 'primary', $arrow = false, $attrs = array() ) {
	$classes   = 'nxt-btn nxt-btn--' . sanitize_html_class( $style );
	$attr_html = '';

	foreach ( $attrs as $key => $value ) {
		$attr_html .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
	}

	printf(
		'<a class="%1$s" href="%2$s"%3$s>%4$s%5$s</a>',
		esc_attr( $classes ),
		esc_url( $url ),
		$attr_html, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		esc_html( $text ),
		$arrow ? ' <span class="nxt-btn__arrow" aria-hidden="true">&#8599;</span>' : ''
	);
}

function nxt_badge( $text, $style = 'default' ) {
	printf(
		'<span class="nxt-badge nxt-badge--%1$s">%2$s</span>',
		esc_attr( sanitize_html_class( $style ) ),
		esc_html( $text )
	);
}

/**
 * Dark call-to-action banner. $buttons: array of array( text, url, style, arrow ).
 */
function nxt_cta_banner( $heading, $buttons ) {
	?>
	<section class="nxt-cta-banner">
		<div class="nxt-container nxt-cta-banner__inner">
			<p class="nxt-cta-banner__heading"><?php echo esc_html( $heading ); ?></p>
			<div class="nxt-hero__cta">
				<?php
				foreach ( $buttons as $button ) {
					nxt_button(
						$button['text'],
						$button['url'],
						isset( $button['style'] ) ? $button['style'] : 'ghost-inverse',
						! empty( $button['arrow'] )
					);
				}
				?>
			</div>
		</div>
	</section>
	<?php
}

/**
 * The "sign up / go to profile" banner shown above the footer. Text and
 * button label come from the settings page.
 */
function nxt_site_cta() {
	if ( is_user_logged_in() ) {
		return;
	}

	nxt_cta_banner(
		nxt_setting( 'cta_heading', __( 'Join Nexora today and grow your network.', 'nexora-theme' ) ),
		array(
			array(
				'text'  => nxt_setting( 'cta_button_label', __( 'Create your account', 'nexora-theme' ) ),
				'url'   => home_url( '/registration-page/' ),
				'style' => 'ghost-inverse',
				'arrow' => true,
			),
		)
	);
}

/**
 * Page header band (eyebrow / title / lede).
 */
function nxt_page_header( $eyebrow, $title, $lede = '' ) {
	?>
	<header class="nxt-page-header">
		<div class="nxt-container nxt-section" style="padding-top:48px;padding-bottom:48px;">
			<?php if ( $eyebrow ) : ?>
				<p class="nxt-eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
			<?php endif; ?>
			<h1 class="nxt-h1"><?php echo esc_html( $title ); ?></h1>
			<?php if ( $lede ) : ?>
				<p class="nxt-intro"><?php echo esc_html( $lede ); ?></p>
			<?php endif; ?>
		</div>
	</header>
	<?php
}

/**
 * True when the page is built from a Nexora plugin shortcode (login,
 * registration, profile, home). Those pages bring their own layout, so the
 * theme renders them full width without its page-title band.
 */
function nxt_is_app_page( $post = null ) {
	$post = get_post( $post );

	if ( ! $post ) {
		return false;
	}

	foreach ( array( 'profile_login', 'profile_registration', 'profile_dashboard', 'nexora_home' ) as $tag ) {
		if ( has_shortcode( $post->post_content, $tag ) ) {
			return true;
		}
	}

	return false;
}
