<?php
/**
 * marketplace/templates/market-orders.php
 *
 * Shows two tabs:
 *   - "Purchases"  — orders where buyer_id  = current user (what they bought)
 *   - "Sales"      — orders where seller_id = current user (what they sold)
 *
 * Queries nx_orders joined with nx_products and wp_users.
 * Falls back gracefully if the table doesn't exist yet.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id   = get_current_user_id();
$purchases = NEXORA_MARKET_HELPER::get_purchases( $user_id );
$sales     = NEXORA_MARKET_HELPER::get_sales( $user_id );

/* ── helper: render one orders table ─────────────────────── */
function nx_render_orders_table( $rows, $mode ) {

    $counterpart_col = ( $mode === 'purchases' ) ? 'Seller' : 'Buyer';
    $counterpart_key = ( $mode === 'purchases' ) ? 'seller_name' : 'buyer_name';

    if ( empty( $rows ) ) {
        $label = ( $mode === 'purchases' ) ? 'purchases' : 'sales';
        $icon_svg = ( $mode === 'purchases' )
            ? '<svg viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg"><rect x="10" y="20" width="36" height="28" rx="5" fill="#bfdbfe" stroke="#3b82f6" stroke-width="2"/><path d="M20 20v-4a8 8 0 0116 0v4" stroke="#3b82f6" stroke-width="2" stroke-linecap="round"/><circle cx="22" cy="30" r="2" fill="#2563eb"/><circle cx="34" cy="30" r="2" fill="#2563eb"/><path d="M22 37c1.2 2 8.8 2 10 0" stroke="#2563eb" stroke-width="1.8" stroke-linecap="round"/></svg>'
            : '<svg viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="28" cy="28" r="20" fill="#dcfce7" stroke="#16a34a" stroke-width="2"/><path d="M28 17v22M22 22l6-5 6 5" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 34h14" stroke="#16a34a" stroke-width="2" stroke-linecap="round"/></svg>';
        $title = ( $mode === 'purchases' ) ? 'No purchases yet' : 'No sales yet';
        $desc  = ( $mode === 'purchases' )
            ? 'You haven\'t bought anything yet. Browse the marketplace to find products.'
            : 'You haven\'t made any sales yet. Add products to start selling.';
        echo '<div class="market-empty">';
        echo '<div class="market-empty-illustration">' . $icon_svg . '</div>';
        echo '<h4>' . esc_html( $title ) . '</h4>';
        echo '<p>' . esc_html( $desc ) . '</p>';
        echo '</div>';
        return;
    }
    ?>
    <table class="market-table">
        <thead>
            <tr>
                <th>Order</th>
                <th>Product</th>
                <th><?php echo esc_html( $counterpart_col ); ?></th>
                <th>Qty</th>
                <th>Total (₹)</th>
                <th>Order Status</th>
                <th>Payment</th>
                <th>Date</th>
                <?php if ( $mode === 'purchases' ) : ?>
                <th>WC Order</th>
                <?php endif; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ( $rows as $o ) :
                $order_status   = $o['order_status']   ?: 'pending';
                $payment_status = $o['payment_status'] ?: 'pending';
                $date           = date( 'd M Y', strtotime( $o['created_at'] ) );

                // Map WC-style statuses to mk-status classes
                $os_class = in_array( $order_status, [ 'completed', 'processing', 'pending', 'active' ], true )
                    ? $order_status : 'pending';
                $ps_class = ( $payment_status === 'paid' || $payment_status === 'completed' )
                    ? 'completed' : 'pending';
            ?>
            <tr>
                <td style="color:var(--mk-muted); font-size:12px;">#<?php echo esc_html( $o['id'] ); ?></td>
                <td style="font-weight:600;"><?php echo esc_html( $o['product_title'] ?: '—' ); ?></td>
                <td><?php echo esc_html( $o[ $counterpart_key ] ?: '—' ); ?></td>
                <td><?php echo (int) $o['quantity']; ?></td>
                <td style="font-weight:600; color:var(--mk-accent);">
                    ₹<?php echo number_format( (float) $o['total'], 2 ); ?>
                </td>
                <td><span class="mk-status <?php echo esc_attr( $os_class ); ?>"><?php echo esc_html( ucfirst( $order_status ) ); ?></span></td>
                <td><span class="mk-status <?php echo esc_attr( $ps_class ); ?>"><?php echo esc_html( ucfirst( $payment_status ) ); ?></span></td>
                <td style="color:var(--mk-muted); font-size:12.5px;"><?php echo esc_html( $date ); ?></td>
                <?php if ( $mode === 'purchases' ) : ?>
                <td>
                    <?php if ( $o['wc_order_id'] ) : ?>
                        <a href="<?php echo esc_url( wc_get_order( $o['wc_order_id'] ) ? wc_get_order( $o['wc_order_id'] )->get_view_order_url() : '#' ); ?>"
                           class="mk-btn mk-btn-ghost" style="font-size:12px;" target="_blank">
                            View #<?php echo (int) $o['wc_order_id']; ?>
                        </a>
                    <?php else : ?>
                        <span style="color:var(--mk-muted); font-size:12px;">—</span>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}
?>

<div class="market-panel">

    <h2>Orders</h2>

    <!-- Sub-tabs -->
    <div class="mk-subtabs" style="display:flex; gap:6px; margin-bottom:20px;">
        <button class="mk-btn mk-btn-primary mk-subtab-btn" data-subtab="purchases">
            🛍️ Purchases
            <?php if ( ! empty( $purchases ) ) : ?>
                <span class="mk-count-badge"><?php echo count( $purchases ); ?></span>
            <?php endif; ?>
        </button>
        <button class="mk-btn mk-btn-ghost mk-subtab-btn" data-subtab="sales">
            💰 Sales
            <?php if ( ! empty( $sales ) ) : ?>
                <span class="mk-count-badge"><?php echo count( $sales ); ?></span>
            <?php endif; ?>
        </button>
    </div>

    <!-- Purchases panel -->
    <div class="mk-subtab-panel" id="mk-panel-purchases">
        <?php nx_render_orders_table( $purchases, 'purchases' ); ?>
    </div>

    <!-- Sales panel -->
    <div class="mk-subtab-panel" id="mk-panel-sales" style="display:none;">
        <?php nx_render_orders_table( $sales, 'sales' ); ?>
    </div>

</div>

<style>
.mk-count-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: var(--mk-accent);
    color: #fff;
    border-radius: 10px;
    font-size: 11px;
    padding: 1px 6px;
    margin-left: 5px;
    font-weight: 700;
}
.mk-subtab-btn.active-sub .mk-count-badge { background: rgba(255,255,255,.35); }
</style>

<script>
jQuery( document ).on( 'click', '.mk-subtab-btn', function () {
    var target = jQuery( this ).data( 'subtab' );

    jQuery( '.mk-subtab-btn' )
        .removeClass( 'mk-btn-primary' )
        .addClass( 'mk-btn-ghost' );
    jQuery( this )
        .removeClass( 'mk-btn-ghost' )
        .addClass( 'mk-btn-primary' );

    jQuery( '.mk-subtab-panel' ).hide();
    jQuery( '#mk-panel-' + target ).show();
} );
</script>
