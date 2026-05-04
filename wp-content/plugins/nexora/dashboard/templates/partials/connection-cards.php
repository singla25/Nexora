<?php
/**
 * partials/connection-cards.php
 *
 * Reusable connection card grid.
 * Used by: owner inline view, AJAX view-all, AJAX mutual.
 *
 * Requires:
 *   $connections       array   from NEXORA_DASHBOARD_HELPER::get_connections()
 *   $is_owner          bool    show Remove button when true
 *   $show_mutual_badge bool    show "Mutual" badge when true  (optional, default false)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$show_mutual_badge = $show_mutual_badge ?? false;

if ( empty( $connections ) ) : ?>
    <div class="empty-content">
        <div class="empty-icon">🤝</div>
        <p>No connections found.</p>
    </div>
    <?php return;
endif;

foreach ( $connections as $user ) : ?>

    <div class="establish-connection-card">

        <div class="conn-cover"></div>

        <div class="conn-avatar">
            <img src="<?php echo esc_url( $user['image'] ); ?>"
                 alt="<?php echo esc_attr( $user['username'] ); ?>">
        </div>

        <div class="conn-body">

            <a href="<?php echo esc_url( $user['profile_link'] ); ?>"
               class="conn-username"
               target="_blank">
                <?php echo esc_html( $user['username'] ); ?>
            </a>

            <p class="conn-name"><?php echo esc_html( $user['name'] ); ?></p>

            <?php if ( $show_mutual_badge ) : ?>
                <span class="mutual-badge">Mutual</span>
            <?php endif; ?>

            <?php if ( $is_owner && isset( $user['connection_id'] ) ) : ?>
                <button class="remove-connection-btn"
                        data-id="<?php echo esc_attr( $user['connection_id'] ); ?>">
                    Remove
                </button>
            <?php endif; ?>

        </div>
    </div>

<?php endforeach;
