<?php
/**
 * tabs/content.php
 *
 * Visible only to user-owners (tab visibility enforced by get_visible_tabs).
 * Shows a feed of other users' content + Add New / History actions.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$current_profile_id = (int) get_user_meta( $context['current_user_id'], '_profile_id', true );

$all_posts = get_posts([
    'post_type'      => 'user_content',
    'posts_per_page' => -1,
]);

// Filter out the owner's own posts (they use History for that)
$feed_posts = array_filter( $all_posts, function( $post ) use ( $current_profile_id ) {
    return (int) get_post_meta( $post->ID, 'user_profile_id', true ) !== $current_profile_id;
} );
?>

<div class="content-header">
    <div class="content-left">
        <h3>Content</h3>
        <span class="content-sub">See what others are sharing</span>
    </div>
    <div class="content-right">
        <button class="content-tab" data-type="add">Add New</button>
        <button class="content-tab" data-type="history">History</button>
    </div>
</div>

<div class="content-box">

    <?php if ( ! empty( $feed_posts ) ) :
        foreach ( $feed_posts as $post ) :

            $author_pid  = (int) get_post_meta( $post->ID, 'user_profile_id', true );
            $image       = get_the_post_thumbnail_url( $post->ID, 'medium' );
            $user_name   = get_post_meta( $post->ID, 'user_name', true );
            $full_name   = trim(
                get_post_meta( $author_pid, 'first_name', true ) . ' ' .
                get_post_meta( $author_pid, 'last_name',  true )
            );
            $date         = get_the_date( 'Y-m-d H:i', $post->ID );
            $profile_link = NEXORA_DASHBOARD_HELPER::get_profile_url( $author_pid );
    ?>

        <div class="content-card"
             data-title="<?php echo esc_attr( $post->post_title ); ?>"
             data-content="<?php echo esc_attr( $post->post_content ); ?>"
             data-image="<?php echo esc_url( $image ); ?>"
             data-username="<?php echo esc_attr( $user_name ); ?>"
             data-fullname="<?php echo esc_attr( $full_name ); ?>"
             data-date="<?php echo esc_attr( $date ); ?>"
             data-profile="<?php echo esc_url( $profile_link ); ?>">

            <?php if ( $image ) : ?>
                <img src="<?php echo esc_url( $image ); ?>" class="content-img" alt="">
            <?php endif; ?>

            <div class="content-body">
                <a href="<?php echo esc_url( $profile_link ); ?>"
                   class="content-user"
                   target="_blank"
                   onclick="event.stopPropagation();">
                    <?php echo esc_html( $user_name ); ?>
                </a>
                <h4 class="content-title view-post"><?php echo esc_html( $post->post_title ); ?></h4>
            </div>
        </div>

    <?php endforeach;
    else : ?>

        <div class="empty-content">
            <div class="empty-icon">📭</div>
            <h3>No Content Yet</h3>
            <p>No one else has posted anything yet.</p>
        </div>

    <?php endif; ?>

</div><!-- .content-box -->
