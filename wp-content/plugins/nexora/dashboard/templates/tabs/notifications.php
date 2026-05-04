<?php
/**
 * tabs/notifications.php
 *
 * Only reachable by owners (tab visibility enforced by get_visible_tabs).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$notification  = new NEXORA_Notification();
$notifications = $notification->get_notifications( $context['current_user_id'] );

/**
 * Format a notification row into a human-readable string.
 */
function nexora_format_noti_message( object $noti ): string {

    $actor = esc_html( $noti->actor_user_name );

    switch ( $noti->type ) {
        case 'request':  return "{$actor} sent you a connection request";
        case 'accepted': return "{$actor} accepted your connection request";
        case 'rejected': return "{$actor} rejected your connection request";
        case 'removed':  return "{$actor} removed the connection with you";
        case 'content':  return "{$actor} uploaded new content";
        default:         return esc_html( $noti->message );
    }
}
?>

<div class="notification-wrapper">

    <div class="notification-header">
        <h3>🔔 Notifications</h3>
    </div>

    <div class="notification-list">

        <?php if ( $notifications ) :
            foreach ( $notifications as $noti ) :

                $actor_profile_id = (int) get_user_meta( $noti->actor_user_id, '_profile_id', true );
                $actor_image      = NEXORA_DASHBOARD_HELPER::get_profile_image( $actor_profile_id );
                $message          = nexora_format_noti_message( $noti );
                $date_str         = date( 'd M Y • h:i A', strtotime( $noti->created_at ) );
        ?>

            <div class="notification-item <?php echo ! $noti->is_read ? 'unread' : ''; ?>">

                <div class="noti-avatar">
                    <img src="<?php echo esc_url( $actor_image ); ?>" alt="">
                </div>

                <div class="noti-content">
                    <div class="noti-top">
                        <span class="noti-message"><?php echo esc_html( $message ); ?></span>
                        <span class="noti-status <?php echo ! $noti->is_read ? 'new' : 'read'; ?>">
                            <?php echo ! $noti->is_read ? 'New' : 'Read'; ?>
                        </span>
                    </div>
                    <div class="noti-time"><?php echo esc_html( $date_str ); ?></div>
                </div>

                <button class="notification-view"
                        data-id="<?php echo esc_attr( $noti->id ); ?>"
                        data-type="received">
                    View
                </button>

            </div>

        <?php endforeach;
        else : ?>

            <div class="empty-notification empty-content">
                <div class="empty-icon">🔔</div>
                <h3>No Notifications Yet</h3>
                <p>You're all caught up 🎉<br>Notifications will appear here when you get updates.</p>
            </div>

        <?php endif; ?>

    </div><!-- .notification-list -->

</div><!-- .notification-wrapper -->
