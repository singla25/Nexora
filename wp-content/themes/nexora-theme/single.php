<?php
/**
 * Single blog post.
 *
 * @package Nexora_Theme
 */

get_header();

while ( have_posts() ) :
	the_post();
	$nxt_blog_id = (int) get_option( 'page_for_posts' );
	?>
	<header class="nxt-page-header">
		<div class="nxt-container" style="padding-top:48px;padding-bottom:48px;">
			<?php if ( $nxt_blog_id ) : ?>
				<p class="nxt-breadcrumb">
					<a href="<?php echo esc_url( get_permalink( $nxt_blog_id ) ); ?>"><?php echo esc_html( get_the_title( $nxt_blog_id ) ); ?></a>
				</p>
			<?php endif; ?>
			<h1 class="nxt-h1"><?php the_title(); ?></h1>
			<p class="nxt-post-meta"><?php echo esc_html( get_the_date() ); ?></p>
		</div>
	</header>

	<section class="nxt-section">
		<div class="nxt-container">
			<?php if ( has_post_thumbnail() ) : ?>
				<div style="margin-bottom:32px;"><?php the_post_thumbnail( 'large' ); ?></div>
			<?php endif; ?>

			<div class="nxt-entry-content nxt-prose">
				<?php the_content(); ?>
			</div>

			<?php
			wp_link_pages( array(
				'before' => '<nav class="nxt-page-links">' . esc_html__( 'Pages:', 'nexora-theme' ),
				'after'  => '</nav>',
			) );

			if ( comments_open() || get_comments_number() ) {
				comments_template();
			}
			?>
		</div>
	</section>
	<?php
endwhile;

get_footer();
