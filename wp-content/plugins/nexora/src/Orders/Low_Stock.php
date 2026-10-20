<?php

namespace Nexora\Orders;

use Nexora\Products\Repository as Products;
use Nexora\Vendors\Repository as Sellers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The low-stock alert. One setting (Nexora System > Settings > Low-stock threshold): a whole number from 0 to 100000, 5 until changed,
 * 0 = alerts off; anything else in the database reads as 5.
 *
 * When a product's stock falls to the threshold or below, its seller gets one in-app notification and one e-mail. The alert is
 * remembered on the product (`_nx_low_stock_alerted`) and armed again only after the stock rises above the threshold, so a stock that
 * keeps falling, or is saved again, never repeats it. It runs after the atomic decrement at checkout and when stock is edited (the
 * seller's product form, an administrator, an import): an edit alerts only when it LOWERS the stock.
 */
class Low_Stock {

	const OPTION  = 'nexora_low_stock_threshold';
	const DEFAULT = 5;
	const MAX     = 100000;
	const FLAG    = '_nx_low_stock_alerted';

	/**
	 * The value of the stock being replaced, kept for the moment the edit is done.
	 *
	 * @var array
	 */
	private static $before = array();

	/**
	 * Hooks the stock edits.
	 */
	public static function hooks() {
		add_filter( 'update_post_metadata', array( __CLASS__, 'remember' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'edited' ), 10, 4 );
	}

	/**
	 * The threshold: 0 to 100000; anything else in the database reads as 5.
	 *
	 * @return int
	 */
	public static function threshold() {

		$clean = self::parse( get_option( self::OPTION, (string) self::DEFAULT ) );

		return null === $clean ? self::DEFAULT : $clean;
	}

	/**
	 * What the Settings form may save: plain digits, 0 to 100000. Anything else is refused and the current value stays.
	 *
	 * @param mixed $value Posted value.
	 * @return string
	 */
	public static function sanitize( $value ) {

		$clean = self::parse( $value );

		if ( null === $clean ) {

			if ( function_exists( 'add_settings_error' ) ) {
				add_settings_error( self::OPTION, self::OPTION, __( 'The low-stock threshold must be a whole number from 0 to 100000 (0 turns the alerts off). The previous value was kept.', 'nexora' ) );
			}

			return (string) self::threshold();
		}

		return (string) $clean;
	}

	/**
	 * Looks at a product after its stock changed: alerts once when it is at or below the threshold, re-arms when it is above.
	 *
	 * @param int  $product_id Product post id.
	 * @param bool $fell       Whether the stock went DOWN (checkout, or an edit that lowered it). A raise only re-arms.
	 */
	public static function check( $product_id, $fell = true ) {

		$product_id = (int) $product_id;
		$threshold  = self::threshold();

		if ( $product_id <= 0 || 0 === $threshold || Products::POST_TYPE !== get_post_type( $product_id ) ) {
			return;
		}

		$stock = (int) get_post_meta( $product_id, Stock::META, true );

		if ( $stock > $threshold ) {
			delete_post_meta( $product_id, self::FLAG );
			return;
		}

		if ( ! $fell ) {
			return;
		}

		$user_id = Sellers::user_for( Products::vendor_of( $product_id ) );

		// add_post_meta( ..., unique ) is the once-only switch: it fails when the product is already marked.
		if ( ! $user_id || ! add_post_meta( $product_id, self::FLAG, (string) time(), true ) ) {
			return;
		}

		$data = Products::data( $product_id );

		Mailer::notify(
			$user_id,
			'low_stock',
			sprintf(
				/* translators: 1: product name, 2: SKU, 3: units left */
				__( 'Low stock: "%1$s" (%2$s) has %3$d left.', 'nexora' ),
				$data['name'],
				$data['sku'],
				$stock
			)
		);
		Mailer::low_stock( $product_id );
	}

	/**
	 * Filter update_post_metadata: remembers the stock that is about to be replaced (changes nothing).
	 *
	 * @param mixed  $check    Short-circuit value (passed on).
	 * @param int    $post_id  Post id.
	 * @param string $meta_key Meta key.
	 * @return mixed
	 */
	public static function remember( $check, $post_id, $meta_key ) {

		if ( Stock::META === $meta_key && Products::POST_TYPE === get_post_type( $post_id ) ) {
			self::$before[ (int) $post_id ] = (int) get_post_meta( $post_id, Stock::META, true );
		}

		return $check;
	}

	/**
	 * Action updated_post_meta: a product's stock was edited.
	 *
	 * @param int    $meta_id    Meta id.
	 * @param int    $post_id    Post id.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 */
	public static function edited( $meta_id, $post_id, $meta_key, $meta_value ) {

		unset( $meta_id );

		if ( Stock::META !== $meta_key || ! isset( self::$before[ (int) $post_id ] ) ) {
			return;
		}

		$was = self::$before[ (int) $post_id ];
		unset( self::$before[ (int) $post_id ] );

		try {
			self::check( (int) $post_id, (int) $meta_value < $was );
		} catch ( \Throwable $e ) {
			unset( $e ); // An alert must never break a product edit.
		}
	}

	/**
	 * A whole number from 0 to 100000 from text such as "5" or " 12 ", or null.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	private static function parse( $value ) {

		if ( is_int( $value ) ) {
			$value = (string) $value;
		}

		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );

		if ( ! preg_match( '/^[0-9]{1,6}$/', $value ) || (int) $value > self::MAX ) {
			return null;
		}

		return (int) $value;
	}
}
