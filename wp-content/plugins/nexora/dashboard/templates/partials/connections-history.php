<?php
/**
 * partials/connections-history.php
 *
 * Rendered server-side and returned as HTML via AJAX.
 * Requires: $received (array of WP_Post), $sent (array of WP_Post)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Render a single history card row.
 */
function nexora_history_card( int $other_profile_id, int $connection_id, string $status ): void {

    $username = get_post_meta( $other_profile_id, 'user_name', true );
    $name     = trim(
        get_post_meta( $other_profile_id, 'first_name', true ) . ' ' .
        get_post_meta( $other_profile_id, 'last_name',  true )
    );
    $image    = NEXORA_DASHBOARD_HELPER::get_profile_image( $other_profile_id );
    $link     = NEXORA_DASHBOARD_HELPER::get_profile_url( $other_profile_id );
    $date     = get_the_date( 'd M Y',  $connection_id );
    $time     = get_the_time( 'h:i A',  $connection_id );
    ?>

    <div class="history-card">
        <img src="<?php echo esc_url( $image ); ?>" class="history-avatar" alt="">

        <a href="<?php echo esc_url( $link ); ?>" target="_blank" class="history-username">
            <?php echo esc_html( $username ); ?>
        </a>

        <div class="history-meta">
            <div class="history-name"><?php echo esc_html( $name ); ?></div>
            <div class="history-time"><?php echo esc_html( "{$date} • {$time}" ); ?></div>
        </div>

        <span class="history-status <?php echo esc_attr( $status ); ?>">
            <?php echo esc_html( ucfirst( $status ) ); ?>
        </span>
    </div>

    <?php
}
?>

<div class="history-wrapper">

    <div class="history-section">
        <h3>📥 Received Requests</h3>
        <?php if ( $received ) :
            foreach ( $received as $conn ) :
                nexora_history_card(
                    (int) get_post_meta( $conn->ID, 'sender_profile_id', true ),
                    $conn->ID,
                    get_post_meta( $conn->ID, 'status', true )
                );
            endforeach;
        else : ?>
            <p class="history-empty">No received requests.</p>
        <?php endif; ?>
    </div>

    <div class="history-section">
        <h3>📤 Sent Requests</h3>
        <?php if ( $sent ) :
            foreach ( $sent as $conn ) :
                nexora_history_card(
                    (int) get_post_meta( $conn->ID, 'receiver_profile_id', true ),
                    $conn->ID,
                    get_post_meta( $conn->ID, 'status', true )
                );
            endforeach;
        else : ?>
            <p class="history-empty">No sent requests.</p>
        <?php endif; ?>
    </div>

</div><!-- .history-wrapper -->
