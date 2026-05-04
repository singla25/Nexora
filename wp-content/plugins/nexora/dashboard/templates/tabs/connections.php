<?php
/**
 * tabs/connections.php
 *
 * Role matrix (vendor-viewer and guest-vendor never reach this tab):
 *
 *  owner (user or vendor)
 *    → Add / Requests / History / Chat sub-tab buttons
 *
 *  user-viewer (logged-in subscriber viewing another subscriber)
 *    → Total count card + mutual count
 *    → View All + View Mutual buttons (AJAX)
 *    Note: vendor viewers never reach this tab (hidden by get_visible_tabs)
 *
 *  guest viewing a user-profile
 *    → Total count card only (no mutual, no view all)
 *
 * Requires: $context, $is_owner, $is_logged_in, $profile_id
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$connections  = NEXORA_DASHBOARD_HELPER::get_connections( $profile_id );
$total        = count( $connections );

// Fix #4: Mutual count and Mutual button are only for logged-in USER viewers
// (not vendor viewers — vendors never reach this tab, but guard anyway).
$viewer_is_vendor = NEXORA_DASHBOARD_HELPER::visitor_is_vendor( $context );
$mutual_count     = 0;

if ( $is_logged_in && ! $is_owner && ! $viewer_is_vendor ) {
    $current_profile_id = (int) get_user_meta( $context['current_user_id'], '_profile_id', true );
    if ( $current_profile_id ) {
        $mutual_count = count(
            NEXORA_DASHBOARD_HELPER::get_mutual_connection_ids( $current_profile_id, $profile_id )
        );
    }
}
?>

<!-- ── Connections Header ──────────────────────────────────── -->
<div class="connection-header">

    <?php if ( $is_owner ) : ?>

        <div class="conn-left">
            <h3 id="conn-heading">Connections</h3>
            <span class="conn-sub">Manage your network</span>
        </div>

        <div class="conn-right">
            <button class="conn-tab" data-type="add">Add New</button>
            <button class="conn-tab" data-type="requests">Requests</button>
            <button class="conn-tab" data-type="history">History</button>
            <button class="conn-tab" data-type="chat">Chat</button>
        </div>

    <?php elseif ( $is_logged_in && ! $viewer_is_vendor ) : // user-viewer only ?>

        <div class="conn-left">
            <h3>Connections</h3>
            <span class="conn-sub">View their network</span>
        </div>

        <div class="conn-right">
            <button class="conn-tab" data-type="view-all-conn"
                    data-profile="<?php echo esc_attr( $profile_id ); ?>">
                All Connections
            </button>
            <!-- Fix #4: Mutual button hidden for vendor viewers -->
            <button class="conn-tab" data-type="view-common-conn"
                    data-profile="<?php echo esc_attr( $profile_id ); ?>">
                Mutual
            </button>
        </div>

    <?php else : // guest viewing a user-profile ?>

        <div class="conn-center">
            <h3>Connections</h3>
            <span class="conn-sub">Login to explore connections</span>
        </div>

    <?php endif; ?>

</div><!-- .connection-header -->


<!-- ── Connection Content ─────────────────────────────────── -->
<div id="connection-established">

    <?php if ( $is_owner ) : ?>

        <div class="establish-connection-cards">
            <?php if ( ! empty( $connections ) ) :
                $is_owner_card = true;
                include NEXORA_DASHBOARD_TEMPLATES . 'partials/connection-cards.php';
            else : ?>
                <div class="empty-content">
                    <div class="empty-icon">🤝</div>
                    <h3>No Connections Yet</h3>
                    <p>Start building your network by sending connection requests 🚀</p>
                    <button class="conn-tab" data-type="add">+ Find People</button>
                </div>
            <?php endif; ?>
        </div>

    <?php else : // viewer or guest ?>

        <div class="connection-summary-wrapper">
            <div class="connection-summary-card">
                <h2><?php echo (int) $total; ?></h2>
                <p>Connections</p>

                <!-- Fix #4: mutual count only shown to user-viewers, not vendor-viewers -->
                <?php if ( $is_logged_in && ! $viewer_is_vendor ) : ?>
                    <p class="mutual-count"><?php echo (int) $mutual_count; ?> Mutual</p>
                <?php endif; ?>

                <div class="connection-preview">
                    <?php foreach ( array_slice( $connections, 0, 3 ) as $user ) : ?>
                        <img src="<?php echo esc_url( $user['image'] ); ?>"
                             alt="<?php echo esc_attr( $user['username'] ); ?>">
                    <?php endforeach; ?>
                </div>

                <?php if ( $is_logged_in && ! $viewer_is_vendor ) : ?>
                    <button class="view-all-btn"
                            data-type="view-all-conn"
                            data-profile="<?php echo esc_attr( $profile_id ); ?>">
                        View All Connections
                    </button>
                <?php endif; ?>
            </div>
        </div>

    <?php endif; ?>

</div><!-- #connection-established -->
