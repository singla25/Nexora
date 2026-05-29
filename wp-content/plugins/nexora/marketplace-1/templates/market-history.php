<?php
/**
 * marketplace/templates/market-history.php
 *
 * Shows a combined activity timeline from nx_activity_log for the
 * current user, plus two quick-reference lists:
 *   - Products they uploaded (from nx_products)
 *   - Purchases they made (from nx_orders)
 *
 * All queries gracefully no-op if tables don't exist yet.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id = get_current_user_id();

/* ── All data fetched through the Helper — no raw queries in templates */
$log_rows = NEXORA_MARKET_HELPER::get_activity( $user_id );
$uploads  = NEXORA_MARKET_HELPER::get_my_products( $user_id );
$bought   = NEXORA_MARKET_HELPER::get_purchases( $user_id, 20 );

/* ── Icon / colour map for action_type ───────────────────── */
$action_map = [
    'product_created'   => [ '📦', 'var(--mk-accent)'   , 'Added a product'  ],
    'product_updated'   => [ '✏️', 'var(--mk-warning)'  , 'Updated a product' ],
    'product_deleted'   => [ '🗑️', 'var(--mk-danger)'   , 'Removed a product' ],
    'order_placed'      => [ '🛍️', 'var(--mk-success)'  , 'Placed an order'   ],
    'order_completed'   => [ '✅', 'var(--mk-success)'  , 'Order completed'   ],
    'api_source_added'  => [ '🔗', 'var(--mk-accent)'   , 'Connected API'     ],
    'csv_import'        => [ '📄', 'var(--mk-warning)'  , 'CSV import'        ],
    'payout_received'   => [ '💰', 'var(--mk-success)'  , 'Payout received'   ],
];

function nx_action_label( $action_type, $meta_raw, $action_map ) {
    $meta = $meta_raw ? json_decode( $meta_raw, true ) : [];
    if ( isset( $action_map[ $action_type ] ) ) {
        $label = $action_map[ $action_type ][2];
        if ( ! empty( $meta['title'] ) ) {
            $label .= ' — ' . esc_html( $meta['title'] );
        }
        return $label;
    }
    return esc_html( ucwords( str_replace( '_', ' ', $action_type ) ) );
}
?>

<div class="market-panel">

    <h2>History</h2>

    <!-- Sub-tabs -->
    <div class="mk-subtabs" style="display:flex; gap:6px; margin-bottom:24px;">
        <button class="mk-btn mk-btn-primary mk-hist-tab" data-hist="activity">🕐 Activity</button>
        <button class="mk-btn mk-btn-ghost  mk-hist-tab" data-hist="uploads">📦 My Uploads</button>
        <button class="mk-btn mk-btn-ghost  mk-hist-tab" data-hist="purchases">🛍️ Purchases Made</button>
    </div>

    <!-- ── Activity panel ─────────────────────────────────── -->
    <div class="mk-hist-panel" id="mk-hist-activity">

        <?php if ( empty( $log_rows ) ) : ?>

            <div class="market-empty" style="padding:40px 0 32px;">
                <div class="market-empty-illustration" style="width:100px;height:100px;">
                    <svg viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="28" cy="28" r="20" fill="#eff6ff" stroke="#3b82f6" stroke-width="2"/>
                        <circle cx="28" cy="28" r="2" fill="#3b82f6"/>
                        <path d="M28 16v12l7 7" stroke="#3b82f6" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M44 12l1 2.5 2.5 1-2.5 1L44 19l-1-2.5L40.5 15.5l2.5-1z" fill="#93c5fd"/>
                    </svg>
                </div>
                <h4>No activity yet</h4>
                <p>Actions like adding products, placing orders, and receiving payouts will appear here.</p>
            </div>

        <?php else : ?>

            <div class="market-timeline">

                <?php foreach ( $log_rows as $row ) :
                    $at     = $row['action_type'];
                    $icon   = $action_map[ $at ][0] ?? '📋';
                    $color  = $action_map[ $at ][1] ?? 'var(--mk-muted)';
                    $label  = nx_action_label( $at, $row['meta'], $action_map );
                    $date   = date( 'd M Y, g:i a', strtotime( $row['created_at'] ) );
                ?>

                <div class="mk-timeline-item">
                    <div class="mk-tl-icon" style="background:color-mix(in srgb, <?php echo $color; ?> 12%, transparent); color:<?php echo $color; ?>;">
                        <?php echo $icon; ?>
                    </div>
                    <div class="mk-tl-body">
                        <div class="mk-tl-title"><?php echo esc_html( $label ); ?></div>
                        <div class="mk-tl-meta"><?php echo esc_html( $date ); ?></div>
                    </div>
                </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    </div>

    <!-- ── Uploads panel ──────────────────────────────────── -->
    <div class="mk-hist-panel" id="mk-hist-uploads" style="display:none;">

        <?php if ( empty( $uploads ) ) : ?>

            <div class="market-empty" style="padding:40px 0 32px;">
                <div class="market-empty-illustration" style="width:100px;height:100px;">
                    <svg viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="10" y="24" width="36" height="26" rx="4" fill="#dbeafe" stroke="#3b82f6" stroke-width="2"/>
                        <path d="M10 30h36" stroke="#3b82f6" stroke-width="1.5"/>
                        <path d="M10 24l8-10h20l8 10" stroke="#3b82f6" stroke-width="2" stroke-linejoin="round" fill="#eff6ff"/>
                        <path d="M28 14v10" stroke="#3b82f6" stroke-width="1.5" stroke-dasharray="2 2"/>
                    </svg>
                </div>
                <h4>No uploads yet</h4>
                <p>Products you list will appear here.</p>
            </div>

        <?php else : ?>

            <table class="market-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Title</th>
                        <th>Price (₹)</th>
                        <th>Source</th>
                        <th>Status</th>
                        <th>Date Added</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $uploads as $u ) :
                        $sc = in_array( $u['status'], [ 'active', 'inactive', 'pending' ], true )
                            ? $u['status'] : 'active';
                    ?>
                    <tr>
                        <td style="color:var(--mk-muted); font-size:12px;">#<?php echo esc_html( $u['id'] ); ?></td>
                        <td style="font-weight:600;"><?php echo esc_html( $u['title'] ); ?></td>
                        <td>₹<?php echo number_format( (float) $u['price'], 2 ); ?></td>
                        <td>
                            <span class="market-badge <?php echo esc_attr( $u['source_type'] ); ?>">
                                <?php echo esc_html( ucfirst( $u['source_type'] ) ); ?>
                            </span>
                        </td>
                        <td><span class="mk-status <?php echo esc_attr( $sc ); ?>"><?php echo esc_html( ucfirst( $u['status'] ) ); ?></span></td>
                        <td style="color:var(--mk-muted); font-size:12.5px;">
                            <?php echo esc_html( date( 'd M Y', strtotime( $u['created_at'] ) ) ); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

    </div>

    <!-- ── Purchases panel ────────────────────────────────── -->
    <div class="mk-hist-panel" id="mk-hist-purchases" style="display:none;">

        <?php if ( empty( $bought ) ) : ?>

            <div class="market-empty" style="padding:40px 0 32px;">
                <div class="market-empty-illustration" style="width:100px;height:100px;">
                    <svg viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <rect x="10" y="20" width="36" height="28" rx="5" fill="#bfdbfe" stroke="#3b82f6" stroke-width="2"/>
                        <path d="M20 20v-4a8 8 0 0116 0v4" stroke="#3b82f6" stroke-width="2" stroke-linecap="round"/>
                        <circle cx="22" cy="30" r="2" fill="#2563eb"/>
                        <circle cx="34" cy="30" r="2" fill="#2563eb"/>
                        <path d="M22 37c1.2 2 8.8 2 10 0" stroke="#2563eb" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                </div>
                <h4>No purchases yet</h4>
                <p>You haven't bought anything yet. Browse the marketplace to find products.</p>
                <button class="mk-btn mk-btn-primary market-tab-link" data-view="browse">
                    Browse Products
                </button>
            </div>

        <?php else : ?>

            <table class="market-table">
                <thead>
                    <tr>
                        <th>Order #</th>
                        <th>Product</th>
                        <th>Total (₹)</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $bought as $b ) :
                        $os = in_array( $b['order_status'], [ 'completed', 'processing', 'pending' ], true )
                            ? $b['order_status'] : 'pending';
                    ?>
                    <tr>
                        <td style="color:var(--mk-muted); font-size:12px;">#<?php echo esc_html( $b['id'] ); ?></td>
                        <td style="font-weight:600;"><?php echo esc_html( $b['product_title'] ?: '—' ); ?></td>
                        <td style="font-weight:600; color:var(--mk-accent);">
                            ₹<?php echo number_format( (float) $b['total'], 2 ); ?>
                        </td>
                        <td><span class="mk-status <?php echo esc_attr( $os ); ?>"><?php echo esc_html( ucfirst( $b['order_status'] ) ); ?></span></td>
                        <td style="color:var(--mk-muted); font-size:12.5px;">
                            <?php echo esc_html( date( 'd M Y', strtotime( $b['created_at'] ) ) ); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

    </div>

</div>

<script>
jQuery( document ).on( 'click', '.mk-hist-tab', function () {
    var target = jQuery( this ).data( 'hist' );

    jQuery( '.mk-hist-tab' )
        .removeClass( 'mk-btn-primary' )
        .addClass( 'mk-btn-ghost' );
    jQuery( this )
        .removeClass( 'mk-btn-ghost' )
        .addClass( 'mk-btn-primary' );

    jQuery( '.mk-hist-panel' ).hide();
    jQuery( '#mk-hist-' + target ).show();
} );
</script>
