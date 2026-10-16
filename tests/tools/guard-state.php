<?php
// Used by tests/guard.sh (wp eval-file tests/tools/guard-state.php with env NX_GUARD = save | hostile | compare | restore and
// NX_GUARD_FILE = a JSON file). Works on the RAW database rows of the options the suite touches, the real Marketplace Item
// (post row, meta rows, option) and the vendor role, so "exactly back" means byte for byte, autoload flag included.
global $wpdb;

$cmd  = (string) getenv( 'NX_GUARD' );
$file = (string) getenv( 'NX_GUARD_FILE' );

$names = array(
	'nexora_vendor_apis_enabled', 'nexora_api_sources', 'nexora_api_host_allowlist', 'nexora_product_categories',
	'nexora_marketplace_item_id', 'nexora_db_version', 'nexora_accounts_version', 'nexora_delete_data_on_uninstall',
	'woocommerce_coming_soon', 'woocommerce_store_pages_only', 'woocommerce_enable_guest_checkout',
	'woocommerce_enable_signup_and_login_from_checkout', 'woocommerce_enable_checkout_login_reminder', 'woocommerce_enable_myaccount_registration',
	'rewrite_rules',
);

$flush = function () use ( $names ) {
	foreach ( $names as $n ) { wp_cache_delete( $n, 'options' ); }
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
};

$state = function () use ( $wpdb, $names ) {

	$options = array();
	foreach ( $names as $n ) {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s", $n ), ARRAY_A );
		$options[ $n ] = $row ? $row : null;
	}

	$ids   = array_map( 'intval', $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_nexora_marketplace_item' AND meta_value = '1' ORDER BY post_id" ) );
	$items = array();
	foreach ( $ids as $id ) {
		$items[ $id ] = array(
			'post' => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A ),
			'meta' => $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id", $id ), ARRAY_A ),
		);
	}

	$role = get_role( 'nexora_vendor' );

	return array(
		'options'      => $options,
		'items'        => $items,
		'vendor_role'  => $role ? $role->capabilities : null,
		'orders'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('shop_order','shop_order_placehold')" ),
		'product_count'=> (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'" ),
	);
};

$hostile = array(
	'nexora_vendor_apis_enabled'  => array( 'option_value' => '1', 'autoload' => 'auto' ),
	'nexora_api_sources'          => array( 'option_value' => serialize( array(
		array( 'stock_default' => 77, 'create_categories' => false, 'id' => 'dummyjson', 'name' => 'DummyJSON', 'url' => 'https://dummyjson.com/products', 'format' => 'dummyjson', 'auth_type' => 'none', 'auth_header' => '', 'secret' => '', 'enabled' => true ),
		array( 'stock_default' => 77, 'create_categories' => false, 'id' => 'fakestore', 'name' => 'Fake Store', 'url' => 'https://fakestoreapi.com/products', 'format' => 'fakestore', 'auth_type' => 'none', 'auth_header' => '', 'secret' => '', 'enabled' => true ),
	) ), 'autoload' => 'auto' ),
	'nexora_api_host_allowlist'   => array( 'option_value' => "hostile.example\nother.example", 'autoload' => 'auto' ),
	'nexora_product_categories'   => array( 'option_value' => "Hostile Category\nOther", 'autoload' => 'auto' ),
	'woocommerce_coming_soon'     => array( 'option_value' => 'yes', 'autoload' => 'auto' ),
	'woocommerce_store_pages_only'=> array( 'option_value' => 'yes', 'autoload' => 'auto' ),
	'woocommerce_enable_guest_checkout' => array( 'option_value' => 'yes', 'autoload' => 'auto' ),
);

$write = function ( $name, $row ) use ( $wpdb ) {
	$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
	if ( null !== $row ) {
		$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => $row['option_value'], 'autoload' => $row['autoload'] ) );
	}
};

if ( 'save' === $cmd ) {
	file_put_contents( $file, wp_json_encode( $state() ) );
	echo "saved\n";

} elseif ( 'hostile' === $cmd ) {
	foreach ( $hostile as $name => $row ) { $write( $name, $row ); }
	$flush();
	file_put_contents( $file, wp_json_encode( $state() ) );
	echo "hostile values set\n";

} elseif ( 'restore' === $cmd ) {
	$saved = json_decode( (string) file_get_contents( $file ), true );
	foreach ( $saved['options'] as $name => $row ) { $write( $name, $row ); }
	$flush();
	echo "restored\n";

} elseif ( 'compare' === $cmd ) {
	$want = json_decode( (string) file_get_contents( $file ), true );
	$have = json_decode( wp_json_encode( $state() ), true );
	$diff = array();
	foreach ( $want['options'] as $name => $row ) {
		if ( $have['options'][ $name ] !== $row ) { $diff[] = "option $name: " . wp_json_encode( $row ) . ' -> ' . wp_json_encode( $have['options'][ $name ] ); }
	}
	foreach ( array( 'items', 'vendor_role', 'orders', 'product_count' ) as $k ) {
		if ( $have[ $k ] !== $want[ $k ] ) { $diff[] = "$k differs: " . substr( wp_json_encode( $want[ $k ] ), 0, 300 ) . ' -> ' . substr( wp_json_encode( $have[ $k ] ), 0, 300 ); }
	}
	if ( $diff ) {
		echo implode( "\n", $diff ), "\n";
		exit( 1 );
	}
	echo "identical\n";

} else {
	echo "unknown NX_GUARD command\n";
	exit( 2 );
}
