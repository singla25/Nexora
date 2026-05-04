<?php
/**
 * partials/content-history-table.php
 *
 * Requires: $posts (array of WP_Post)
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>

<table class="nexora-table">
    <thead>
        <tr>
            <th>Title</th>
            <th>Date</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>

    <?php if ( $posts ) :
        foreach ( $posts as $post ) :
            $image = get_the_post_thumbnail_url( $post->ID, 'medium' );
            $date  = get_the_date( 'Y-m-d H:i', $post->ID );
        ?>
            <tr>
                <td><?php echo esc_html( $post->post_title ); ?></td>
                <td><?php echo esc_html( $date ); ?></td>
                <td>
                    <button class="view-content-btn btn btn-sm"
                            data-title="<?php echo esc_attr( $post->post_title ); ?>"
                            data-content="<?php echo esc_attr( $post->post_content ); ?>"
                            data-image="<?php echo esc_url( $image ); ?>"
                            data-date="<?php echo esc_attr( $date ); ?>">
                        View
                    </button>
                </td>
            </tr>
        <?php endforeach;
    else : ?>
        <tr>
            <td colspan="3" class="text-center">No content found.</td>
        </tr>
    <?php endif; ?>

    </tbody>
</table>
