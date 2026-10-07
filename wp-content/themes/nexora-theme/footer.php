<?php
/**
 * Footer: sign-up banner, logo + tagline, footer menu, contact details and
 * social links. All of it comes from Appearance > Nexora Settings.
 *
 * @package Nexora_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

nxt_site_cta();

$nxt_phone   = nxt_setting( 'phone' );
$nxt_email   = nxt_setting( 'email' );
$nxt_address = nxt_setting( 'address' );
$nxt_social  = array(
	'facebook'  => 'Facebook',
	'twitter'   => 'X',
	'linkedin'  => 'LinkedIn',
	'instagram' => 'Instagram',
);
?>
</main>

<footer class="nxt-footer">
	<div class="nxt-container">
		<div class="nxt-footer__top">
			<div>
				<a class="nxt-logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php if ( has_custom_logo() ) : ?>
						<?php the_custom_logo(); ?>
					<?php else : ?>
						<span class="nxt-logo__mark" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( get_bloginfo( 'name' ), 0, 1 ) ) ); ?></span>
						<span><?php bloginfo( 'name' ); ?></span>
					<?php endif; ?>
				</a>
				<p class="nxt-footer__tagline"><?php echo esc_html( nxt_setting( 'tagline', get_bloginfo( 'description' ) ) ); ?></p>
			</div>

			<nav class="nxt-footer__nav" aria-label="<?php esc_attr_e( 'Footer', 'nexora-theme' ); ?>">
				<?php
				wp_nav_menu( array(
					'theme_location' => 'footer',
					'container'      => false,
					'items_wrap'     => '%3$s',
					'fallback_cb'    => 'nxt_default_footer_menu',
					'depth'          => 1,
				) );
				?>
			</nav>

			<div class="nxt-footer__social">
				<?php
				foreach ( $nxt_social as $key => $label ) {
					$url = nxt_setting( $key );
					if ( $url ) {
						printf(
							'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
							esc_url( $url ),
							esc_html( $label )
						);
					}
				}
				?>
			</div>
		</div>

		<div class="nxt-footer__meta">
			<?php if ( $nxt_address ) : ?>
				<span><?php echo esc_html( $nxt_address ); ?></span>
			<?php endif; ?>

			<?php if ( $nxt_phone ) : ?>
				<a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $nxt_phone ) ); ?>"><?php echo esc_html( $nxt_phone ); ?></a>
			<?php endif; ?>

			<?php if ( $nxt_email ) : ?>
				<a href="mailto:<?php echo esc_attr( $nxt_email ); ?>"><?php echo esc_html( $nxt_email ); ?></a>
			<?php endif; ?>

			<span>&copy; <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?></span>
		</div>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
