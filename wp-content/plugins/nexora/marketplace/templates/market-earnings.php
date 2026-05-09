<?php
/**
 * marketplace/templates/market-earnings.php
 *
 * Vendor earnings dashboard:
 *   - 4 stat cards: This month gross / net / platform fee / pending payout
 *   - Period-by-period breakdown table from nx_earnings
 *   - Chart placeholder (Chart.js wired tomorrow via AJAX data endpoint)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$user_id      = get_current_user_id();
$this_month   = date( 'Y-m' );
$last_month   = date( 'Y-m', strtotime( 'first day of last month' ) );

/* ── Fetch all earnings rows via helper ───────────────────── */
$rows         = NEXORA_MARKET_HELPER::get_earnings( $user_id );
$current_row  = null;
$previous_row = null;
$total_net    = 0;
$pending_payout = 0;

foreach ( $rows as $r ) {
    if ( $r['period'] === $this_month ) $current_row  = $r;
    if ( $r['period'] === $last_month ) $previous_row = $r;
    $total_net += (float) $r['net'];
    if ( $r['payout_status'] === 'pending' ) {
        $pending_payout += (float) $r['net'];
    }
}

/* ── Stat helpers ─────────────────────────────────────────── */
$cur_gross   = $current_row  ? (float) $current_row['gross']        : 0;
$cur_net     = $current_row  ? (float) $current_row['net']          : 0;
$cur_fee     = $current_row  ? (float) $current_row['platform_fee'] : 0;
$prev_net    = $previous_row ? (float) $previous_row['net']         : 0;

$delta       = $prev_net > 0 ? round( ( ( $cur_net - $prev_net ) / $prev_net ) * 100, 1 ) : null;
$delta_sign  = ( $delta !== null && $delta >= 0 ) ? '+' : '';
$delta_class = ( $delta !== null && $delta < 0 ) ? 'neg' : '';
?>

<div class="market-panel">

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
        <h2 style="margin:0; border:none; padding:0;">Earnings</h2>
        <span style="font-size:13px; color:var(--mk-muted);">
            <?php echo esc_html( date( 'F Y' ) ); ?>
        </span>
    </div>

    <!-- Stat Cards -->
    <div class="market-stats">

        <div class="mk-stat-card">
            <div class="stat-label">This Month — Gross</div>
            <div class="stat-value">₹<?php echo number_format( $cur_gross, 2 ); ?></div>
            <?php if ( $delta !== null ) : ?>
                <div class="stat-sub <?php echo esc_attr( $delta_class ); ?>">
                    <?php echo esc_html( $delta_sign . $delta . '%' ); ?> vs last month
                </div>
            <?php endif; ?>
        </div>

        <div class="mk-stat-card">
            <div class="stat-label">This Month — Net</div>
            <div class="stat-value">₹<?php echo number_format( $cur_net, 2 ); ?></div>
            <div class="stat-sub" style="color:var(--mk-muted);">
                After platform fee
            </div>
        </div>

        <div class="mk-stat-card">
            <div class="stat-label">Platform Fee</div>
            <div class="stat-value">₹<?php echo number_format( $cur_fee, 2 ); ?></div>
            <div class="stat-sub" style="color:var(--mk-muted);">
                <?php
                $fee_pct = $cur_gross > 0
                    ? round( ( $cur_fee / $cur_gross ) * 100, 1 )
                    : 0;
                echo esc_html( $fee_pct . '% of gross' );
                ?>
            </div>
        </div>

        <div class="mk-stat-card">
            <div class="stat-label">Pending Payout</div>
            <div class="stat-value">₹<?php echo number_format( $pending_payout, 2 ); ?></div>
            <div class="stat-sub" style="color:var(--mk-muted);">
                All-time net: ₹<?php echo number_format( $total_net, 2 ); ?>
            </div>
        </div>

    </div>

    <!-- Chart area (Chart.js will mount here tomorrow) -->
    <div class="market-chart-placeholder" id="mk-earnings-chart-wrap" style="position:relative;">
        <canvas id="mk-earnings-chart" style="display:none;"></canvas>
        <span id="mk-chart-empty-label">
            📊 Earnings chart — wired up in the backend phase
        </span>
    </div>

    <!-- Period breakdown table -->
    <h3 style="font-size:14px; font-weight:700; margin:28px 0 12px; color:var(--mk-text);">
        Period Breakdown
    </h3>

    <?php if ( empty( $rows ) ) : ?>

        <div class="market-empty" style="padding:40px 0 32px;">
            <div class="market-empty-illustration" style="width:100px;height:100px;">
                <svg viewBox="0 0 56 56" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="28" cy="28" r="20" fill="#dcfce7" stroke="#16a34a" stroke-width="2"/>
                    <path d="M28 16v4M28 36v4M20 28h16" stroke="#16a34a" stroke-width="2" stroke-linecap="round"/>
                    <path d="M22 22h8a4 4 0 010 8h-4a4 4 0 000 8h8" stroke="#16a34a" stroke-width="2" stroke-linecap="round"/>
                    <path d="M44 12l1 2.5 2.5 1-2.5 1L44 19l-1-2.5L40.5 15.5l2.5-1z" fill="#86efac"/>
                </svg>
            </div>
            <h4>No earnings yet</h4>
            <p>Start selling products and your earnings will appear here each period.</p>
        </div>

    <?php else : ?>

        <table class="market-table">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Gross (₹)</th>
                    <th>Platform Fee (₹)</th>
                    <th>Net (₹)</th>
                    <th>Payout Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $r ) :
                    $ps_class = in_array( $r['payout_status'], [ 'paid', 'pending' ], true )
                        ? ( $r['payout_status'] === 'paid' ? 'completed' : 'pending' )
                        : 'pending';
                    /* Format period: "2025-04" → "April 2025" */
                    $period_label = date( 'F Y', strtotime( $r['period'] . '-01' ) );
                ?>
                <tr>
                    <td style="font-weight:600;"><?php echo esc_html( $period_label ); ?></td>
                    <td>₹<?php echo number_format( (float) $r['gross'], 2 ); ?></td>
                    <td style="color:var(--mk-danger);">
                        − ₹<?php echo number_format( (float) $r['platform_fee'], 2 ); ?>
                    </td>
                    <td style="font-weight:700; color:var(--mk-success);">
                        ₹<?php echo number_format( (float) $r['net'], 2 ); ?>
                    </td>
                    <td>
                        <span class="mk-status <?php echo esc_attr( $ps_class ); ?>">
                            <?php echo esc_html( ucfirst( $r['payout_status'] ) ); ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    <?php endif; ?>

</div>
