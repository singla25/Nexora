<?php
// Marketplace Phase 7b: the other record types (connections, member posts), the ACF side (field groups in code, the take-over of ACF's
// own save, inline validation, the not-active notice) and the retirement of the old raw-$_POST meta box save.
require_once __DIR__ . '/../bootstrap.php';

use Nexora\Admin_Edit\Acf_Bridge;
use Nexora\Admin_Edit\Audit;
use Nexora\Admin_Edit\Fields;
use Nexora\Admin_Edit\Groups;
use Nexora\Admin_Edit\Saver;

nx_test_reset_limits();
$admin = nx_test_admin();
wp_set_current_user( $admin );
$A = nx_test_user( 'aeoa' );
$B = nx_test_user( 'aeob' );
$meta = function ( $id, $key ) { clean_post_cache( $id ); return get_post_meta( $id, $key, true ); };
$types = array( 'nexora_customer', 'nexora_seller', 'nexora_cust_seller', 'nexora_product', 'user_connections', 'user_content' );

/* ---------- connections ---------- */
$conn = nx_test_connection( $A, $B, 'pending' );
nx_assert_same( array( 'status' ), Fields::names( 'user_connections', 'edit' ), 'connection: only the status is editable' );
nx_assert_same( array( 'sender_user_id', 'sender_profile_id', 'sender_user_name', 'receiver_user_id', 'receiver_profile_id', 'receiver_user_name' ), Fields::names( 'user_connections', 'readonly' ), 'connection: who is connected to whom is read-only' );
foreach ( array( 'accepted', 'rejected', 'removed', 'pending' ) as $st ) {
	$res = Saver::save( $conn, array( 'status' => $st ) );
	nx_assert( ! is_wp_error( $res ) && $st === $meta( $conn, 'status' ), "connection status can be set to $st" );
}
foreach ( array( 'banana', '', 'ACCEPTED', 'blocked' ) as $st ) {
	$res = Saver::save( $conn, array( 'status' => $st ) );
	nx_assert( is_wp_error( $res ) && ! empty( $res->get_error_data()['fields']['status'] ) && 'pending' === $meta( $conn, 'status' ), "connection status '$st' is refused" );
}
$ends = array( $meta( $conn, 'sender_user_id' ), $meta( $conn, 'receiver_user_id' ), $meta( $conn, 'sender_user_name' ), $meta( $conn, 'receiver_profile_id' ) );
$res  = Saver::save( $conn, array( 'sender_user_id' => $B['user_id'], 'receiver_user_id' => $A['user_id'], 'sender_user_name' => 'x', 'receiver_profile_id' => 1, 'status' => 'accepted' ) );
nx_assert( array( 'status' ) === $res && $ends === array( $meta( $conn, 'sender_user_id' ), $meta( $conn, 'receiver_user_id' ), $meta( $conn, 'sender_user_name' ), $meta( $conn, 'receiver_profile_id' ) ), 'the two ends of a connection cannot be changed' );
nx_assert( count( Audit::rows( 'user_connections', $conn ) ) >= 4, 'connection status changes are in the history' );

/* ---------- member posts ---------- */
$post = \Nexora\Content\Repository::create( $A['user_id'], $A['profile_id'], 'First title', 'First text' );
nx_test_track_post( $post );
nx_assert_same( array( 'title', 'description' ), Fields::names( 'user_content', 'edit' ), 'member post: title and text are editable' );
nx_assert_same( array( 'user_id', 'user_profile_id', 'user_name' ), Fields::names( 'user_content', 'readonly' ), 'member post: the author link is read-only' );
$res = Saver::save( $post, array( 'title' => 'Edited <b>title</b>', 'description' => "Edited text\nline two <b>x</b>" ) );
nx_assert( ! is_wp_error( $res ), 'an administrator edits a member post' );
clean_post_cache( $post );
nx_assert_same( array( 'Edited title', "Edited text\nline two x" ), array( get_post_field( 'post_title', $post ), get_post_field( 'post_content', $post ) ), 'with the front end\'s cleaning (plain text)' );
$res = Saver::save( $post, array( 'title' => '   ' ) );
nx_assert( is_wp_error( $res ) && ! empty( $res->get_error_data()['fields']['title'] ) && 'Edited title' === get_post_field( 'post_title', $post ), 'an empty title is refused, as on the front end' );
$own = array( $meta( $post, 'user_id' ), $meta( $post, 'user_profile_id' ), $meta( $post, 'user_name' ) );
Saver::save( $post, array( 'user_id' => $B['user_id'], 'user_profile_id' => $B['profile_id'], 'user_name' => 'x', 'description' => 'again' ) );
nx_assert_same( $own, array( $meta( $post, 'user_id' ), $meta( $post, 'user_profile_id' ), $meta( $post, 'user_name' ) ), 'author, author profile and name cannot be changed' );
nx_assert_same( (int) $A['user_id'], (int) get_post_field( 'post_author', $post ), 'nor the post author' );

/* ---------- types with no edit form ---------- */
nx_assert_same( array(), Fields::names( 'nexora_api_conn', 'edit' ), 'API connections have no edit fields (they hold keys)' );
nx_assert_same( array(), Fields::names( 'nexora_import_log', 'edit' ), 'import logs have no edit fields' );
$res = Saver::save( $post, array() );
nx_assert( ! is_wp_error( $res ) && array() === $res, 'saving nothing changes nothing' );
nx_assert( is_wp_error( Saver::save( 999999999, array( 'title' => 'x' ) ) ), 'a post that does not exist is refused' );
$page = wp_insert_post( array( 'post_type' => 'page', 'post_title' => 'nxtest page', 'post_status' => 'publish' ) ); nx_test_track_post( $page );
nx_assert( is_wp_error( Saver::save( $page, array( 'title' => 'x' ) ) ), 'a post of another type is refused' );

/* ---------- ACF: field groups in code ---------- */
if ( ! function_exists( 'acf_add_local_field_group' ) ) {
	echo "ACF not installed: the ACF part is skipped\n";
} else {
	Groups::register();
	foreach ( $types as $type ) {
		$group = acf_get_field_group( Groups::group_key( $type ) );
		nx_assert( is_array( $group ) && ! empty( $group['key'] ), "$type: a field group is registered in code" );
		$loc = wp_json_encode( $group['location'] ?? array() );
		nx_assert( false !== strpos( $loc, $type ), "$type: and it is shown on that record type only" );
		$names = array();
		foreach ( (array) acf_get_fields( $group['key'] ) as $f ) { $names[ $f['name'] ] = $f; }
		$want = array_merge( Fields::names( $type, 'edit' ), Fields::names( $type, 'readonly' ) );
		nx_assert_same( array(), array_values( array_diff( $want, array_keys( $names ) ) ), "$type: every field has an ACF field named like the stored meta key" );
		nx_assert_same( array(), array_values( array_diff( array_keys( $names ), $want ) ), "$type: and there are no others" );
		foreach ( Fields::names( $type, 'readonly' ) as $ro ) { nx_assert( ! empty( $names[ $ro ]['disabled'] ) || ! empty( $names[ $ro ]['readonly'] ), "$type: $ro is shown but cannot be edited" ); }
		foreach ( Fields::names( $type, 'edit' ) as $ed ) { nx_assert( empty( $names[ $ed ]['disabled'] ) && empty( $names[ $ed ]['readonly'] ), "$type: $ed can be edited" ); }
		foreach ( $names as $n => $f ) { nx_assert_same( Fields::field_key( $type, $n ), $f['key'], "$type: $n has the registered field key" ); }
	}
	$pf = acf_get_fields( Groups::group_key( 'nexora_product' ) );
	$by = array(); foreach ( $pf as $f ) { $by[ $f['name'] ] = $f; }
	nx_assert_same( 'number', $by['price']['type'] ?? '', 'price is a number field' );
	nx_assert_same( 'number', $by['stock']['type'] ?? '', 'stock is a number field' );
	nx_assert( 'select' === ( $by['status']['type'] ?? '' ) && array( 'publish', 'draft' ) === array_keys( $by['status']['choices'] ), 'product status offers publish and draft only' );
	nx_assert( 'select' === ( $by['category']['type'] ?? '' ) && array_keys( \Nexora\Products\Fields::categories() ) === array_keys( $by['category']['choices'] ), 'category offers exactly the configured categories' );
	$cb = array(); foreach ( acf_get_fields( Groups::group_key( 'nexora_cust_seller' ) ) as $f ) { $cb[ $f['name'] ] = $f; }
	nx_assert_same( array( 'pending', 'approved', 'rejected', 'suspended' ), array_keys( $cb['status']['choices'] ), 'seller status offers the four statuses' );

	/* ---- values come from where they are really stored ---- */
	$Av = nx_test_vendor( 'aeov', 'approved' );
	$pid = nx_test_product( $Av, 'AEO-1', '15.50', '3' );
	$shown = function ( $type, $id, $name ) { return acf_get_value( $id, acf_get_field( Fields::field_key( $type, $name ) ) ); };
	nx_assert_same( 'Order Test AEO-1', $shown( 'nexora_product', $pid, 'name' ), 'the product name field shows the post title' );
	nx_assert_same( 'd', $shown( 'nexora_product', $pid, 'description' ), 'the description field shows the post content' );
	nx_assert_same( 'publish', $shown( 'nexora_product', $pid, 'status' ), 'the status field shows the post status' );
	nx_assert_same( '15.50', (string) $shown( 'nexora_product', $pid, 'price' ), 'price shows the stored two-decimal value' );
	nx_assert_same( 'approved', $shown( 'nexora_cust_seller', $Av['vendor_id'], 'status' ), 'a seller record\'s status field shows the account status (user meta)' );

	/* ---- ACF\'s own save is replaced by ours ---- */
	foreach ( $types as $type ) {
		$some = get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids' ) );
		if ( $some ) { nx_assert( true === apply_filters( 'acf/form-post/skip_save', false, $some[0], get_post( $some[0] ) ), "$type: ACF is told to leave saving to us" ); }
	}
	nx_assert( false === apply_filters( 'acf/form-post/skip_save', false, $page, get_post( $page ) ), 'other post types are saved by ACF as usual' );

	/* ---- inline validation on the edit screen ---- */
	$vkey = Fields::field_key( 'nexora_product', 'price' );
	nx_test_admin_request( $pid, 'edit' );
	$_POST['acf'] = array( $vkey => '0', Fields::field_key( 'nexora_product', 'stock' ) => 'many' );
	$_REQUEST     = array_merge( $_GET, $_POST );
	acf_reset_validation_errors();
	do_action( 'acf/validate_save_post' );
	$errs = array_column( acf_get_validation_errors(), 'input' );
	nx_assert( in_array( 'acf[' . $vkey . ']', $errs, true ) && in_array( 'acf[' . Fields::field_key( 'nexora_product', 'stock' ) . ']', $errs, true ), 'bad values are flagged on their own fields before saving' );
	acf_reset_validation_errors();
	$_POST['acf'] = array( $vkey => '16.00' );
	do_action( 'acf/validate_save_post' );
	nx_assert( empty( acf_get_validation_errors() ), 'good values raise no error' );
	acf_reset_validation_errors();

	/* ---- the real edit-screen save: WordPress saves the post, our handler stores the fields ---- */
	nx_test_admin_request( $pid, 'edit' );
	$_POST['acf']       = array( $vkey => '21.00', Fields::field_key( 'nexora_product', 'name' ) => 'Via Screen' );
	$_POST['post_type'] = 'nexora_product';
	$_REQUEST           = array_merge( $_GET, $_POST );
	wp_update_post( array( 'ID' => $pid, 'post_title' => get_post_field( 'post_title', $pid ) ) );
	clean_post_cache( $pid );
	nx_assert_same( array( '21.00', 'Via Screen' ), array( $meta( $pid, 'price' ), get_post_field( 'post_title', $pid ) ), 'saving the edit screen stores the new price and name through the Service' );
	nx_test_admin_request( $pid, 'edit' );
	$_POST['acf'] = array( $vkey => '-3', Fields::field_key( 'nexora_product', 'name' ) => 'Should Not Stick' );
	$_REQUEST     = array_merge( $_GET, $_POST );
	wp_update_post( array( 'ID' => $pid, 'post_title' => 'Should Not Stick Either' ) );
	clean_post_cache( $pid );
	nx_assert_same( array( '21.00', 'Via Screen' ), array( $meta( $pid, 'price' ), get_post_field( 'post_title', $pid ) ), 'an invalid screen save stores nothing, not even the core title (product titles stay Service-owned)' );
	nx_assert( '' !== Acf_Bridge::take_notice( $admin ), 'and the administrator gets a message to show after the redirect' );
	nx_assert_same( '', Acf_Bridge::take_notice( $admin ), '(which is shown once)' );
	nx_test_admin_request( $pid, 'edit', array( 'user' => $Av['user_id'] ) );
	$_POST['acf'] = array( $vkey => '1.00' );
	$_REQUEST     = array_merge( $_GET, $_POST );
	wp_update_post( array( 'ID' => $pid, 'post_title' => get_post_field( 'post_title', $pid ) ) );
	nx_assert_same( '21.00', $meta( $pid, 'price' ), 'the same screen save by the seller changes nothing' );
	nx_test_admin_request_end();
	wp_set_current_user( $admin );
}

/* ---------- ACF not active: say so, keep the read-only screens ---------- */
add_filter( 'nexora_acf_active', '__return_false' );
nx_assert( ! Acf_Bridge::active(), 'the bridge reports ACF as not active when the filter says so' );
$notice = Acf_Bridge::notice_html();
nx_assert( false !== stripos( $notice, 'ACF' ) && false !== stripos( $notice, 'notice' ), 'a notice tells the administrator that editing needs ACF' );
nx_assert( '' !== Acf_Bridge::notice_html() && false === strpos( Acf_Bridge::notice_html(), '<input' ), '(it is only text)' );
ob_start();
( new \Nexora\Admin\Meta_Boxes() )->user_personal_details( get_post( $A['profile_id'] ) );
$box = ob_get_clean();
nx_assert( false !== strpos( $box, (string) $meta( $A['profile_id'], 'user_name' ) ), 'the profile screen still shows the stored details' );
nx_assert( 0 === preg_match( '/<(input|textarea|select)\b(?![^>]*\b(readonly|disabled)\b)/i', $box ), 'but every control in it is read-only' );
remove_filter( 'nexora_acf_active', '__return_false' );

/* ---------- the old raw save of the meta boxes is gone ---------- */
nx_test_admin_request( $A['profile_id'], 'edit' );
$_POST += array( 'first_name' => 'RawPost', 'user_name' => 'rawhack', 'email' => 'raw@evil.example', 'bio' => 'raw' );
$_REQUEST = array_merge( $_GET, $_POST );
do_action( 'save_post', $A['profile_id'], get_post( $A['profile_id'] ), true );
nx_assert( 'RawPost' !== $meta( $A['profile_id'], 'first_name' ) && 'rawhack' !== $meta( $A['profile_id'], 'user_name' ), 'a plain POST of profile fields no longer writes anything (only validated ACF values do)' );
$_POST['sender_user_id'] = 1; $_POST['status'] = 'accepted';
do_action( 'save_post', $conn, get_post( $conn ), true );
nx_assert( (string) $A['user_id'] === (string) $meta( $conn, 'sender_user_id' ), 'nor does a plain POST to a connection' );
nx_test_admin_request_end();
wp_set_current_user( $admin );
$src = file_get_contents( NEXORA_PATH . 'src/Admin/Meta_Boxes.php' );
nx_assert( false === strpos( $src, 'update_post_meta' ) && false === strpos( $src, 'save_meta_boxes' ), 'the code of the old meta boxes contains no meta write and no save handler' );

nx_test_finish();
