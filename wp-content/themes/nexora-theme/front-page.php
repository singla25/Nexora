<?php
/**
 * Front page. Normally the page contains the [nexora_home] shortcode (the
 * plugin renders the hero, live stats and features). If the front page is
 * set to show latest posts, or the shortcode is missing, fall back to a
 * small dynamic hero so the site is never empty.
 *
 * @package Nexora_Theme
 */

get_header();

while ( have_posts() ) :
	the_post();

	if ( ! nxt_is_app_page() && ! trim( wp_strip_all_tags( get_the_content() ) ) ) :
		?>
		<section class="nxt-hero">
			<div class="nxt-container nxt-hero__grid">
				<div>
					<p class="nxt-eyebrow"><?php echo esc_html( get_bloginfo( 'description' ) ); ?></p>
					<h1><?php bloginfo( 'name' ); ?></h1>
					<div class="nxt-hero__cta">
						<?php
						if ( is_user_logged_in() ) {
							nxt_button( __( 'Go to your profile', 'nexora-theme' ), nxt_profile_url(), 'primary' );
						} else {
							nxt_button( __( 'Create your account', 'nexora-theme' ), home_url( '/registration-page/' ), 'primary' );
							nxt_button( __( 'Log in', 'nexora-theme' ), home_url( '/login-page/' ), 'secondary' );
						}
						?>
					</div>
				</div>
			</div>
		</section>
		<?php
	elseif ( nxt_is_app_page() ) :
		?>
		<div class="nxt-app-page"><?php the_content(); ?></div>
		<?php
	else :
		nxt_page_header( '', get_the_title() );
		?>
		<section class="nxt-section">
			<div class="nxt-container nxt-entry-content nxt-prose">
				<?php the_content(); ?>
			</div>
		</section>
		<?php
	endif;
endwhile;

get_footer();
