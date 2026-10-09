<?php
/**
 * Theme supports, menus, assets.
 *
 * @package Nexora_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function nxt_setup() {
	load_theme_textdomain( 'nexora-theme', NXT_DIR . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'custom-logo', array(
		'height'      => 64,
		'width'       => 200,
		'flex-height' => true,
		'flex-width'  => true,
	) );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'responsive-embeds' );

	register_nav_menus( array(
		'primary' => __( 'Main navigation (header)', 'nexora-theme' ),
		'footer'  => __( 'Footer navigation', 'nexora-theme' ),
	) );

	add_image_size( 'nxt-card', 640, 420, true );
}
add_action( 'after_setup_theme', 'nxt_setup' );

function nxt_enqueue_assets() {
	wp_enqueue_style(
		'nxt-fonts',
		'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800;1,600&display=swap',
		array(),
		null
	);

	wp_enqueue_style( 'nxt-style', NXT_URI . '/assets/css/style.css', array( 'nxt-fonts' ), NXT_VERSION );

	wp_enqueue_script( 'nxt-main', NXT_URI . '/assets/js/main.js', array(), NXT_VERSION, true );

	if ( is_singular() && comments_open() ) {
		wp_enqueue_script( 'comment-reply' );
	}
}
add_action( 'wp_enqueue_scripts', 'nxt_enqueue_assets' );

function nxt_resource_hints( $urls, $relation_type ) {
	if ( 'preconnect' === $relation_type ) {
		$urls[] = array( 'href' => 'https://fonts.gstatic.com', 'crossorigin' );
		$urls[] = 'https://fonts.googleapis.com';
	}
	return $urls;
}
add_filter( 'wp_resource_hints', 'nxt_resource_hints', 10, 2 );

function nxt_widgets_init() {
	register_sidebar( array(
		'name'          => __( 'Blog Sidebar', 'nexora-theme' ),
		'id'            => 'blog-sidebar',
		'before_widget' => '<div class="nxt-widget %2$s">',
		'after_widget'  => '</div>',
		'before_title'  => '<h3 class="nxt-widget-title">',
		'after_title'   => '</h3>',
	) );
}
add_action( 'widgets_init', 'nxt_widgets_init' );

function nxt_trim_head() {
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );
}
add_action( 'init', 'nxt_trim_head' );

add_filter( 'excerpt_length', function () {
	return 30;
} );
add_filter( 'excerpt_more', function () {
	return '&hellip;';
} );

/**
 * Header menu used until an admin assigns a menu. It reflects the visitor's
 * state: guests see Login / Register, members see their profile and a
 * logout link. All URLs are resolved dynamically.
 */
function nxt_default_primary_menu() {
	echo '<ul class="nxt-nav__list">';

	printf( '<li><a href="%s">%s</a></li>', esc_url( home_url( '/' ) ), esc_html__( 'Home', 'nexora-theme' ) );

	if ( is_user_logged_in() ) {
		printf( '<li><a href="%s">%s</a></li>', esc_url( nxt_profile_url() ), esc_html__( 'My profile', 'nexora-theme' ) );
	}

	$blog = (int) get_option( 'page_for_posts' );
	if ( $blog ) {
		printf( '<li><a href="%s">%s</a></li>', esc_url( get_permalink( $blog ) ), esc_html( get_the_title( $blog ) ) );
	}

	echo '</ul>';
}

function nxt_default_footer_menu() {
	$pages = get_pages( array(
		'sort_column' => 'menu_order,post_title',
		'number'      => 6,
		'exclude'     => implode( ',', array_filter( array(
			(int) get_option( 'page_on_front' ),
			(int) get_option( 'page_for_posts' ),
			(int) url_to_postid( home_url( '/login-page/' ) ),
			(int) url_to_postid( home_url( '/registration-page/' ) ),
		) ) ),
	) );

	foreach ( $pages as $page ) {
		printf( '<li><a href="%s">%s</a></li>', esc_url( get_permalink( $page ) ), esc_html( get_the_title( $page ) ) );
	}
}
