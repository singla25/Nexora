<?php
/**
 * Tiny helpers that produce Elementor element arrays (the same structure
 * Elementor stores in _elementor_data). Layout and look come from the
 * CSS classes (assets/css/elementor.css), so every element stays fully
 * editable in the Elementor editor.
 *
 * @package Nexora_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NXT_EL {

	private static $counter = 0;

	private static function id() {
		self::$counter++;
		return substr( md5( 'nxt' . self::$counter . wp_rand() ), 0, 7 );
	}

	/**
	 * Container (flexbox). $classes goes into Elementor's "CSS Classes".
	 */
	public static function container( $children = array(), $classes = '', $settings = array() ) {
		return array(
			'id'       => self::id(),
			'elType'   => 'container',
			'settings' => array_merge(
				array(
					'content_width' => 'full',
					'css_classes'   => $classes,
				),
				$settings
			),
			'elements' => $children,
			'isInner'  => false,
		);
	}

	public static function widget( $type, $settings = array(), $classes = '' ) {
		if ( '' !== $classes ) {
			$settings['_css_classes'] = $classes;
		}

		return array(
			'id'         => self::id(),
			'elType'     => 'widget',
			'widgetType' => $type,
			'settings'   => $settings,
			'elements'   => array(),
		);
	}

	public static function heading( $text, $tag = 'h2', $classes = '' ) {
		return self::widget(
			'heading',
			array(
				'title'       => $text,
				'header_size' => $tag,
			),
			$classes
		);
	}

	public static function text( $html, $classes = 'nxe-text' ) {
		return self::widget( 'text-editor', array( 'editor' => $html ), $classes );
	}

	/**
	 * @param string $style primary | secondary | inverse
	 */
	public static function button( $text, $url, $style = 'primary' ) {
		return self::widget(
			'button',
			array(
				'text' => $text,
				'link' => array(
					'url'         => $url,
					'is_external' => '',
					'nofollow'    => '',
				),
			),
			'nxe-btn nxe-btn--' . $style
		);
	}

	public static function image( $img, $classes = 'nxe-media', $alt = '' ) {
		return self::widget(
			'image',
			array(
				'image'      => array(
					'url'    => $img['url'],
					'id'     => $img['id'],
					'alt'    => $alt,
					'source' => $img['id'] ? 'library' : 'external',
				),
				'image_size' => 'full',
				'link_to'    => 'none',
			),
			$classes
		);
	}

	public static function shortcode( $shortcode, $classes = '' ) {
		return self::widget( 'shortcode', array( 'shortcode' => $shortcode ), $classes );
	}

	public static function html( $html, $classes = '' ) {
		return self::widget( 'html', array( 'html' => $html ), $classes );
	}

	/**
	 * Elementor Pro Nav Menu widget.
	 */
	public static function nav_menu( $slug, $layout = 'horizontal', $classes = '' ) {
		return self::widget(
			'nav-menu',
			array(
				'menu'     => $slug,
				'layout'   => $layout,
				'dropdown' => 'tablet',
				'toggle'   => 'burger',
			),
			$classes
		);
	}

	/**
	 * Centered boxed content area (max 1200px) used inside every section.
	 */
	public static function wrap( $children, $extra = '' ) {
		return self::container( $children, trim( 'nxe-wrap ' . $extra ) );
	}

	public static function section( $children, $classes = '' ) {
		return self::container( $children, trim( 'nxe-section ' . $classes ) );
	}
}
