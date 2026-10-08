<?php

namespace Nexora\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a template from templates/ with the given variables.
 * Templates contain markup only: every value they print is escaped there.
 */
class View {

	/**
	 * @param string $template Path under templates/ without .php, e.g. 'chat/layout'.
	 * @param array  $vars     Variables made available to the template.
	 * @return string The rendered HTML ('' when the template does not exist).
	 */
	public static function render( $template, array $vars = array() ) {

		$file = NEXORA_PATH . 'templates/' . ltrim( $template, '/' ) . '.php';

		if ( ! is_file( $file ) ) {
			return '';
		}

		extract( $vars, EXTR_SKIP );

		ob_start();
		include $file;

		return ob_get_clean();
	}

	/** Render and print. */
	public static function output( $template, array $vars = array() ) {
		echo self::render( $template, $vars );
	}
}
