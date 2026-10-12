<?php
// The Better Messages user search is limited to the searching member's accepted connections.
require_once __DIR__ . '/../bootstrap.php';

$alice = nx_test_user( 'bmalice' );
$bob   = nx_test_user( 'bmbob' );
$eve   = nx_test_user( 'bmeve' );
$dan   = nx_test_user( 'bmdan' );
nx_test_connection( $alice, $bob, 'accepted' );
nx_test_connection( $eve, $alice, 'pending' );
nx_test_connection( $dan, $alice, 'accepted' );   // alice is the receiver here
nx_test_connection( $bob, $eve, 'accepted' );      // unrelated to alice

$bm = new \Nexora\Integrations\Better_Messages();
nx_assert( (bool) has_filter( 'better_messages_search_user_sql_condition', array( $bm, 'nexora_filter_search_query_users' ) ) || (bool) has_filter( 'better_messages_search_user_sql_condition' ), 'filter is registered' );

$cond = $bm->nexora_filter_search_query_users( array(), array(), '', $alice['user_id'] );
$ids  = array();
if ( preg_match( '/AND ID IN \(([0-9,]+)\)/', implode( ' ', $cond ), $m ) ) { $ids = array_map( 'intval', explode( ',', $m[1] ) ); }
sort( $ids );
$expected = array( $bob['user_id'], $dan['user_id'] );
sort( $expected );
nx_assert_same( $expected, $ids, 'a member can find exactly their accepted connections (sender or receiver side)' );
nx_assert( ! in_array( $eve['user_id'], $ids, true ), 'pending connections are not searchable' );

$lonely = nx_test_user( 'bmlonely' );
$cond = $bm->nexora_filter_search_query_users( array( 'x' ), array(), '', $lonely['user_id'] );
nx_assert_same( array( 'x', 'AND 1=0' ), $cond, 'a member with no connections finds nobody (existing conditions kept)' );

$cond = $bm->nexora_filter_search_query_users( array(), array(), '', 999999999 );
nx_assert_same( array( 'AND 1=0' ), $cond, 'a user without a profile finds nobody' );

// cost: the query count must not grow with the number of connections on the whole site
global $wpdb;
$before = $wpdb->num_queries;
$bm->nexora_filter_search_query_users( array(), array(), '', $alice['user_id'] );
$used = $wpdb->num_queries - $before;
nx_assert( $used <= 12, "search runs a bounded number of queries ($used)" );

nx_test_finish();
