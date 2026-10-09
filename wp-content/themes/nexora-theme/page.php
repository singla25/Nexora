<?php
/**
 * Generic page. Pages built from Nexora plugin shortcodes (login,
 * registration, profile, home) render full width with no title band.
 *
 * @package Nexora_Theme
 */

get_header();

while ( have_posts() ) :
	the_post();

	if ( nxt_is_app_page() ) :
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
