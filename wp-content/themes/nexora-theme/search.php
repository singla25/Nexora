<?php
/**
 * Search results.
 *
 * @package Nexora_Theme
 */

get_header();

nxt_page_header(
	__( 'Search results', 'nexora-theme' ),
	/* translators: %s: search query */
	sprintf( __( 'Results for "%s"', 'nexora-theme' ), get_search_query() )
);
?>

<section class="nxt-section">
	<div class="nxt-container">
		<?php if ( have_posts() ) : ?>
			<div class="nxt-post-list">
				<?php while ( have_posts() ) : the_post(); ?>
					<article class="nxt-post-card">
						<p class="nxt-post-meta"><?php echo esc_html( get_post_type_object( get_post_type() )->labels->singular_name ); ?></p>
						<h2><a href="<?php the_permalink(); ?>" style="color:inherit;"><?php the_title(); ?></a></h2>
						<p><?php echo esc_html( get_the_excerpt() ); ?></p>
					</article>
				<?php endwhile; ?>
			</div>
			<?php the_posts_pagination(); ?>
		<?php else : ?>
			<p><?php esc_html_e( 'No results found. Try a different search term.', 'nexora-theme' ); ?></p>
			<?php get_search_form(); ?>
		<?php endif; ?>
	</div>
</section>

<?php get_footer(); ?>
