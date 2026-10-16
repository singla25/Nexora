<?php

namespace Nexora\Marketplace;

use Nexora\Products\Presenter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The WooCommerce side of buying a marketplace product. A cart line is the hidden Marketplace Item plus ONE piece of data:
 * the id of the product post (nexora_product_id). Everything shown or charged is read from that post, never from the
 * request.
 *
 * - Only add() below can put the hidden item in a cart; ?add-to-cart=, wc-ajax and the Store API cannot, and guests cannot
 *   add anything.
 * - On every load each line is re-read from the product post (name, price, image, "Sold by"), lines that can no longer be
 *   bought are removed, quantities are lowered to the stock, price changes are noted, and the lines are grouped by vendor.
 *   What the member last saw, and the list of what changed, live in the WooCommerce session, so the cart-item data stays
 *   "product id only".
 * - At checkout the same rules run again WITHOUT fixing anything: a problem line blocks the order with a plain message.
 */
class Cart_Bridge {

	const SEEN    = 'nexora_cart_seen';
	const CHANGES = 'nexora_cart_changes';
	const KEY     = 'nexora_product_id';

	/**
	 * The product post id being added by add() right now (0 at any other time).
	 *
	 * @var int
	 */
	private static $adding = 0;

	/**
	 * Guards against re-entering the load-time check.
	 *
	 * @var bool
	 */
	private static $busy = false;

	/**
	 * Hooks the gate, the hydration, the load-time check and the checkout checks.
	 */
	public function __construct() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'gate' ), 1, 6 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'guard' ), 1, 4 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'strip' ), 99, 4 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'from_session' ), 20, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'hydrate_cart' ), 5 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 10, 2 );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'revalidate' ), 20 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'classic_check' ) );
		add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'store_errors' ), 10, 2 );
		add_action( 'woocommerce_store_api_validate_cart_item', array( __CLASS__, 'store_item' ), 10, 2 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( __CLASS__, 'store_add' ) );
	}

	/**
	 * Adds a marketplace product to the cart of the current member. The only way in.
	 *
	 * @param int   $user_id    The buyer.
	 * @param mixed $product_id Product post id.
	 * @param mixed $quantity   Wanted quantity.
	 * @return array|\WP_Error array( 'key' => cart item key )
	 */
	public static function add( $user_id, $product_id, $quantity ) {

		if ( ! function_exists( 'WC' ) ) {
			return new \WP_Error( 'unavailable', __( 'Shopping is not available right now.', 'nexora' ) );
		}

		if ( null === WC()->cart ) {
			wc_load_cart();
		}

		$product_id = (int) ( is_string( $product_id ) && ctype_digit( $product_id ) ? $product_id : ( is_int( $product_id ) ? $product_id : 0 ) );
		$rules      = Cart_Rules::check( $product_id, $user_id, $quantity, self::quantity_in_cart( $product_id ) );

		if ( ! $rules['ok'] ) {
			return new \WP_Error( $rules['code'], $rules['message'] );
		}

		$item = Marketplace_Item::id();

		if ( ! $item ) {
			return new \WP_Error( 'unavailable', __( 'Shopping is not available right now. Please try again in a moment.', 'nexora' ) );
		}

		self::$adding = $product_id;

		try {
			$key = WC()->cart->add_to_cart( $item, Cart_Rules::quantity( $quantity ), 0, array(), array( self::KEY => $product_id ) );
		} finally {
			self::$adding = 0;
		}

		if ( ! $key ) {
			return new \WP_Error( 'not_added', __( 'This product could not be added to your cart.', 'nexora' ) );
		}

		self::remember( $product_id, $rules['snapshot'] );

		return array( 'key' => $key );
	}

	/**
	 * The gate: guests add nothing, and the hidden item is only ever added by add().
	 *
	 * @param bool  $valid          Whether the add is valid so far.
	 * @param int   $product_id     Product being added.
	 * @param int   $quantity       Quantity (unused).
	 * @param int   $variation_id   Variation (unused).
	 * @param array $variations     Variation attributes (unused).
	 * @param array $cart_item_data Data posted with the add.
	 * @return bool
	 */
	public static function gate( $valid, $product_id, $quantity = 1, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {

		unset( $quantity, $variation_id, $variations );

		if ( ! is_user_logged_in() ) {
			wc_add_notice( __( 'Please sign in to add products to your cart.', 'nexora' ), 'error' );
			return false;
		}

		if ( Marketplace_Item::is_item( (int) $product_id ) ) {

			$pid = isset( $cart_item_data[ self::KEY ] ) && is_int( $cart_item_data[ self::KEY ] ) ? $cart_item_data[ self::KEY ] : 0;

			if ( self::$adding <= 0 || $pid !== self::$adding ) {
				wc_add_notice( Cart_Rules::unavailable(), 'error' );
				return false;
			}
		}

		return $valid;
	}

	/**
	 * Same rules as gate(), but on a hook that runs inside WC_Cart::add_to_cart() itself,
	 * which never applies woocommerce_add_to_cart_validation. A throw there becomes a refusal.
	 *
	 * @param array $data         Cart item data.
	 * @param int   $product_id   Product being added.
	 * @param int   $variation_id Variation (unused).
	 * @param int   $quantity     Quantity (unused).
	 * @return array
	 * @throws \Exception When the add is not allowed.
	 */
	public static function guard( $data, $product_id, $variation_id = 0, $quantity = 1 ) {

		unset( $variation_id, $quantity );

		if ( ! is_user_logged_in() ) {
			throw new \Exception( esc_html__( 'Please sign in to add products to your cart.', 'nexora' ) );
		}

		if ( Marketplace_Item::is_item( (int) $product_id ) ) {

			$pid = is_array( $data ) && isset( $data[ self::KEY ] ) && is_int( $data[ self::KEY ] ) ? $data[ self::KEY ] : 0;

			if ( self::$adding <= 0 || $pid !== self::$adding ) {
				throw new \Exception( esc_html( Cart_Rules::unavailable() ) );
			}
		}

		return $data;
	}

	/**
	 * Whatever came with the add, the hidden item's line carries only the product id add() is adding.
	 *
	 * @param array $data         Cart item data.
	 * @param int   $product_id   Product being added.
	 * @param int   $variation_id Variation (unused).
	 * @param int   $quantity     Quantity (unused).
	 * @return array
	 */
	public static function strip( $data, $product_id, $variation_id = 0, $quantity = 1 ) {

		unset( $variation_id, $quantity );

		if ( ! Marketplace_Item::is_item( (int) $product_id ) ) {
			return $data;
		}

		return self::$adding > 0 ? array( self::KEY => self::$adding ) : array();
	}

	/**
	 * A line coming back from the session is re-read from the product post.
	 *
	 * @param array  $session_data The line with its product object.
	 * @param array  $values       The stored values (unused).
	 * @param string $key          Cart item key (unused).
	 * @return array
	 */
	public static function from_session( $session_data, $values = array(), $key = '' ) {

		unset( $values, $key );

		return self::hydrate( $session_data );
	}

	/**
	 * Before the totals are worked out, every marketplace line gets the product post's name, price and image.
	 *
	 * @param \WC_Cart $cart The cart.
	 */
	public static function hydrate_cart( $cart ) {

		foreach ( $cart->get_cart() as $line ) {
			self::hydrate( $line );
		}
	}

	/**
	 * Adds "Sold by <business>" to a line's details (the block cart shows these too).
	 *
	 * @param array $item_data Details so far.
	 * @param array $line      The cart line.
	 * @return array
	 */
	public static function item_data( $item_data, $line ) {

		$pid = self::pid_of( $line );

		if ( $pid > 0 && \Nexora\Products\Repository::owner_of( $pid ) > 0 ) {

			$name = Cart_Rules::snapshot( $pid )['vendor_name'];

			if ( '' !== $name ) {
				$item_data[] = array(
					'key'     => __( 'Sold by', 'nexora' ),
					'value'   => $name,
					'display' => $name,
				);
			}
		}

		return $item_data;
	}

	/**
	 * On every cart load: fix what changed and say so. Lines that can no longer be bought are removed, quantities are lowered
	 * to the stock, price changes are noted, and the lines are grouped by vendor.
	 *
	 * @param \WC_Cart $cart The cart.
	 */
	public static function revalidate( $cart ) {

		if ( self::$busy || ! function_exists( 'WC' ) || null === WC()->session ) {
			return;
		}

		self::$busy = true;

		try {
			$user  = get_current_user_id();
			$seen  = (array) WC()->session->get( self::SEEN, array() );
			$lines = $cart->get_cart();
			$kept  = array();
			$dirty = false;
			$pids  = array_filter( array_map( array( __CLASS__, 'pid_of' ), $lines ) );

			if ( $pids ) {
				_prime_post_caches( array_values( $pids ), false, true );
			}

			foreach ( $lines as $key => $line ) {

				$pid = self::pid_of( $line );

				if ( $pid <= 0 ) {
					$kept[ $key ] = $line;
					continue;
				}

				$qty   = (int) $line['quantity'];
				$rules = Cart_Rules::check( $pid, $user, $qty );
				$snap  = $rules['snapshot'];
				$name  = '' !== ( $snap['name'] ?? '' ) ? $snap['name'] : ( $seen[ $pid ]['name'] ?? __( 'A product', 'nexora' ) );

				if ( $rules['ok'] ) {
					self::note_price( $pid, $name, $snap['price'], $seen );
					$kept[ $key ] = $line;
					continue;
				}

				$dirty = true;

				if ( 'insufficient_stock' === $rules['code'] && $snap['stock'] > 0 ) {
					$line['quantity'] = (int) $snap['stock'];
					$kept[ $key ]     = $line;
					self::note_price( $pid, $name, $snap['price'], $seen );
					self::note(
						sprintf(
						/* translators: 1: product name, 2: units left. */
							__( '"%1$s": only %2$d left, so your quantity was lowered.', 'nexora' ),
							$name,
							(int) $snap['stock']
						)
					);
					continue;
				}

				if ( 'bad_quantity' === $rules['code'] && $qty > Cart_Rules::MAX_QTY ) {
					$line['quantity'] = Cart_Rules::MAX_QTY;
					$kept[ $key ]     = $line;
					continue;
				}

				unset( $seen[ $pid ] );

				if ( 'not_logged_in' === $rules['code'] ) {
					continue;
				}

				self::note(
					'out_of_stock' === $rules['code']
					/* translators: %s: product name. */
					? sprintf( __( '"%s" is sold out and was removed from your cart.', 'nexora' ), $name )
					/* translators: %s: product name. */
					: sprintf( __( '"%s" is no longer available and was removed from your cart.', 'nexora' ), $name )
				);
			}

			$sorted = self::group_by_vendor( $kept );

			if ( array_keys( $sorted ) !== array_keys( $lines ) || $dirty ) {
				$cart->set_cart_contents( $sorted );
				WC()->session->set( 'cart', $sorted ? $cart->get_cart_for_session() : null );
				$cart->calculate_totals();

				if ( $user ) {
					$cart->persistent_cart_update();
				}
			}

			WC()->session->set( self::SEEN, array_filter( $seen ) );
		} finally {
			self::$busy = false;
		}
	}

	/**
	 * The classic cart and checkout pages: raise an error notice for every line that cannot be bought.
	 */
	public static function classic_check() {

		foreach ( self::problems( WC()->cart ) as $problem ) {
			wc_add_notice( $problem['message'], 'error' );
		}
	}

	/**
	 * The Store API (the Cart and Checkout blocks): add an error for every line that cannot be bought. The Checkout route
	 * runs this before it creates an order, so a problem blocks the order. Read-only.
	 *
	 * @param \WP_Error $errors The errors so far.
	 * @param \WC_Cart  $cart   The cart.
	 */
	public static function store_errors( $errors, $cart ) {

		foreach ( self::problems( $cart ) as $problem ) {
			$errors->add( 'nexora_cart_' . $problem['code'], $problem['message'] );
		}
	}

	/**
	 * One cart line at a time (Store API): refuse a line that cannot be bought.
	 *
	 * @param \WC_Product $product The line's product (the hidden item).
	 * @param array       $line    The cart line.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the line cannot be bought.
	 */
	public static function store_item( $product, $line ) {

		unset( $product );

		$pid = self::pid_of( $line );

		if ( $pid <= 0 ) {
			return;
		}

		$rules = Cart_Rules::check( $pid, get_current_user_id(), (int) $line['quantity'] );

		if ( ! $rules['ok'] ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'nexora_cart_' . sanitize_key( $rules['code'] ), esc_html( self::sentence( $rules ) ), 400 );
		}
	}

	/**
	 * The Store API add-item route cannot add the hidden item (only add() does).
	 *
	 * @param \WC_Product $product The product being added.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException For the hidden item.
	 */
	public static function store_add( $product ) {

		if ( $product instanceof \WC_Product && Marketplace_Item::is_item( $product->get_id() ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'nexora_cart_blocked', esc_html( Cart_Rules::unavailable() ), 400 );
		}
	}

	/**
	 * What changed in the cart since the member last looked (not cleared).
	 *
	 * @return string[]
	 */
	public static function peek_changes() {
		return function_exists( 'WC' ) && WC()->session ? array_values( (array) WC()->session->get( self::CHANGES, array() ) ) : array();
	}

	/**
	 * What changed, handed over once and cleared.
	 *
	 * @return string[]
	 */
	public static function take_changes() {

		$changes = self::peek_changes();

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::CHANGES, null );
		}

		return $changes;
	}

	/**
	 * The product post id a cart line stands for (0 for any other line).
	 *
	 * @param array $line A cart line.
	 * @return int
	 */
	public static function pid_of( $line ) {

		$pid = is_array( $line ) && isset( $line[ self::KEY ] ) && is_int( $line[ self::KEY ] ) ? $line[ self::KEY ] : 0;

		return $pid > 0 ? $pid : 0;
	}

	/**
	 * Gives a marketplace line's product object the product post's name, price and image.
	 *
	 * @param array $line A cart line.
	 * @return array The same line.
	 */
	private static function hydrate( $line ) {

		$pid = self::pid_of( $line );

		if ( $pid <= 0 || empty( $line['data'] ) || ! $line['data'] instanceof \WC_Product || \Nexora\Products\Repository::owner_of( $pid ) <= 0 ) {
			return $line;
		}

		$snap    = Cart_Rules::snapshot( $pid );
		$product = $line['data'];

		$product->set_name( $snap['name'] );
		$product->set_regular_price( $snap['price'] );
		$product->set_price( $snap['price'] );
		$product->set_image_id( $snap['image_id'] );

		return $line;
	}

	/**
	 * Which lines of a cart cannot be bought right now (fresh read, nothing changed).
	 *
	 * @param \WC_Cart|null $cart The cart.
	 * @return array[] code and message of each problem.
	 */
	private static function problems( $cart ) {

		$out = array();

		if ( ! $cart instanceof \WC_Cart ) {
			return $out;
		}

		foreach ( $cart->get_cart() as $line ) {

			$pid = self::pid_of( $line );

			if ( $pid <= 0 ) {
				continue;
			}

			$rules = Cart_Rules::check( $pid, get_current_user_id(), (int) $line['quantity'] );

			if ( ! $rules['ok'] ) {
				$out[] = array(
					'code'    => $rules['code'],
					'message' => self::sentence( $rules ),
				);
			}
		}

		return $out;
	}

	/**
	 * A plain sentence for a refused line: the product, then the reason.
	 *
	 * @param array $rules A Cart_Rules result.
	 * @return string
	 */
	private static function sentence( array $rules ) {

		$name = '' !== ( $rules['snapshot']['name'] ?? '' ) ? $rules['snapshot']['name'] : __( 'A product', 'nexora' );

		/* translators: 1: product name, 2: why it cannot be bought. */
		return sprintf( __( '"%1$s": %2$s', 'nexora' ), $name, $rules['message'] );
	}

	/**
	 * Sorts lines so each vendor's lines sit together (stable: the order within a vendor is the order they were added).
	 *
	 * @param array $lines Cart lines keyed by cart item key.
	 * @return array
	 */
	private static function group_by_vendor( array $lines ) {

		$rank = array();
		$i    = 0;

		foreach ( $lines as $key => $line ) {
			$pid          = self::pid_of( $line );
			$rank[ $key ] = array( $pid > 0 ? (int) Cart_Rules::snapshot( $pid )['vendor_id'] : 0, $i++ );
		}

		uksort(
			$lines,
			function ( $a, $b ) use ( $rank ) {
				return $rank[ $a ] <=> $rank[ $b ];
			}
		);

		return $lines;
	}

	/**
	 * The quantity of a product already in the cart.
	 *
	 * @param int $product_id Product post id.
	 * @return int
	 */
	private static function quantity_in_cart( $product_id ) {

		$total = 0;

		foreach ( WC()->cart ? WC()->cart->get_cart() : array() as $line ) {

			if ( self::pid_of( $line ) === $product_id ) {
				$total += (int) $line['quantity'];
			}
		}

		return $total;
	}

	/**
	 * Notes the price the member has just been shown (so a later change can be reported).
	 *
	 * @param int   $pid      Product post id.
	 * @param array $snapshot Snapshot from Cart_Rules::check() (name, price).
	 */
	private static function remember( $pid, array $snapshot ) {

		if ( ! WC()->session ) {
			return;
		}

		$seen         = (array) WC()->session->get( self::SEEN, array() );
		$seen[ $pid ] = array(
			'price' => $snapshot['price'],
			'name'  => $snapshot['name'],
		);

		WC()->session->set( self::SEEN, $seen );
	}

	/**
	 * Compares the price with the one last shown, notes a change, and records the current price.
	 *
	 * @param int    $pid   Product post id.
	 * @param string $name  Product name.
	 * @param string $price Current price, two decimals.
	 * @param array  $seen  The seen map (changed in place).
	 */
	private static function note_price( $pid, $name, $price, array &$seen ) {

		if ( isset( $seen[ $pid ]['price'] ) && $seen[ $pid ]['price'] !== $price ) {
			self::note(
				sprintf(
				/* translators: 1: product name, 2: old price, 3: new price. */
					__( '"%1$s" changed price from %2$s to %3$s.', 'nexora' ),
					$name,
					Presenter::money( (float) $seen[ $pid ]['price'] ),
					Presenter::money( (float) $price )
				)
			);
		}

		$seen[ $pid ] = array(
			'price' => $price,
			'name'  => $name,
		);
	}

	/**
	 * Adds a line to the "what changed" list (once).
	 *
	 * @param string $message Plain-words message.
	 */
	private static function note( $message ) {

		if ( ! WC()->session ) {
			return;
		}

		$changes = (array) WC()->session->get( self::CHANGES, array() );

		if ( ! in_array( $message, $changes, true ) ) {
			$changes[] = $message;
			WC()->session->set( self::CHANGES, $changes );
		}
	}
}
