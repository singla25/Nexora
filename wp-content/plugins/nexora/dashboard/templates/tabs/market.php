<?php
/**
 * tabs/market.php
 *
 * Visible to: user-owner and vendor-owner only.
 * (Tab visibility is enforced by NEXORA_DASHBOARD_HELPER::get_visible_tabs — never reached
 *  by viewers, guests, or any viewer role.)
 *
 * For vendor-owner: show vendor-centric description.
 * For user-owner:   show buyer/explorer description.
 *
 * To connect the marketplace module, use the filter:
 *   add_filter( 'nexora_marketplace_content', function( $html, $ctx ) {
 *       return do_shortcode( '[your_marketplace_shortcode]' );
 *   }, 10, 2 );
 *
 * Requires: $context, $profile_role
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Allow an external module to provide the marketplace HTML.
 * $context is passed so the module can render role-specific content.
 */
$market_content = apply_filters( 'nexora_marketplace_content', '', $context );

$subtitle = $profile_role === 'vendor'
    ? 'Manage your listings and orders'
    : 'Explore vendor products and services';
?>

<div class="market-header">
    <div class="market-left">
        <h3>Marketplace</h3>
        <span class="market-sub"><?php echo esc_html( $subtitle ); ?></span>
    </div>
</div>

<div class="market-body">

    <?php if ( $market_content ) : ?>

        <?php echo $market_content; // phpcs:ignore WordPress.Security.EscapeOutput -- sanitised by shortcode engine ?>

    <?php else : ?>

        <div class="empty-content">
            <div class="empty-icon">🛒</div>
            <h3>Marketplace Coming Soon</h3>
            <p>
                This section will display the marketplace once the module is connected.<br>
                <?php if ( $profile_role === 'vendor' ) : ?>
                    As a vendor, you'll be able to manage your listings and track orders here.
                <?php else : ?>
                    You'll be able to browse and purchase from vendors here.
                <?php endif; ?>
            </p>
        </div>

    <?php endif; ?>

</div><!-- .market-body -->
