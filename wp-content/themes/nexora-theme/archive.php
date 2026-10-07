<?php
/**
 * Archive listing.
 *
 * @package Nexora_Theme
 */

get_header();

nxt_page_header( __( 'Archive', 'nexora-theme' ), wp_strip_all_tags( get_the_archive_title() ) );
?>

<section class="nxt-section">
	<div class="nxt-container">
		<?php if ( have_posts() ) : ?>
			<div class="nxt-post-list">
				<?php while ( have_posts() ) : the_post(); ?>
					<article class="nxt-post-card">
						<p class="nxt-post-meta"><?php echo esc_html( get_the_date() ); ?></p>
						<h2><a href="<?php the_permalink(); ?>" style="color:inherit;"><?php the_title(); ?></a></h2>
						<p><?php echo esc_html( get_the_excerpt() ); ?></p>
					</article>
				<?php endwhile; ?>
			</div>
			<?php the_posts_pagination(); ?>
		<?php else : ?>
			<p><?php esc_html_e( 'Nothing found here yet.', 'nexora-theme' ); ?></p>
		<?php endif; ?>
	</div>
</section>

<?php get_footer(); ?>
