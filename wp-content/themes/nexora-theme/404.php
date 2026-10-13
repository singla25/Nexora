<?php
/**
 * 404 page.
 *
 * @package Nexora_Theme
 */

get_header();

// An Elementor Pro "404" template replaces the built-in content below.
if ( function_exists( 'elementor_theme_do_location' ) && elementor_theme_do_location( 'single' ) ) {
	get_footer();
	return;
}
?>

<section class="nxt-section nxt-404">
	<div class="nxt-container">
		<p class="nxt-eyebrow"><?php esc_html_e( '404', 'nexora-theme' ); ?></p>
		<h1 class="nxt-h1"><?php esc_html_e( 'Page not found', 'nexora-theme' ); ?></h1>
		<p class="nxt-intro" style="margin:0 auto 28px;">
			<?php esc_html_e( 'The page you are looking for does not exist or may have moved.', 'nexora-theme' ); ?>
		</p>
		<div class="nxt-hero__cta" style="justify-content:center;">
			<?php
			nxt_button( __( 'Go to homepage', 'nexora-theme' ), home_url( '/' ), 'primary' );
			if ( is_user_logged_in() ) {
				nxt_button( __( 'Your profile', 'nexora-theme' ), nxt_profile_url(), 'secondary' );
			} else {
				nxt_button( __( 'Log in', 'nexora-theme' ), home_url( '/login-page/' ), 'secondary' );
			}
			?>
		</div>
	</div>
</section>

<?php get_footer(); ?>
