<?php
/**
 * Comments.
 *
 * @package Nexora_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( post_password_required() ) {
	return;
}
?>
<div class="nxt-comments" style="margin-top:48px;">
	<?php if ( have_comments() ) : ?>
		<h2 class="nxt-h2"><?php comments_number( __( 'No comments', 'nexora-theme' ), __( '1 comment', 'nexora-theme' ), __( '% comments', 'nexora-theme' ) ); ?></h2>
		<ol class="nxt-comment-list">
			<?php wp_list_comments( array( 'style' => 'ol' ) ); ?>
		</ol>
		<?php the_comments_pagination(); ?>
	<?php endif; ?>

	<?php comment_form(); ?>
</div>
