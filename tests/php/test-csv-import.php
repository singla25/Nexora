<?php
// Marketplace Phase 5: the CSV import. Parser (file checks, line numbers, formula neutralising), dry-run plan, apply,
// idempotence, per-vendor upsert key (vendor + sku), history log. The upload endpoint itself is in test-import-http.php.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Imports\Csv_Parser;
use Nexora\Imports\Importer;
use Nexora\Imports\Log_Repository;
use Nexora\Products\Repository;

global $wpdb;
nx_test_capture_mail();
nx_test_reset_limits();

$A = nx_test_vendor( 'csa', 'approved' );
$B = nx_test_vendor( 'csb', 'approved' );
$P = nx_test_vendor( 'csp', 'pending' );
$S = nx_test_vendor( 'css', 'suspended' );
$C = nx_test_user( 'csc' );

$files = array();
$csv = function ( $content, $name = 'products.csv' ) use ( &$files ) {
	$path = tempnam( sys_get_temp_dir(), 'nxcsv' );
	file_put_contents( $path, $content );
	$files[] = $path;
	return array( $path, $name );
};
$parse = function ( $content, $name = 'products.csv' ) use ( $csv ) { list( $p, $n ) = $csv( $content, $name ); return Csv_Parser::parse( $p, $n ); };
$HEAD  = "sku,name,description,price,stock,category,image_url\n";
$count = function () use ( $wpdb ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nexora_product'" ); };
$logs  = function () use ( $wpdb ) { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'nexora_import_log'" ); };
$cells = function ( $r ) { return array_column( $r['rows'], 'cells' ); };
$track = function ( $vendor ) { foreach ( Repository::ids_for_vendor( $vendor['vendor_id'] ) as $id ) { $GLOBALS['nx_test']['posts'][] = $id; } };

nx_assert_same( 2 * 1024 * 1024, Csv_Parser::MAX_BYTES, 'the size limit is 2 MB' );
nx_assert_same( 500, Csv_Parser::MAX_ROWS, 'the row limit is 500' );

/* ---------- the parser ---------- */
$r = $parse( $HEAD . "K-1,Kurta,Soft cotton,499,10,fashion,\nK-2,Mug,,120.50,3,home-kitchen,\n" );
nx_assert( is_array( $r ) && 2 === count( $r['rows'] ), 'a valid file gives its rows' );
nx_assert_same( array( 2, 3 ), array_column( $r['rows'], 'line' ), 'rows carry their line number in the file (the header is line 1)' );
nx_assert_same( 'K-1', $cells( $r )[0]['sku'] ?? '', 'cells are keyed by column name' );
$r = $parse( "\xEF\xBB\xBF" . $HEAD . "K-1,Kurta,x,499,10,fashion,\r\nK-2,Mug,,120,3,toys,\r\n" );
nx_assert( is_array( $r ) && 2 === count( $r['rows'] ) && 'K-1' === $cells( $r )[0]['sku'], 'a UTF-8 BOM and Windows line endings are fine' );
$r = $parse( $HEAD . "K-1,Kurta,\"line one\nline two\",499,10,fashion,\n\n\nK-9,Mug,,120,3,toys,\n" );
nx_assert_same( array( 2, 6 ), array_column( $r['rows'], 'line' ), 'line numbers survive a multi-line cell and blank lines' );
$r = $parse( " SKU , Name ,description,PRICE,stock,Category\nK-1,Kurta,,499,10,fashion\n" );
nx_assert( is_array( $r ) && 'K-1' === $cells( $r )[0]['sku'] && ! isset( $cells( $r )[0]['image_url'] ), 'the header is case- and space-tolerant and image_url / description are optional columns' );

foreach ( array(
	'a missing required column'   => "sku,name,price,stock\nK-1,Kurta,499,10\n",
	'an unknown column'           => "sku,name,price,stock,category,cost\nK-1,Kurta,499,10,fashion,5\n",
	'a repeated column'           => "sku,name,price,stock,category,price\nK-1,Kurta,499,10,fashion,5\n",
	'an empty file'               => '',
	'only a header'               => "sku,name,price,stock,category\n",
	'binary content'              => "sku,name,price,stock,category\nK-1,Kurta\x00\x01,499,10,fashion\n",
	'a latin-1 (non UTF-8) file'  => "sku,name,price,stock,category\nK-1,Caf\xE9,499,10,fashion\n",
) as $label => $content ) {
	$r = $parse( $content );
	nx_assert( is_wp_error( $r ) && 'nexora_csv' === $r->get_error_code() && '' !== $r->get_error_message(), "refused: $label" );
}
foreach ( array( 'products.txt', 'products.xlsx', 'products.csv.php', 'products', 'products.csv.exe', '.csv.', 'products.html' ) as $name ) {
	nx_assert( is_wp_error( $parse( $HEAD . "K-1,Kurta,,499,10,fashion,\n", $name ) ), "refused by extension: $name" );
}
nx_assert( is_array( $parse( $HEAD . "K-1,Kurta,,499,10,fashion,\n", 'PRODUCTS.CSV' ) ), 'the .csv extension is case-insensitive' );
$big = $HEAD . str_repeat( "K-1,Kurta,,499,10,fashion,\n", 10 ) . str_repeat( 'a', Csv_Parser::MAX_BYTES );
nx_assert( is_wp_error( $parse( $big ) ), 'refused: a file over 2 MB' );
$rows500 = $HEAD; for ( $i = 1; $i <= 500; $i++ ) { $rows500 .= "R-$i,Item $i,,10,1,other,\n"; }
nx_assert( is_array( $parse( $rows500 ) ) && 500 === count( $parse( $rows500 )['rows'] ), '500 rows are accepted' );
$r = $parse( $rows500 . "R-501,Item 501,,10,1,other,\n" );
nx_assert( is_wp_error( $r ) && false !== strpos( $r->get_error_message(), '500' ), 'refused: 501 rows (and the message says the limit)' );
nx_assert( is_wp_error( Csv_Parser::parse( '/nonexistent/file.csv', 'x.csv' ) ), 'refused: a path that cannot be read' );
$r = $parse( $HEAD . "K-1,Kurta,,499,10,fashion,\nK-2,Mug,too,many,cells,here,x,extra,cells\n" );
nx_assert( is_array( $r ) && 1 === count( $r['rows'] ) && 1 === count( $r['errors'] ) && 3 === $r['errors'][0]['line'], 'a row with too many cells is reported with its line and skipped, the rest still parse' );

/* ---------- formula injection ---------- */
$r = $parse( $HEAD . "K-1,=HYPERLINK(\"http://evil.example\"),+1+1,499,10,fashion,\nK-2,@SUM(A1),-2+3,120,3,toys,\nK-3,\t=cmd,\r=x,120,3,toys,\nK-4,Fine = ok,a+b,120,3,toys,\n" );
$c = $cells( $r );
nx_assert( "'=HYPERLINK(\"http://evil.example\")" === $c[0]['name'] && "'+1+1" === $c[0]['description'], 'cells starting with = or + get a leading apostrophe' );
nx_assert( "'@SUM(A1)" === $c[1]['name'] && "'-2+3" === $c[1]['description'], 'cells starting with @ or - get a leading apostrophe' );
nx_assert( 0 === strpos( $c[2]['name'], "'" ) && 0 === strpos( $c[2]['description'], "'" ), 'cells starting with a tab or carriage return are neutralised too' );
nx_assert( 'Fine = ok' === $c[3]['name'] && 'a+b' === $c[3]['description'], 'a = or + inside a cell is left alone' );

/* ---------- plan (dry run) ---------- */
$content = $HEAD
	. "OK-1,Kurta,Soft cotton,499,10,fashion,\n"          // line 2
	. "OK-2,Mug,,120.50,3,Home & Kitchen,\n"               // line 3: category by label
	. "BAD-1,Free thing,,0,3,fashion,\n"                   // line 4: price
	. "BAD-2,Odd,,10,many,fashion,\n"                      // line 5: stock
	. "BAD-3,Cars,,10,3,cars,\n"                           // line 6: category
	. "OK-1,Again,,10,3,fashion,\n"                        // line 7: duplicate SKU in the file
	. ",No sku,,10,3,fashion,\n"                           // line 8: sku
	. "OK-3,=cmd|' /C calc'!A0,,10,3,fashion,\n";          // line 9: formula neutralised, still imports
$parsed = $parse( $content );
$before = $count(); $before_logs = $logs();
$snapshot = $wpdb->get_var( "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts}" );
$plan = Importer::plan_csv( $A['user_id'], $parsed['rows'] );
nx_assert( is_array( $plan ) && 'csv' === $plan['source'], 'a plan is made for an approved vendor' );
nx_assert_same( array( 'create' => 3, 'update' => 0, 'unchanged' => 0, 'error' => 5, 'total' => 8 ), $plan['summary'], 'the plan counts creates and errors (3 and 5)' );
nx_assert_same( $before, $count(), 'a dry run creates nothing' );
nx_assert_same( $before_logs, $logs(), 'a dry run writes no history entry' );
nx_assert_same( $snapshot, $wpdb->get_var( "SELECT MAX(post_modified_gmt) FROM {$wpdb->posts}" ), 'a dry run changes no post at all' );
$by_line = array(); foreach ( $plan['rows'] as $row ) { $by_line[ $row['line'] ] = $row; }
nx_assert_same( array( 2, 3, 4, 5, 6, 7, 8, 9 ), array_keys( $by_line ), 'every row is in the report with its line number' );
foreach ( array( 4 => 'price', 5 => 'stock', 6 => 'category', 8 => 'sku' ) as $line => $field ) {
	nx_assert( 'error' === $by_line[ $line ]['action'] && isset( $by_line[ $line ]['errors'][ $field ] ), "line $line is reported as an error on $field" );
}
nx_assert( 'error' === $by_line[7]['action'] && isset( $by_line[7]['errors']['sku'] ) && false !== strpos( $by_line[7]['errors']['sku'], '2' ), 'a repeated SKU in the file is an error that points at the first line (2)' );
nx_assert( 'create' === $by_line[3]['action'], 'a category can be given by its label' );
nx_assert( 'create' === $by_line[9]['action'], 'a neutralised formula cell still imports' );

/* ---------- apply ---------- */
$res = Importer::apply( $A['user_id'], $plan );
$track( $A );
nx_assert( is_array( $res ) && isset( $res['summary'] ), 'applying a plan returns a summary' );
nx_assert_same( 3, $res['summary']['created'], 'three products were created' );
nx_assert_same( 5, $res['summary']['skipped'], 'five rows were skipped' );
nx_assert_same( $before + 3, $count(), 'three products exist' );
$id = Repository::find_by_sku( $A['vendor_id'], 'OK-1' );
$d  = Repository::data( $id );
nx_assert( $id > 0 && 'Kurta' === $d['name'] && '499.00' === $d['price'] && 10 === $d['stock'] && 'fashion' === $d['category'] && 'csv' === $d['source'] && $A['vendor_id'] === $d['vendor_id'], 'the product has the CSV values, source csv and the vendor' );
nx_assert_same( $A['user_id'], (int) get_post_field( 'post_author', $id ), 'and belongs to the importing vendor' );
nx_assert_same( 'publish', get_post_status( $id ), 'and is published' );
$f = Repository::find_by_sku( $A['vendor_id'], 'OK-3' );
nx_assert( $f > 0 && 0 === strpos( Repository::data( $f )['name'], "'=cmd" ), 'the neutralised formula is stored with its apostrophe' );
nx_assert_same( 0, Repository::find_by_sku( $A['vendor_id'], 'BAD-1' ), 'skipped rows created nothing' );
nx_assert_same( $before_logs + 1, $logs(), 'one history entry was written by the real import' );
$hist = Log_Repository::for_vendor( $A['vendor_id'], 10 );
nx_assert( 1 === count( $hist ) && 'csv' === $hist[0]['source'] && 3 === $hist[0]['created'] && 5 === $hist[0]['skipped'] , 'the history shows the source, created and skipped counts' );
nx_assert( ! empty( $hist[0]['errors'] ) && isset( $hist[0]['errors'][0]['line'] ), 'and the row errors with line numbers' );
nx_assert_same( array(), Log_Repository::for_vendor( $B['vendor_id'], 10 ), "another vendor's history is empty (history is per vendor)" );

/* ---------- re-import is idempotent ---------- */
$modified = $wpdb->get_var( $wpdb->prepare( "SELECT post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $id ) );
sleep( 1 );
$again = Importer::plan_csv( $A['user_id'], $parsed['rows'] );
nx_assert_same( array( 'create' => 0, 'update' => 0, 'unchanged' => 3, 'error' => 5, 'total' => 8 ), $again['summary'], 're-importing the same file plans no creates and no updates' );
$res2 = Importer::apply( $A['user_id'], $again );
nx_assert( 0 === $res2['summary']['created'] && 0 === $res2['summary']['updated'] && 3 === $res2['summary']['unchanged'], 'applying it again changes nothing' );
nx_assert_same( $before + 3, $count(), 'and creates no duplicate' );
nx_assert_same( $modified, $wpdb->get_var( $wpdb->prepare( "SELECT post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $id ) ), 'an unchanged product is not even touched' );

$changed = $parse( $HEAD . "OK-1,Kurta Deluxe,Soft cotton,599,10,fashion,\nNEW-1,Brand new,,50,2,toys,\n" );
$res3 = Importer::apply( $A['user_id'], Importer::plan_csv( $A['user_id'], $changed['rows'] ) );
nx_assert( 1 === $res3['summary']['created'] && 1 === $res3['summary']['updated'], 'a changed row updates, a new SKU creates' );
nx_assert( 'Kurta Deluxe' === Repository::data( $id )['name'] && '599.00' === Repository::data( $id )['price'], 'the update is stored on the same product' );
nx_assert_same( $id, Repository::find_by_sku( $A['vendor_id'], 'ok-1' ), 'the product keeps its identity (same post)' );

/* ---------- vendor + sku: another vendor's SKU is theirs alone ---------- */
$a_before = Repository::data( $id ) + array( 'modified' => $wpdb->get_var( $wpdb->prepare( "SELECT post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $id ) ) );
$b_file = $parse( $HEAD . "OK-1,Vendor B Kurta,B text,10,1,books,\n" );
$planB  = Importer::plan_csv( $B['user_id'], $b_file['rows'] );
nx_assert_same( 1, $planB['summary']['create'], "vendor B uploading vendor A's SKU plans a CREATE, not an update" );
$resB = Importer::apply( $B['user_id'], $planB );
$track( $B );
$bid = Repository::find_by_sku( $B['vendor_id'], 'OK-1' );
nx_assert( 1 === $resB['summary']['created'] && $bid > 0 && $bid !== $id, 'B got their own product with that SKU' );
nx_assert( 'Vendor B Kurta' === Repository::data( $bid )['name'] && $B['vendor_id'] === Repository::data( $bid )['vendor_id'], 'owned by B' );
nx_assert_same( $a_before, Repository::data( $id ) + array( 'modified' => $wpdb->get_var( $wpdb->prepare( "SELECT post_modified_gmt FROM {$wpdb->posts} WHERE ID = %d", $id ) ) ), "A's product is byte-for-byte untouched (data and modified time)" );
nx_assert_same( $id, Repository::find_by_sku( $A['vendor_id'], 'OK-1' ), "and still A's" );

/* ---------- who may import ---------- */
foreach ( array( 'a customer' => $C['user_id'], 'a pending applicant' => $P['user_id'], 'a suspended vendor' => $S['user_id'], 'a guest' => 0 ) as $label => $uid ) {
	$r = Importer::plan_csv( $uid, $parsed['rows'] );
	nx_assert( is_wp_error( $r ) && 'nexora_forbidden' === $r->get_error_code(), "$label cannot import" );
	nx_assert( is_wp_error( Importer::apply( $uid, $plan ) ), "$label cannot apply a plan" );
}

/* ---------- images: only the vendor's own uploads, by URL ---------- */
$own   = nx_test_attachment( $A['user_id'], 'own' );
$other = nx_test_attachment( $B['user_id'], 'other' );
$ci = $parse( $HEAD . 'IMG-1,With image,,10,1,other,' . wp_get_attachment_url( $own ) . "\nIMG-2,Others image,,10,1,other," . wp_get_attachment_url( $other ) . "\nIMG-3,External,,10,1,other,https://evil.example/x.jpg\nIMG-4,No image,,10,1,other,\n" );
$pl = Importer::plan_csv( $A['user_id'], $ci['rows'] );
$bl = array(); foreach ( $pl['rows'] as $row ) { $bl[ $row['line'] ] = $row; }
nx_assert( 'create' === $bl[2]['action'], 'an image URL from the vendor\'s own Media Library is accepted' );
nx_assert( 'error' === $bl[3]['action'] && isset( $bl[3]['errors']['image_url'] ), "another member's image URL is refused" );
nx_assert( 'error' === $bl[4]['action'] && isset( $bl[4]['errors']['image_url'] ), 'an external image URL is refused (Media Library URLs only)' );
nx_assert( 'create' === $bl[5]['action'], 'a blank image is fine' );
Importer::apply( $A['user_id'], $pl );
$track( $A );
nx_assert_same( $own, Repository::data( Repository::find_by_sku( $A['vendor_id'], 'IMG-1' ) )['image_id'], 'the accepted URL becomes the product image' );
$up = $parse( $HEAD . "IMG-1,With image,,10,1,other,\n" );
$pu = Importer::plan_csv( $A['user_id'], $up['rows'] );
nx_assert_same( 'unchanged', $pu['rows'][0]['action'], 'a blank image_url on an existing product keeps its image (nothing to change)' );

/* ---------- per-vendor limit ---------- */
add_filter( 'nexora_max_products', function () { return 3; } );
$L  = nx_test_vendor( 'csl', 'approved' );
$lf = $parse( $HEAD . "L-1,One,,10,1,other,\nL-2,Two,,10,1,other,\nL-3,Three,,10,1,other,\nL-4,Four,,10,1,other,\n" );
$lp = Importer::plan_csv( $L['user_id'], $lf['rows'] );
nx_assert( 3 === $lp['summary']['create'] && 1 === $lp['summary']['error'] && 'error' === $lp['rows'][3]['action'], 'rows beyond the per-vendor product limit are errors in the plan' );
Importer::apply( $L['user_id'], $lp );
$track( $L );
nx_assert_same( 3, Repository::count_for_vendor( $L['vendor_id'] ), 'and only the allowed number were created' );
remove_all_filters( 'nexora_max_products' );

/* ---------- the CSV is never stored ---------- */
$uploads = wp_upload_dir();
$stray   = glob( $uploads['basedir'] . '/*/*/*.csv' ) ?: array();
nx_assert_same( array(), array_values( array_filter( $stray, function ( $f ) { return false !== strpos( $f, 'nxcsv' ) || false !== strpos( $f, 'products' ); } ) ), 'no CSV was written to the uploads folder' );

foreach ( $files as $f ) { @unlink( $f ); }
nx_test_finish();
