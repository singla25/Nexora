<?php
/**
 * Blog listing.
 *
 * @package Nexora_Theme
 */

get_header();

$nxt_blog_id = (int) get_option( 'page_for_posts' );

nxt_page_header(
	__( 'From the team', 'nexora-theme' ),
	$nxt_blog_id ? get_the_title( $nxt_blog_id ) : __( 'Blog', 'nexora-theme' )
);
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
						<a href="<?php the_permalink(); ?>"><?php esc_html_e( 'Read more', 'nexora-theme' ); ?> &rarr;</a>
					</article>
				<?php endwhile; ?>
			</div>

			<?php the_posts_pagination(); ?>
		<?php else : ?>
			<p><?php esc_html_e( 'Nothing published yet. Check back soon.', 'nexora-theme' ); ?></p>
		<?php endif; ?>
	</div>
</section>

<?php get_footer(); ?>
