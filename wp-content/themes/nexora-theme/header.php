<?php
/**
 * Header: logo, primary navigation, and an account area that depends on
 * whether the visitor is logged in.
 *
 * @package Nexora_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="nxt-skip-link" href="#nxt-main"><?php esc_html_e( 'Skip to content', 'nexora-theme' ); ?></a>

<?php if ( ! function_exists( 'elementor_theme_do_location' ) || ! elementor_theme_do_location( 'header' ) ) : ?>
<header class="nxt-header">
	<div class="nxt-container nxt-header__inner">
		<a class="nxt-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php if ( has_custom_logo() ) : ?>
				<?php the_custom_logo(); ?>
			<?php else : ?>
				<span class="nxt-logo__mark" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( get_bloginfo( 'name' ), 0, 1 ) ) ); ?></span>
				<span><?php bloginfo( 'name' ); ?></span>
			<?php endif; ?>
		</a>

		<button type="button" class="nxt-nav__toggle" aria-controls="nxt-primary-nav" aria-expanded="false">
			<span class="screen-reader-text"><?php esc_html_e( 'Menu', 'nexora-theme' ); ?></span>
		</button>

		<nav class="nxt-nav" id="nxt-primary-nav" aria-label="<?php esc_attr_e( 'Primary', 'nexora-theme' ); ?>">
			<?php
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'container'      => false,
				'menu_class'     => 'nxt-nav__list',
				'fallback_cb'    => 'nxt_default_primary_menu',
				'depth'          => 1,
			) );

			if ( is_user_logged_in() ) :
				$nxt_user = wp_get_current_user();
				?>
				<a class="nxt-nav__user" href="<?php echo esc_url( nxt_profile_url() ); ?>">
					<img class="nxt-nav__avatar" src="<?php echo esc_url( nxt_current_avatar() ); ?>" alt="" width="34" height="34" />
					<span><?php echo esc_html( $nxt_user->display_name ); ?></span>
				</a>
				<?php
				nxt_button( __( 'Log out', 'nexora-theme' ), wp_logout_url( home_url( '/login-page/' ) ), 'secondary' );
			else :
				nxt_button( __( 'Log in', 'nexora-theme' ), home_url( '/login-page/' ), 'secondary' );
				nxt_button( __( 'Sign up', 'nexora-theme' ), home_url( '/registration-page/' ), 'nav' );
			endif;
			?>
		</nav>
	</div>
</header>
<?php endif; ?>

<main id="nxt-main">
