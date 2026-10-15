<?php
// The admin menu as WordPress really builds it (the golden snapshot only calls register_main_menu(), so it
// never sees the post-type entries core adds on admin_menu). Vendors must sit under "Nexora System" for an
// administrator and must not exist for a member.
require_once __DIR__ . '/../bootstrap.php';

global $menu, $submenu, $_wp_submenu_nopriv, $_wp_menu_nopriv, $admin_page_hooks, $_registered_pages, $_parent_pages;
require_once ABSPATH . 'wp-admin/includes/plugin.php';

$saved = array( $menu ?? null, $submenu ?? null, $_wp_submenu_nopriv ?? null, $_wp_menu_nopriv ?? null, $admin_page_hooks ?? null, $_registered_pages ?? null, $_parent_pages ?? null );

$build = function ( $uid ) {
	global $menu, $submenu, $_wp_submenu_nopriv, $_wp_menu_nopriv, $admin_page_hooks, $_registered_pages, $_parent_pages;
	wp_set_current_user( $uid );
	$menu = $submenu = $_wp_submenu_nopriv = $_wp_menu_nopriv = $admin_page_hooks = $_registered_pages = $_parent_pages = array();
	set_current_screen( 'dashboard' );
	do_action( 'admin_menu' );
	$slugs = array_map( function ( $row ) { return $row[2]; }, (array) ( $submenu['nexora-system'] ?? array() ) );
	// WordPress keeps the top-level row in $menu and hides it later (wp-admin/includes/menu.php) when the user lacks its capability.
	$visible = array();
	foreach ( (array) $menu as $row ) {
		if ( current_user_can( $row[1] ) ) { $visible[] = $row[2]; }
	}
	return array( 'slugs' => $slugs, 'top' => $visible );
};

$admin  = nx_test_admin();
$member = nx_test_user( 'amember' );

$a = $build( $admin );
nx_assert( in_array( 'nexora-system', $a['top'], true ), 'admin: "Nexora System" is in the menu' );
nx_assert( in_array( 'edit.php?post_type=nexora_vendor', $a['slugs'], true ), 'admin: Vendors is listed under Nexora System' );
foreach ( array( 'user_profile', 'user_connections', 'user_content', 'nexora_product' ) as $cpt ) {
	nx_assert( in_array( 'edit.php?post_type=' . $cpt, $a['slugs'], true ), "admin: $cpt is still listed under Nexora System" );
}
foreach ( array( 'nexora-notifications', 'nexora-chat' ) as $page ) {
	nx_assert( in_array( $page, $a['slugs'], true ), "admin: $page is still listed" );
}
nx_assert_same( 1, count( array_keys( $a['slugs'], 'edit.php?post_type=nexora_vendor', true ) ), 'admin: Vendors appears exactly once' );

$m = $build( $member['user_id'] );
nx_assert( ! in_array( 'nexora-system', $m['top'], true ) && array() === $m['slugs'], 'member: no Nexora System menu at all' );
nx_assert( ! in_array( 'edit.php?post_type=nexora_vendor', $m['slugs'], true ) && ! in_array( 'edit.php?post_type=nexora_product', $m['slugs'], true ), 'member: no Vendors or Products entry' );
$pt = get_post_type_object( 'nexora_vendor' );
wp_set_current_user( $member['user_id'] );
nx_assert( ! current_user_can( $pt->cap->edit_posts ) && ! current_user_can( $pt->cap->read_private_posts ), 'member: cannot edit or read vendor applications' );
wp_set_current_user( $admin );
nx_assert( current_user_can( $pt->cap->edit_posts ), 'admin: can edit vendor applications' );

// A vendor (approved) is still only a member as far as wp-admin goes.
\Nexora\Accounts\Account_Types::set_status( $member['user_id'], 'pending' );
\Nexora\Accounts\Account_Types::set_status( $member['user_id'], 'approved' );
$v = $build( $member['user_id'] );
nx_assert( array() === $v['slugs'] && ! in_array( 'nexora-system', $v['top'], true ), 'approved vendor: still no Nexora System menu (the vendor area is front-end)' );

list( $menu, $submenu, $_wp_submenu_nopriv, $_wp_menu_nopriv, $admin_page_hooks, $_registered_pages, $_parent_pages ) = $saved;
wp_set_current_user( 0 );
nx_test_finish();
