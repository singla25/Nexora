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

		try {
			return $write();
		} finally {
			self::$internal = $before;
		}
	}
}
