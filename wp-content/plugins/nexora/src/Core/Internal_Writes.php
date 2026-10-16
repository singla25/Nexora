<?php

namespace Nexora\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * For a Repository that owns a guarded post type: marks its own writes so Core\Post_Guard lets them through
 * and refuses everything else. Each using class gets its own flag.
 */
trait Internal_Writes {

	/**
	 * True while the Repository itself is writing.
	 *
	 * @var bool
	 */
	private static $internal = false;

	/**
	 * Whether the current write comes from the Repository (and so from its Service).
	 *
	 * @return bool
	 */
	public static function is_internal() {
		return self::$internal;
	}

	/**
	 * Runs a write as the Repository, so the Guard lets it through.
	 *
	 * @param callable $write What to do.
	 * @return mixed What the callable returns.
	 */
	private static function internally( callable $write ) {

		$before         = self::$internal;
		self::$internal = true;

		// The text was already reduced to plain text by the validator and is always escaped when printed. WordPress's
		// post filters (kses, for users without unfiltered_html) would rewrite it into HTML entities ("&" to "&amp;"),
		// so what was stored would never equal what was validated.
		$kses = false !== has_filter( 'title_save_pre', 'wp_filter_kses' ) || false !== has_filter( 'content_save_pre', 'wp_filter_post_kses' );

		if ( $kses ) {
			kses_remove_filters();
		}

		try {
			return $write();
		} finally {
			self::$internal = $before;

			if ( $kses ) {
				kses_init_filters();
			}
		}
	}
}
