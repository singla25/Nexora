<?php
/**
 * Unit Tests — NEXORA_DASHBOARD_HELPER
 *
 * Run with: php dashboard/tests/test-dashboard-helper.php
 *
 * Pure-PHP; no WordPress required. Lightweight stubs at end of file.
 */

define( 'ABSPATH', true );
require_once __DIR__ . '/../class-dashboard-helper.php';

// ── Mini test runner ──────────────────────────────────────────────────────────
$PASS = $FAIL = 0;

function assert_equal( $label, $expected, $actual ): void {
    global $PASS, $FAIL;
    if ( $expected === $actual ) {
        echo "  ✅  $label\n"; $PASS++;
    } else {
        $e = var_export( $expected, true ); $a = var_export( $actual, true );
        echo "  ❌  $label\n     expected: $e\n     actual:   $a\n"; $FAIL++;
    }
}
function assert_true( $label, $value ): void  { assert_equal( $label, true,  (bool) $value ); }
function assert_false( $label, $value ): void { assert_equal( $label, false, (bool) $value ); }
function section( string $t ): void { echo "\n── $t ──\n"; }

function make_ctx(
    string $role_type    = 'guest',
    string $profile_role = 'user',
    string $viewer_role  = '',
    int    $profile_id   = 1,
    int    $current_uid  = 0,
    int    $owner_uid    = 0
): array {
    return [
        'current_user_id' => $current_uid,
        'profile_id'      => $profile_id,
        'owner_user_id'   => $owner_uid,
        'role_type'       => $role_type,
        'profile_role'    => $profile_role,
        'viewer_role'     => $viewer_role,
        'is_owner'        => $role_type === 'owner',
        'is_logged_in'    => $role_type !== 'guest',
    ];
}

// =============================================================================
// 1. resolve_role_type
// =============================================================================
section( 'resolve_role_type' );

global $_logged_in_user_id;
$_logged_in_user_id = 0;
assert_equal( 'guest when not logged in', 'guest',
    NEXORA_DASHBOARD_HELPER::resolve_role_type( 0, 5 ) );

$_logged_in_user_id = 3;
assert_equal( 'owner when IDs match', 'owner',
    NEXORA_DASHBOARD_HELPER::resolve_role_type( 3, 3 ) );

$_logged_in_user_id = 3;
assert_equal( 'viewer when IDs differ', 'viewer',
    NEXORA_DASHBOARD_HELPER::resolve_role_type( 3, 7 ) );

// =============================================================================
// 2. get_visible_tabs — full matrix
// =============================================================================
section( 'get_visible_tabs — guest→user profile' );
$tabs = NEXORA_DASHBOARD_HELPER::get_visible_tabs( make_ctx( 'guest', 'user', '' ) );
assert_true(  'info',         in_array( 'user-info',    $tabs, true ) );
assert_true(  'connections',  in_array( 'connections',  $tabs, true ) );
assert_false( 'notifications',in_array( 'notifications',$tabs, true ) );
assert_false( 'content',      in_array( 'content',      $tabs, true ) );
assert_false( 'market',       in_array( 'market',       $tabs, true ) );

section( 'get_visible_tabs — guest→vendor profile' );
$tabs = NEXORA_DASHBOARD_HELPER::get_visible_tabs( make_ctx( 'guest', 'vendor', '' ) );
assert_true(  'info',              in_array( 'user-info',   $tabs, true ) );
assert_false( 'connections hidden',in_array( 'connections', $tabs, true ) );

section( 'get_visible_tabs — user-owner' );
$tabs = NEXORA_DASHBOARD_HELPER::get_visible_tabs( make_ctx( 'owner', 'user', 'user', 1, 1, 1 ) );
assert_true( 'info',          in_array( 'user-info',     $tabs, true ) );
assert_true( 'connections',   in_array( 'connections',   $tabs, true ) );
assert_true( 'notifications', in_array( 'notifications', $tabs, true ) );
assert_true( 'content',       in_array( 'content',       $tabs, true ) );
assert_true( 'market',        in_array( 'market',        $tabs, true ) );

section( 'get_visible_tabs — vendor-owner (Fix #4: no connections/content)' );
$tabs = NEXORA_DASHBOARD_HELPER::get_visible_tabs( make_ctx( 'owner', 'vendor', 'vendor', 2, 2, 2 ) );
assert_true(  'info',             in_array( 'user-info',     $tabs, true ) );
assert_false( 'no connections',   in_array( 'connections',   $tabs, true ) );
assert_true(  'notifications',    in_array( 'notifications', $tabs, true ) );
assert_false( 'no content',       in_array( 'content',       $tabs, true ) );
assert_true(  'market',           in_array( 'market',        $tabs, true ) );

section( 'get_visible_tabs — user-viewer' );
$tabs = NEXORA_DASHBOARD_HELPER::get_visible_tabs( make_ctx( 'viewer', 'user', 'user', 1, 3, 1 ) );
assert_true(  'info',        in_array( 'user-info',    $tabs, true ) );
assert_true(  'connections', in_array( 'connections',  $tabs, true ) );
assert_false( 'no notif',    in_array( 'notifications',$tabs, true ) );

section( 'get_visible_tabs — vendor-viewer (Fix #4: only info)' );
$tabs = NEXORA_DASHBOARD_HELPER::get_visible_tabs( make_ctx( 'viewer', 'user', 'vendor', 1, 4, 1 ) );
assert_true(  'info',            in_array( 'user-info',   $tabs, true ) );
assert_false( 'no connections',  in_array( 'connections', $tabs, true ) );

// =============================================================================
// 3. get_info_subtabs — Fix #2 order + Fix #3 vendor docs
// =============================================================================
section( 'get_info_subtabs — user-owner order' );
$slugs = array_keys( NEXORA_DASHBOARD_HELPER::get_info_subtabs( make_ctx( 'owner', 'user', 'user' ) ) );
assert_equal( 'personal 1st', 'personal-info', $slugs[0] );
assert_equal( 'address 2nd',  'address-info',  $slugs[1] );
assert_equal( 'work 3rd',     'work-info',     $slugs[2] );
assert_equal( 'docs 4th',     'docs-info',     $slugs[3] );
assert_equal( 'security 5th', 'security-info', $slugs[4] );
assert_equal( 'count is 5',   5, count( $slugs ) );

section( 'get_info_subtabs — vendor-owner order (Fix #2 + Fix #3)' );
$slugs = array_keys( NEXORA_DASHBOARD_HELPER::get_info_subtabs( make_ctx( 'owner', 'vendor', 'vendor' ) ) );
assert_equal( 'personal 1st',   'personal-info', $slugs[0] );
assert_equal( 'address 2nd',    'address-info',  $slugs[1] );
assert_equal( 'business 3rd',   'work-info',     $slugs[2] );
assert_equal( 'docs 4th',       'docs-info',     $slugs[3] );
assert_equal( 'security 5th',   'security-info', $slugs[4] );
assert_equal( 'vendor-info 6th','vendor-info',   $slugs[5] );

section( 'get_info_subtabs — non-owner' );
assert_equal( 'guest → []',  [], NEXORA_DASHBOARD_HELPER::get_info_subtabs( make_ctx( 'guest' ) ) );
assert_equal( 'viewer → []', [], NEXORA_DASHBOARD_HELPER::get_info_subtabs( make_ctx( 'viewer', 'user', 'user' ) ) );

// =============================================================================
// 4. get_work_fields — Fix #1 correct meta keys
// =============================================================================
section( 'get_work_fields — vendor uses business_* keys (Fix #1)' );
$wf = NEXORA_DASHBOARD_HELPER::get_work_fields( 'vendor' );
assert_equal( 'heading', 'Business Information', $wf['heading'] );
assert_true(  'has business_email',   array_key_exists( 'business_email',   $wf['fields'] ) );
assert_true(  'has business_phone',   array_key_exists( 'business_phone',   $wf['fields'] ) );
assert_true(  'has business_address', array_key_exists( 'business_address', $wf['fields'] ) );
assert_false( 'no company_email',     array_key_exists( 'company_email',    $wf['fields'] ) );

section( 'get_work_fields — user uses company_* keys' );
$wf = NEXORA_DASHBOARD_HELPER::get_work_fields( 'user' );
assert_equal( 'heading', 'Work Information', $wf['heading'] );
assert_true(  'has company_email', array_key_exists( 'company_email', $wf['fields'] ) );

// =============================================================================
// 5. get_work_save_fields — includes business_* fields
// =============================================================================
section( 'get_work_save_fields — Fix #1 business fields included' );
$sf = NEXORA_DASHBOARD_HELPER::get_work_save_fields();
assert_true( 'business_email',   in_array( 'business_email',   $sf, true ) );
assert_true( 'business_phone',   in_array( 'business_phone',   $sf, true ) );
assert_true( 'business_address', in_array( 'business_address', $sf, true ) );

// =============================================================================
// 6. can_access_tab
// =============================================================================
section( 'can_access_tab' );
$owner_ctx = make_ctx( 'owner', 'user', 'user' );
$guest_ctx = make_ctx( 'guest', 'user', '' );
assert_true(  'owner can content',  NEXORA_DASHBOARD_HELPER::can_access_tab( 'content',   $owner_ctx ) );
assert_false( 'guest cannot content',NEXORA_DASHBOARD_HELPER::can_access_tab( 'content',  $guest_ctx ) );
assert_true(  'guest can info',     NEXORA_DASHBOARD_HELPER::can_access_tab( 'user-info', $guest_ctx ) );
assert_false( 'guest cannot market',NEXORA_DASHBOARD_HELPER::can_access_tab( 'market',    $guest_ctx ) );

// =============================================================================
// 7. context accessor helpers
// =============================================================================
section( 'Context accessor helpers' );
$oc = make_ctx( 'owner',  'vendor', 'vendor' );
$vc = make_ctx( 'viewer', 'user',   'user' );
$gc = make_ctx( 'guest',  'user',   '' );
assert_true(  'is_owner(owner)',       NEXORA_DASHBOARD_HELPER::is_owner( $oc ) );
assert_false( 'is_owner(guest)',       NEXORA_DASHBOARD_HELPER::is_owner( $gc ) );
assert_true(  'is_viewer(viewer)',     NEXORA_DASHBOARD_HELPER::is_viewer( $vc ) );
assert_true(  'is_guest(guest)',       NEXORA_DASHBOARD_HELPER::is_guest( $gc ) );
assert_true(  'is_vendor_profile',     NEXORA_DASHBOARD_HELPER::is_vendor_profile( $oc ) );
assert_true(  'visitor_is_vendor',     NEXORA_DASHBOARD_HELPER::visitor_is_vendor( $oc ) );
assert_false( 'visitor_not_vendor',    NEXORA_DASHBOARD_HELPER::visitor_is_vendor( $vc ) );

// =============================================================================
// 8. get_profile_url
// =============================================================================
section( 'get_profile_url' );
global $_post_meta_map;
$_post_meta_map[1]['user_name'] = 'testuser';
assert_equal( 'URL with username',
    'https://example.com/dashboard/testuser',
    NEXORA_DASHBOARD_HELPER::get_profile_url( 1 )
);
$_post_meta_map[99] = [ 'user_name' => '' ];
assert_equal( 'fallback URL when no username',
    'https://example.com/dashboard/',
    NEXORA_DASHBOARD_HELPER::get_profile_url( 99 )
);

// =============================================================================
// Summary
// =============================================================================
$total = $PASS + $FAIL;
echo "\n══════════════════════════════════════════\n";
echo "  Total: $total  |  ✅ Passed: $PASS  |  ❌ Failed: $FAIL\n";
echo "══════════════════════════════════════════\n";
exit( $FAIL > 0 ? 1 : 0 );


// =============================================================================
// WP STUBS — minimal WordPress shims so the helper can run standalone
// =============================================================================

$_logged_in_user_id = 0;
$_post_meta_map     = [];

function is_user_logged_in(): bool {
    global $_logged_in_user_id;
    return $_logged_in_user_id > 0;
}
function get_current_user_id(): int {
    global $_logged_in_user_id;
    return (int) $_logged_in_user_id;
}
function get_post_meta( int $post_id, string $key, bool $single = false ) {
    global $_post_meta_map;
    return $_post_meta_map[ $post_id ][ $key ] ?? ( $single ? '' : [] );
}
function update_post_meta( int $post_id, string $key, $value ): void {
    global $_post_meta_map;
    $_post_meta_map[ $post_id ][ $key ] = $value;
}
function get_user_meta( int $uid, string $key, bool $single = false ) { return $single ? '' : []; }
function get_userdata( int $uid ) {
    if ( ! $uid ) return false;
    $u = new stdClass(); $u->roles = []; return $u;
}
function site_url( string $path = '' ): string { return 'https://example.com' . $path; }
function home_url( string $path = '' ): string { return 'https://example.com' . $path; }
function get_query_var( string $var ): string { return ''; }
function wp_get_attachment_url( int $id ): string {
    return $id ? "https://example.com/uploads/doc-$id.jpg" : '';
}
function get_option( string $key, $default = false ) { return $default; }
function sanitize_text_field( string $s ): string { return trim( strip_tags( $s ) ); }
function get_posts( array $args ): array { return []; }
function wp_reset_postdata(): void {}
function get_the_ID(): int { return 0; }
class WP_Query {
    public function __construct( array $a ) {}
    public function have_posts(): bool { return false; }
    public function the_post(): void {}
}
class NEXORA_Notification {
    public function get_unread_count( int $uid ): int { return 0; }
}
