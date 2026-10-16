<?php

namespace Nexora\Marketplace;

use Nexora\Accounts\Account_Types;
use Nexora\Products\Presenter;
use Nexora\Products\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * May this member buy this product, this many? The one set of rules behind the Add to cart button, the add endpoint,
 * cart load and checkout, so they can never disagree. It only reads, and everything it reports about the product (name,
 * price, image, vendor, stock) comes from the product post: nothing the browser sent is an input here except the
 * quantity, which must be a whole number from 1 to 99.
 */
class Cart_Rules {

	const MAX_QTY     = 99;
	const UNAVAILABLE = 'This product is not available.';

	/**
	 * Checks a purchase.
	 *
	 * @param mixed $product_id Product post id.
	 * @param int   $user_id    The buyer.
	 * @param mixed $quantity   Wanted quantity.
	 * @param mixed $in_cart    Quantity of this product already in the buyer's cart.
	 * @return array ok, code, message, snapshot (empty when the product does not exist)
	 */
	public static function check( $product_id, $user_id, $quantity, $in_cart = 0 ) {

		$user_id    = (int) $user_id;
		$product_id = self::whole( $product_id );
		$in_cart    = max( 0, (int) ( is_int( $in_cart ) || ( is_string( $in_cart ) && is_numeric( $in_cart ) ) ? $in_cart : 0 ) );

		if ( $user_id <= 0 ) {
			return self::result( false, 'not_logged_in', __( 'Please sign in to buy.', 'nexora' ) );
		}

		if ( $product_id <= 0 || Marketplace_Item::is_item( $product_id ) || Repository::owner_of( $product_id ) <= 0 ) {
			return self::result( false, 'not_found', self::unavailable() );
		}

		$snapshot = self::snapshot( $product_id );

		if ( 'publish' !== get_post_status( $product_id ) ) {
			return self::result( false, 'not_published', self::unavailable(), $snapshot );
		}

		if ( Account_Types::APPROVED !== Account_Types::vendor_status( $snapshot['owner_id'] ) ) {
			return self::result( false, 'vendor_unavailable', self::unavailable(), $snapshot );
		}

		if ( $snapshot['owner_id'] === $user_id ) {
			return self::result( false, 'own_product', __( 'You cannot buy your own product.', 'nexora' ), $snapshot );
		}

		$qty = self::quantity( $quantity );

		if ( $qty <= 0 ) {
			return self::result( false, 'bad_quantity', __( 'Choose a quantity from 1 to 99.', 'nexora' ), $snapshot );
		}

		if ( $qty + $in_cart > self::MAX_QTY ) {
			return self::result( false, 'bad_quantity', __( 'You can have at most 99 of one product in your cart.', 'nexora' ), $snapshot );
		}

		$stock = $snapshot['stock'];

		if ( $stock <= 0 ) {
			return self::result( false, 'out_of_stock', __( 'Out of stock.', 'nexora' ), $snapshot );
		}

		if ( $qty + $in_cart > $stock ) {

			$left = max( 0, $stock - $in_cart );

			if ( $in_cart > 0 && 0 === $left ) {
				/* translators: %d: units in stock. */
				$message = sprintf( __( 'You already have all %d available in your cart.', 'nexora' ), $stock );
			} elseif ( $in_cart > 0 ) {
				/* translators: 1: units that can still be added, 2: units already in the cart. */
				$message = sprintf( __( 'Only %1$d more can be added (you already have %2$d in your cart).', 'nexora' ), $left, $in_cart );
			} else {
				/* translators: %d: units left. */
				$message = sprintf( __( 'Only %d left.', 'nexora' ), $left );
			}

			return self::result( false, 'insufficient_stock', $message, $snapshot );
		}

		return self::result( true, 'ok', '', $snapshot );
	}

	/**
	 * What the product post says right now.
	 *
	 * @param int $product_id Product post id (an existing product).
	 * @return array name, price (two decimals, text), price_float, stock, vendor_id, vendor_name, owner_id, image_id, image_url
	 */
	public static function snapshot( $product_id ) {

		$data      = Repository::data( $product_id );
		$vendor_id = (int) $data['vendor_id'];

		return array(
			'name'        => (string) $data['name'],
			'price'       => number_format( (float) $data['price'], 2, '.', '' ),
			'price_float' => round( (float) $data['price'], 2 ),
			'stock'       => (int) $data['stock'],
			'vendor_id'   => $vendor_id,
			'vendor_name' => $vendor_id ? (string) get_post_meta( $vendor_id, 'business_name', true ) : '',
			'owner_id'    => Repository::owner_of( $product_id ),
			'image_id'    => (int) $data['image_id'],
			'image_url'   => Presenter::image( $product_id, (int) $data['image_id'], 'thumbnail' ),
		);
	}

	/**
	 * The one message used for every "you cannot have this" case that must not say why (missing, unpublished, vendor not approved).
	 *
	 * @return string
	 */
	public static function unavailable() {
		return __( 'This product is not available.', 'nexora' );
	}

	/**
	 * A quantity as a whole number from 1 to 99, or 0 when it is anything else (text, decimals, exponents, signs, arrays...).
	 *
	 * @param mixed $value Raw quantity.
	 * @return int
	 */
	public static function quantity( $value ) {

		if ( is_string( $value ) && ctype_digit( $value ) && strlen( $value ) <= 3 ) {
			$value = (int) $value;
		}

		return is_int( $value ) && $value >= 1 && $value <= self::MAX_QTY ? $value : 0;
	}

	/**
	 * A positive whole number from an int or a plain digit string, else 0.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	private static function whole( $value ) {

		if ( is_string( $value ) && ctype_digit( $value ) && strlen( $value ) <= 12 ) {
			return (int) $value;
		}

		return is_int( $value ) && $value > 0 ? $value : 0;
	}

	/**
	 * Shapes a result.
	 *
	 * @param bool   $ok       Whether the purchase is allowed.
	 * @param string $code     Machine-readable reason.
	 * @param string $message  Plain-words reason for the buyer.
	 * @param array  $snapshot The product as it is now.
	 * @return array
	 */
	private static function result( $ok, $code, $message, array $snapshot = array() ) {

		return array(
			'ok'       => $ok,
			'code'     => $code,
			'message'  => $message,
			'snapshot' => $snapshot,
		);
	}
}
