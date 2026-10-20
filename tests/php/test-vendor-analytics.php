<?php
// Marketplace Phase 8: seller analytics (the Sales tab) and the administrator's Marketplace overview. Read-only reports on the frozen
// order-line figures of Phase 6b: only Processing and Completed count, pending and on-hold are shown apart as "not counted yet", a partial
// refund shows the seller's reversed share, old figures never move when a price is edited, nobody sees another seller's lines or any buyer
// name, and order lines that name a seller record that no longer exists are handled (shown as "Deleted seller" to the administrator only).
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Accounts\Account_Types;
use Nexora\Orders\Earnings;
use Nexora\Orders\Lines;
use Nexora\Orders\Overview_Page;
use Nexora\Orders\Report_View;
use Nexora\Orders\Reports;
use Nexora\Vendors\Service;

if ( ! function_exists( 'WC' ) ) { echo "WooCommerce not active: skipped\n"; nx_test_finish(); return; }
nx_test_marketplace_item_scope();
nx_test_reset_limits();
global $wpdb;

// Any PHP warning, notice or deprecation raised by the report code is a failure.
$php_problems = array();
set_error_handler( function ( $no, $str, $file, $line ) use ( &$php_problems ) { if ( false !== strpos( $file, 'plugins/nexora' ) ) { $php_problems[] = "$str ($file:$line)"; } return false; }, E_WARNING | E_NOTICE | E_DEPRECATED | E_USER_WARNING | E_USER_NOTICE );

$admin = nx_test_admin();
wp_set_current_user( $admin );
$A  = nx_test_vendor( 'ana', 'approved' );
$B  = nx_test_vendor( 'anb', 'approved' );
$S  = nx_test_vendor( 'ans', 'approved' );   // suspended below
$P  = nx_test_vendor( 'anp', 'pending' );
$R  = nx_test_vendor( 'anr', 'pending' );    // rejected below
$DS = nx_test_direct_seller( 'and', 'approved' );
$X  = nx_test_vendor( 'anx', 'approved' );   // its record is deleted below
$C  = nx_test_user( 'anc' );                 // the buyer: the order fixture gives them the billing name Bob Billing and the shipping name Sia Shipping
$pa1 = nx_test_product( $A, 'AN-A1', '100.00', '900' );
$pa2 = nx_test_product( $A, 'AN-A2', '50.00', '900', array( 'name' => 'Marker <b>Alpha</b> & "Co"' ) );
$pb1 = nx_test_product( $B, 'AN-B1', '40.00', '900' );
$ps1 = nx_test_product( $S, 'AN-S1', '30.00', '900' );
$pd1 = nx_test_product( $DS, 'AN-D1', '20.00', '900' );
$px1 = nx_test_product( $X, 'AN-X1', '70.00', '900' );
$order  = function ( array $lines, $payment = 'cod' ) use ( $C ) { $r = nx_test_order( $C, $lines, array( 'payment' => $payment ) ); if ( ! $r['order'] ) { throw new RuntimeException( 'order failed: ' . $r['error'] ); } return $r['order']; };
$reload = function ( $o ) { return wc_get_order( $o->get_id() ); };
$set_date = function ( $o, $ts ) { $o = wc_get_order( $o->get_id() ); $o->set_date_created( $ts ); $o->save(); };
$rep    = function ( $v, $range = 'all', $page = 1 ) { Reports::flush(); return Reports::seller( is_array( $v ) ? $v['vendor_id'] : $v, $range, $page ); };
$mc     = function ( $x ) { return number_format( (float) $x, 2, '.', '' ); };

/* ---------- the range allow-list ---------- */
nx_assert_same( array( '7', '30', '90', 'all' ), Reports::RANGES, 'the ranges are 7, 30, 90 days and all time' );
nx_assert_same( '30', Reports::DEFAULT_RANGE, 'the default is 30 days' );
foreach ( array( '7', '30', '90', 'all' ) as $ok ) { nx_assert_same( $ok, Reports::range_from( $ok ), "range '$ok' is accepted" ); }
foreach ( array( '', '1', '8', '365', 'ALL', 'all ', '30; DROP', array( '7' ), null, '-7', '0', '7.0' ) as $bad ) { nx_assert_same( '30', Reports::range_from( $bad ), 'range ' . wp_json_encode( $bad ) . ' falls back to 30' ); }

/* ---------- the main fixture orders (commission is 10%) ---------- */
$ov0 = Reports::overview( 'all' );
$o1 = $order( array( array( $pa1, 2 ) ) );                                  // A 200.00 -> commission 20.00, earning 180.00
$o2 = $order( array( array( $pa2, 1 ), array( $pb1, 3 ) ) );                // A 50.00 (45.00) and B 120.00 (108.00): one order, two sellers
$o3 = $order( array( array( $pa1, 1 ) ), 'bacs' );                          // A on hold: 100.00, earning 90.00, not counted yet
$o4 = $order( array( array( $pa1, 5 ) ) ); $o4->update_status( 'cancelled' );
$o5 = $order( array( array( $pa2, 2 ) ) ); $o5->update_status( 'completed' ); // A 100.00 -> earning 90.00
$o6 = $order( array( array( $pa1, 3 ) ) ); $o6->update_status( 'failed' );
$o7 = $order( array( array( $pa1, 4 ) ), 'bacs' ); $o7 = $reload( $o7 ); $o7->update_status( 'pending' ); // A pending: 400.00, earning 360.00, not counted yet
$o8 = $order( array( array( $pa1, 1 ) ) ); $o8->update_status( 'refunded' );
$o9  = $order( array( array( $px1, 2 ) ) );                                  // X 140.00 -> earning 126.00
$o10 = $order( array( array( $pb1, 1 ), array( $px1, 1 ) ) );                // B 40.00 and X 70.00
$o11 = $order( array( array( $ps1, 1 ) ) );                                  // S 30.00
$o12 = $order( array( array( $pd1, 2 ) ) );                                  // DS 40.00
$ov1 = Reports::overview( 'all' );
Reports::flush();
wp_set_current_user( $admin );
Service::change_status( $S['vendor_id'], 'suspend' );
Service::change_status( $R['vendor_id'], 'reject' );

/* ---------- totals match the fixtures ---------- */
$r = $rep( $A );
nx_assert_same( array( 3, 5, '350.00', '35.00', '315.00', '0.00' ), array( $r['totals']['orders'], $r['totals']['units'], $r['totals']['sales'], $r['totals']['commission'], $r['totals']['earnings'], $r['totals']['reversed'] ), 'seller A: 3 counted orders, 5 units, sales 350.00, commission 35.00, earnings 315.00, nothing reversed' );
nx_assert_same( array( 2, '450.00' ), array( $r['awaiting']['orders'], $r['awaiting']['earnings'] ), 'seller A: the pending and the on-hold order are shown apart as "not counted yet" (earning 90.00 + 360.00)' );
$sum = Earnings::summary( $A['vendor_id'] );
nx_assert_same( array( $sum['gross'], $sum['commission'], $sum['earning'], $sum['orders'], $sum['awaiting'] ), array( $r['totals']['sales'], $r['totals']['commission'], $r['totals']['earnings'], $r['totals']['orders'], $r['awaiting']['earnings'] ), 'and they equal the existing Earnings::summary (one source of truth, no second money maths)' );
$rb = $rep( $B );
nx_assert_same( array( 2, 4, '160.00', '16.00', '144.00' ), array( $rb['totals']['orders'], $rb['totals']['units'], $rb['totals']['sales'], $rb['totals']['commission'], $rb['totals']['earnings'] ), 'seller B (one order shared with A, one with X): 2 orders, 4 units, 160.00 / 16.00 / 144.00' );
$rs = $rep( $S );
nx_assert_same( '27.00', $rs['totals']['earnings'], 'a suspended seller keeps their history (30.00 - 10%)' );
$rd = $rep( $DS['vendor_id'] );
nx_assert_same( '36.00', $rd['totals']['earnings'], 'a direct seller (nexora_seller) gets the same report' );

/* ---------- which statuses count ---------- */
$none = $rep( $P );
nx_assert_same( array( 0, 0, '0.00', '0.00' ), array( $none['totals']['orders'], $none['awaiting']['orders'], $none['totals']['earnings'], $none['awaiting']['earnings'] ), 'a seller with no orders: zeros, no error' );
$statuses = array();
foreach ( $r['orders'] as $e ) { $statuses[ $e['id'] ] = array( $e['status'], $e['counted'], $e['awaiting'] ); }
nx_assert_same( array( 'processing', true, false ), $statuses[ $o1->get_id() ] ?? null, 'processing: counted' );
nx_assert_same( array( 'completed', true, false ), $statuses[ $o5->get_id() ] ?? null, 'completed: counted' );
nx_assert_same( array( 'on-hold', false, true ), $statuses[ $o3->get_id() ] ?? null, 'on-hold: not counted yet' );
nx_assert_same( array( 'pending', false, true ), $statuses[ $o7->get_id() ] ?? null, 'pending: not counted yet' );
foreach ( array( array( $o4, 'cancelled' ), array( $o6, 'failed' ), array( $o8, 'refunded' ) ) as $pair ) {
	nx_assert_same( array( $pair[1], false, false ), $statuses[ $pair[0]->get_id() ] ?? null, "{$pair[1]}: neither counted nor awaiting (listed, in no total)" );
}
nx_assert_same( '315.00', $r['totals']['earnings'], '... so the cancelled, failed and refunded orders added nothing to the earnings' );

/* ---------- a partial refund shows the seller's reversed share ---------- */
$F  = nx_test_vendor( 'anf', 'approved' );
$pf = nx_test_product( $F, 'AN-F1', '100.00', '900' );
$of = $order( array( array( $pf, 4 ) ) );
$item = current( $of->get_items() );
$ref  = wc_create_refund( array( 'order_id' => $of->get_id(), 'amount' => '100.00', 'line_items' => array( $item->get_id() => array( 'qty' => 1, 'refund_total' => '100.00' ) ), 'refund_payment' => false, 'restock_items' => false ) );
nx_assert( ! is_wp_error( $ref ), 'setup: one unit (100.00) is refunded' );
$rf = $rep( $F );
nx_assert_same( array( 1, 3, '300.00', '30.00', '270.00', '90.00' ), array( $rf['totals']['orders'], $rf['totals']['units'], $rf['totals']['sales'], $rf['totals']['commission'], $rf['totals']['earnings'], $rf['totals']['reversed'] ), 'after the refund: 3 units, sales 300.00, commission 30.00, earnings 270.00, and the reversed share 90.00 is shown' );
$fsum = Earnings::summary( $F['vendor_id'] );
nx_assert_same( array( $fsum['gross'], $fsum['earning'] ), array( $rf['totals']['sales'], $rf['totals']['earnings'] ), '... matching what the seller is owed (Earnings::summary)' );

/* ---------- a price edited afterwards changes nothing old ---------- */
$G  = nx_test_vendor( 'ang', 'approved' );
$pg = nx_test_product( $G, 'AN-G1', '60.00', '900' );
$og = $order( array( array( $pg, 2 ) ) );
$before = $rep( $G );
$lines_before = Lines::of( $reload( $og ) );
\Nexora\Products\Repository::update( $pg, array( 'price' => '999.00', 'name' => 'Renamed Later' ) );
$after = $rep( $G );
nx_assert_same( $before, $after, 'editing the product price and name afterwards leaves every old figure and name in the report as it was' );
nx_assert_same( $lines_before, Lines::of( $reload( $og ) ), '... and the stored order line' );
nx_assert_same( '108.00', $after['totals']['earnings'], '(2 x 60.00 - 10%)' );

/* ---------- best sellers ---------- */
nx_assert_same( array( $pa1, $pa2 ), array_column( $r['products'], 'product_id' ), 'best sellers: by earnings, A\'s two products' );
nx_assert_same( array( 2, '180.00' ), array( $r['products'][0]['units'], $r['products'][0]['earnings'] ), 'AN-A1: 2 units, 180.00' );
nx_assert_same( array( 3, '135.00' ), array( $r['products'][1]['units'], $r['products'][1]['earnings'] ), 'AN-A2: 3 units, 135.00' );

/* ---------- date ranges ---------- */
$E  = nx_test_vendor( 'ane', 'approved' );
$pe = nx_test_product( $E, 'AN-E1', '10.00', '900' );
$ob = array();
foreach ( array( 'd1' => Reports::since( '7' ), 'd2' => Reports::since( '7' ) - 1, 'd3' => Reports::since( '30' ) - 1, 'd4' => Reports::since( '90' ) - 1 ) as $k => $ts ) { $ob[ $k ] = $order( array( array( $pe, 1 ) ) ); $set_date( $ob[ $k ], $ts ); }
nx_assert_same( null, Reports::since( 'all' ), 'all time has no start' );
nx_assert( Reports::since( '30' ) < Reports::since( '7' ) && Reports::since( '90' ) < Reports::since( '30' ), 'the ranges grow: 7 days is the shortest' );
foreach ( array( '7' => '9.00', '30' => '18.00', '90' => '27.00', 'all' => '36.00' ) as $range => $want ) {
	nx_assert_same( $want, $rep( $E, $range )['totals']['earnings'], "range $range: earnings $want (an order exactly at the start is in, one second earlier is out)" );
}
nx_assert_same( 1, $rep( $E, '7' )['totals']['orders'], 'range 7: one order' );
$series = $rep( $E, 'all' )['series'];
nx_assert( count( $series ) >= 4 && $series === array_values( $series ), 'the chart series has a point for each day with sales' );
nx_assert_same( '36.00', $mc( array_sum( array_column( $series, 'earnings' ) ) ), '... and the points add up to the total' );
$ds = array_column( $series, 'date' ); $sorted = $ds; sort( $sorted );
nx_assert_same( $sorted, $ds, 'the series runs oldest to newest' );
nx_assert_same( $rep( $E, '30' ), Reports::seller( $E['vendor_id'], Reports::range_from( 'junk' ), 1 ), 'an invalid range gives the 30-day report' );

/* ---------- the orders table: 10 a page, newest first ---------- */
$H  = nx_test_vendor( 'anh', 'approved' );
$ph = nx_test_product( $H, 'AN-H1', '5.00', '900' );
$hs = array(); for ( $i = 0; $i < 12; $i++ ) { $hs[] = $order( array( array( $ph, 1 ) ) ); $set_date( $hs[ $i ], time() - ( 12 - $i ) * 60 ); }
$p1 = $rep( $H, 'all', 1 ); $p2 = $rep( $H, 'all', 2 ); $p9 = $rep( $H, 'all', 99 );
nx_assert_same( array( 10, 2, 12, 2 ), array( count( $p1['orders'] ), count( $p2['orders'] ), $p1['total'], $p1['pages'] ), '12 orders: pages of 10 and 2' );
nx_assert_same( $hs[11]->get_id(), $p1['orders'][0]['id'], 'newest first' );
nx_assert_same( $p2['orders'], $p9['orders'], 'a page past the end shows the last page' );
nx_assert_same( '54.00', $p1['totals']['earnings'], 'the totals cover ALL 12 orders, not just the page (12 x 4.50)' );

/* ---------- access: who gets a report ---------- */
$H1 = function ( $user_id, $req = array() ) { Reports::flush(); wp_set_current_user( $user_id ); return Report_View::seller_html( $user_id, $req ); };
$html_a = $H1( $A['user_id'] );
nx_assert( '' !== $html_a && false !== strpos( $html_a, 'AN-A1' ), 'an approved seller gets the report' );
nx_assert( '' !== $H1( $S['user_id'] ) && false !== strpos( $H1( $S['user_id'] ), 'AN-S1' ), 'a suspended seller keeps read-only access to their history' );
nx_assert( '' !== $H1( $DS['user_id'] ), 'a direct seller gets it too' );
foreach ( array( 'a pending seller' => $P['user_id'], 'a rejected seller' => $R['user_id'], 'a customer' => $C['user_id'], 'a guest' => 0 ) as $who => $uid ) {
	nx_assert_same( '', $H1( $uid ), "$who gets nothing to report" );
}
wp_set_current_user( $admin );
nx_assert_same( array(), Reports::seller( 0, 'all', 1 )['orders'], 'no seller record: an empty report, no error' );

/* ---------- another seller's lines are never shown ---------- */
$html_b = $H1( $B['user_id'] );
nx_assert( false === strpos( $html_a, 'AN-B1' ) && false === strpos( $html_a, 'AN-X1' ) && false === strpos( $html_a, 'AN-S1' ), 'A\'s page shows none of the other sellers\' products, though A and B share an order' );
nx_assert( false === strpos( $html_b, 'AN-A1' ) && false === strpos( $html_b, 'AN-A2' ) && false === strpos( $html_b, 'AN-X1' ), 'B\'s page shows none of A\'s or X\'s' );
foreach ( $rep( $B )['orders'] as $e ) { foreach ( $e['lines'] as $l ) { nx_assert_same( $B['vendor_id'], $l['vendor_id'], 'every line in B\'s table is B\'s own' ); } }

/* ---------- no buyer names, anywhere ---------- */
$buyer = get_userdata( $C['user_id'] );
$secrets = array( 'Bob', 'Billing', 'bob.billing', 'Sia', 'Shipping Sia', 'Ship Street', 'Billing Road', '9800000001', $C['login'], $buyer->display_name, $buyer->user_email, 'unique buyers' );
Reports::flush();
$all_out = array(
	'A page' => $html_a, 'B page' => $html_b, 'S page' => $H1( $S['user_id'] ), 'DS page' => $H1( $DS['user_id'] ), 'A page 2' => $H1( $A['user_id'], array( 'nx_orders_page' => '2', 'nx_range' => '7' ) ),
	'A data' => wp_json_encode( $rep( $A ) ), 'overview data' => wp_json_encode( Reports::overview( 'all' ) ),
);
wp_set_current_user( $admin );
$all_out['overview page'] = Overview_Page::html( array( 'nx_range' => 'all' ) );
foreach ( $all_out as $name => $out ) {
	$leak = array_filter( $secrets, function ( $s ) use ( $out ) { return false !== stripos( $out, $s ); } );
	nx_assert_same( array(), array_values( $leak ), "$name: no buyer name, address, phone or e-mail appears" );
}

/* ---------- output is escaped ---------- */
nx_assert( false === strpos( $html_a, '<b>Alpha' ) && false !== strpos( $html_a, 'Marker Alpha &amp; &quot;Co&quot;' ), 'a product name is escaped in the page' );
$item1 = current( $reload( $o1 )->get_items() ); $item1->update_meta_data( '_nx_name', '<img src=x onerror=alert(1)><script>alert(2)</script>Evil' ); $item1->save();
$html_e = $H1( $A['user_id'] );
nx_assert( false === strpos( $html_e, '<img src=x' ) && false === strpos( $html_e, '<script>alert' ) && false !== strpos( $html_e, '&lt;img src=x' ), 'a stored name that contains markup is escaped on output' );
$item1->update_meta_data( '_nx_name', 'AN-restored' ); $item1->save();

/* ---------- the page: filter, chart with a table fallback, no outside code ---------- */
$html_a = $H1( $A['user_id'] );
nx_assert( false !== strpos( $html_a, 'nx_range=7' ) && false !== strpos( $html_a, 'nx_range=90' ) && false !== strpos( $html_a, 'nx_range=all' ), 'the range filter offers 7, 30, 90 days and all time' );
nx_assert( false !== strpos( $H1( $A['user_id'], array( 'nx_range' => '7' ) ), 'aria-current' ) , 'the chosen range is marked' );
nx_assert( false !== strpos( $html_a, '<svg' ) && false !== strpos( $html_a, 'role="img"' ), 'the chart is an inline SVG' );
nx_assert( false !== strpos( $html_a, '<table' ) && false !== stripos( $html_a, 'not counted yet' ), 'with a text table fallback, and the "not counted yet" total' );
nx_assert( 0 === preg_match( '#<script|src="https?://|href="https?://(?!' . preg_quote( wp_parse_url( home_url(), PHP_URL_HOST ), '#' ) . ')#i', $html_a ), 'no script, no external library or CDN' );
$page2 = $H1( $H['user_id'], array( 'nx_range' => 'all', 'nx_orders_page' => '2' ) );
nx_assert( 2 === substr_count( $page2, 'class="nx-report-order"' ) && false !== strpos( $H1( $H['user_id'], array( 'nx_range' => 'all' ) ), 'nx_orders_page=2' ), 'the page shows the second page of orders (2 rows) and links to it from the first' );
nx_assert_same( 10, substr_count( $H1( $H['user_id'], array( 'nx_range' => 'all', 'nx_orders_page' => 'abc' ) ), 'class="nx-report-order"' ), 'a page number that is not a number shows page 1' );

/* ---------- the administrator's overview ---------- */
$ov = Reports::overview( 'all', 'earnings', 'desc' );
$d  = function ( $k ) use ( $ov1, $ov0, $mc ) { return $mc( $ov1['totals'][ $k ] - $ov0['totals'][ $k ] ); };
nx_assert_same( array( '790.00', '79.00', '711.00' ), array( $d( 'sales' ), $d( 'commission' ), $d( 'earnings' ) ), 'the fixtures add exactly 790.00 sales, 79.00 commission, 711.00 earnings (A 350, B 160, S 30, DS 40, X 210)' );
$sums = array( 'sales' => 0, 'commission' => 0, 'earnings' => 0, 'units' => 0, 'orders' => 0 );
foreach ( $ov['rows'] as $row ) { foreach ( $sums as $k => $_ ) { $sums[ $k ] += (float) $row[ $k ]; } }
foreach ( array( 'sales', 'commission', 'earnings' ) as $k ) { nx_assert_same( $mc( $sums[ $k ] ), $ov['totals'][ $k ], "the total $k equals the sum of the per-seller rows" ); }
nx_assert_same( (int) $sums['units'], $ov['totals']['units'], 'the total units equals the sum of the rows' );
nx_assert( $ov['totals']['orders'] <= $sums['orders'], 'the total of orders counts an order once, even when it has several sellers (so it can be below the sum of the rows)' );
$names = array_column( $ov['rows'], 'name' );
nx_assert( in_array( $A['vendor_id'], array_column( $ov['rows'], 'seller_id' ), true ) && in_array( 'Test Shop ana', $names, true ), 'each seller has a row with the business name' );
$earn = array_map( 'floatval', array_column( $ov['rows'], 'earnings' ) ); $sorted = $earn; rsort( $sorted );
nx_assert_same( $sorted, $earn, 'sorted by earnings, highest first' );
foreach ( array( 'name', 'orders', 'units', 'sales', 'commission', 'earnings' ) as $col ) {
	foreach ( array( 'asc', 'desc' ) as $dir ) {
		$o = Reports::overview( 'all', $col, $dir );
		$vals = array_column( $o['rows'], $col ); $want = $vals;
		if ( 'name' === $col ) { usort( $want, 'strcasecmp' ); } else { sort( $want, SORT_NUMERIC ); }
		if ( 'desc' === $dir ) { $want = array_reverse( $want ); }
		nx_assert_same( array_map( 'strval', $want ), array_map( 'strval', $vals ), "sortable by $col, $dir" );
	}
}
nx_assert_same( Reports::overview( 'all', 'earnings', 'desc' ), Reports::overview( 'all', 'droptable', 'sideways' ), 'an unknown column or direction falls back to earnings, descending' );
$e7 = array_values( array_filter( Reports::overview( '7' )['rows'], function ( $r ) use ( $E ) { return $r['seller_id'] === $E['vendor_id']; } ) );
nx_assert_same( '9.00', $e7[0]['earnings'] ?? null, 'overview, last 7 days: seller E has 9.00' );

/* ---------- order lines that name a seller record that no longer exists ---------- */
wp_set_current_user( $admin );
$gone = $X['vendor_id'];
$wpdb->delete( $wpdb->postmeta, array( 'post_id' => $gone ) );
$wpdb->delete( $wpdb->posts, array( 'ID' => $gone ) );
clean_post_cache( $gone );
Reports::flush();
$php_problems = array();
$ovx = Reports::overview( 'all', 'earnings', 'desc' );
$row = array_values( array_filter( $ovx['rows'], function ( $r ) use ( $gone ) { return $r['seller_id'] === $gone; } ) );
nx_assert( 1 === count( $row ) && true === $row[0]['deleted'] && 'Deleted seller' === $row[0]['name'], 'the overview shows the missing seller record as "Deleted seller"' );
nx_assert_same( array( '210.00', '189.00', 2 ), array( $row[0]['sales'], $row[0]['earnings'], $row[0]['orders'] ), '... with their counted figures (two orders, 210.00 sales, 189.00 earnings)' );
$sumx = 0; foreach ( $ovx['rows'] as $rw ) { $sumx += (float) $rw['earnings']; }
nx_assert_same( $mc( $sumx ), $ovx['totals']['earnings'], '... and equals the sum of the rows, the "Deleted seller" row included' );
$html_o = Overview_Page::html( array( 'nx_range' => 'all' ) );
nx_assert( false !== strpos( $html_o, 'Deleted seller' ), 'the page says "Deleted seller"' );
foreach ( array( $A, $B, $S, $DS ) as $v ) {
	$out = $H1( $v['user_id'] ) . wp_json_encode( $rep( $v ) );
	nx_assert( false === strpos( $out, 'AN-X1' ) && false === strpos( $out, (string) $gone ), 'seller ' . $v['vendor_id'] . ': nothing of the deleted seller\'s lines is shown' );
}
nx_assert_same( array(), $php_problems, 'no PHP warning, notice or deprecation from any of it' );
wp_set_current_user( $admin );
$orphan_lines = Earnings::vendor_lines( $reload( $o9 ), $A['vendor_id'] );
nx_assert_same( array(), $orphan_lines, 'the order of the deleted seller has no line for any other seller' );

/* ---------- the overview page is for administrators only ---------- */
nx_assert( '' !== Overview_Page::html( array() ), 'an administrator gets the page' );
foreach ( array( 'a seller' => $A['user_id'], 'a customer' => $C['user_id'], 'a guest' => 0 ) as $who => $uid ) {
	wp_set_current_user( $uid );
	nx_assert_same( '', Overview_Page::html( array() ), "$who gets nothing" );
}
wp_set_current_user( $admin );
global $menu, $submenu, $_wp_submenu_nopriv, $_wp_menu_nopriv, $admin_page_hooks, $_registered_pages, $_parent_pages;
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$build = function ( $uid ) { global $submenu, $menu, $_wp_submenu_nopriv, $_wp_menu_nopriv, $admin_page_hooks, $_registered_pages, $_parent_pages; wp_set_current_user( $uid ); $menu = $submenu = $_wp_submenu_nopriv = $_wp_menu_nopriv = $admin_page_hooks = $_registered_pages = $_parent_pages = array(); set_current_screen( 'dashboard' ); do_action( 'admin_menu' ); return array_map( function ( $r ) { return $r[2]; }, (array) ( $submenu['nexora-system'] ?? array() ) ); };
nx_assert( in_array( Overview_Page::SLUG, $build( $admin ), true ), 'the "Marketplace overview" page is under Nexora System for an administrator' );
nx_assert( ! in_array( Overview_Page::SLUG, $build( $A['user_id'] ), true ), '... and not there for a seller' );
unset( $GLOBALS['current_screen'] );
wp_set_current_user( $admin );

/* ---------- the cache is cleared when an order changes ---------- */
$J  = nx_test_vendor( 'anj', 'approved' );
$pj = nx_test_product( $J, 'AN-J1', '100.00', '900' );
$oj = $order( array( array( $pj, 1 ) ), 'bacs' );
Reports::flush();
$c1 = Reports::seller( $J['vendor_id'], 'all', 1 );
nx_assert_same( array( '0.00', '90.00' ), array( $c1['totals']['earnings'], $c1['awaiting']['earnings'] ), 'setup: the on-hold order is "not counted yet"' );
$ovj = Reports::overview( 'all' );
$oj = $reload( $oj ); $oj->update_status( 'processing' );
$c2 = Reports::seller( $J['vendor_id'], 'all', 1 );
nx_assert_same( array( '90.00', '0.00' ), array( $c2['totals']['earnings'], $c2['awaiting']['earnings'] ), 'marking it paid shows at once (the cached report was cleared by the status change, nothing flushed by hand)' );
nx_assert_same( '90.00', $mc( Reports::overview( 'all' )['totals']['earnings'] - $ovj['totals']['earnings'] ), '... and so does the overview' );
$item = current( $reload( $oj )->get_items() );
wc_create_refund( array( 'order_id' => $oj->get_id(), 'amount' => '100.00', 'line_items' => array( $item->get_id() => array( 'qty' => 1, 'refund_total' => '100.00' ) ), 'refund_payment' => false, 'restock_items' => false ) );
nx_assert_same( '0.00', Reports::seller( $J['vendor_id'], 'all', 1 )['totals']['earnings'], 'a refund clears the cache too' );

/* ---------- nothing the reports read was changed by reading it ---------- */
Reports::flush();
$lines_a = Lines::of( $reload( $o2 ) );
Reports::seller( $A['vendor_id'], 'all', 1 ); Reports::overview( 'all' );
nx_assert_same( $lines_a, Lines::of( $reload( $o2 ) ), 'the order lines are exactly as they were after all the reports' );
nx_assert_same( $sum, Earnings::summary( $A['vendor_id'] ), 'and so are the seller\'s earnings' );
nx_assert_same( array( '100.00', '50.00', '40.00' ), array( get_post_meta( $pa1, 'price', true ), get_post_meta( $pa2, 'price', true ), get_post_meta( $pb1, 'price', true ) ), 'and the products');
nx_assert_same( array(), $php_problems, 'no PHP warning or notice anywhere in this test' );
restore_error_handler();

nx_test_finish();
