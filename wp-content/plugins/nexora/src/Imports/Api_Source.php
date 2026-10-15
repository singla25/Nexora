<?php

namespace Nexora\Imports;

use Nexora\Products\Fields;
use Nexora\Products\Repository as Products;
use Nexora\Products\Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches and maps the DummyJSON catalog (the learning stand-in for a store API). Server side only: the URL is fixed
 * (no request parameter can change it), the host must be on a short allow-list, the response is size-capped, schema
 * checked and cached for ten minutes. The source needs no credentials, so none exist to leak. Upstream error bodies and
 * the request URL are never passed on.
 */
class Api_Source {

	const URL       = 'https://dummyjson.com/products';
	const PAGE      = 100;
	const MAX_ITEMS = 500;
	const MAX_BYTES = 2 * MB_IN_BYTES;
	const CACHE     = 10 * MINUTE_IN_SECONDS;

	/**
	 * DummyJSON category => category slug of the fixed list (anything else becomes "other").
	 *
	 * @return array
	 */
	public static function category_map() {

		return array(
			'beauty'              => 'beauty',
			'fragrances'          => 'beauty',
			'skin-care'           => 'beauty',
			'furniture'           => 'home-kitchen',
			'home-decoration'     => 'home-kitchen',
			'kitchen-accessories' => 'home-kitchen',
			'groceries'           => 'groceries',
			'laptops'             => 'electronics',
			'smartphones'         => 'electronics',
			'tablets'             => 'electronics',
			'mobile-accessories'  => 'electronics',
			'mens-shirts'         => 'fashion',
			'mens-shoes'          => 'fashion',
			'mens-watches'        => 'fashion',
			'womens-bags'         => 'fashion',
			'womens-dresses'      => 'fashion',
			'womens-jewellery'    => 'fashion',
			'womens-shoes'        => 'fashion',
			'womens-watches'      => 'fashion',
			'tops'                => 'fashion',
			'sunglasses'          => 'fashion',
			'sports-accessories'  => 'sports',
		);
	}

	/**
	 * Fetches up to 500 products.
	 *
	 * @return array items [ external_id, raw, image_url ], errors [ ref, message ], fetch_error (string, '' when fine)
	 */
	public static function fetch() {

		$out  = array(
			'items'       => array(),
			'errors'      => array(),
			'fetch_error' => '',
		);
		$base = self::base_url();

		if ( '' === $base ) {
			$out['fetch_error'] = __( 'The product source is not available.', 'nexora' );
			return $out;
		}

		$skip = 0;
		$seen = 0;

		while ( $seen < self::MAX_ITEMS ) {

			$page = self::page(
				add_query_arg(
					array(
						'limit' => self::PAGE,
						'skip'  => $skip,
					),
					$base
				)
			);

			if ( '' !== $page['error'] ) {
				$out['fetch_error'] = $page['error'];
				break;
			}

			foreach ( $page['products'] as $product ) {

				$mapped = self::map( $product );

				++$seen;

				if ( isset( $mapped['error'] ) ) {
					$out['errors'][] = $mapped['error'];
				} else {
					$out['items'][] = $mapped;
				}
			}

			$skip += self::PAGE;

			if ( ! $page['products'] || $skip >= $page['total'] ) {
				break;
			}
		}

		$out['items'] = array_slice( $out['items'], 0, self::MAX_ITEMS );

		return $out;
	}

	/**
	 * The fixed source URL, or '' when it is not an allowed https host.
	 *
	 * @return string
	 */
	private static function base_url() {

		/**
		 * The product source URL (tests point it elsewhere; the host must still be allowed).
		 *
		 * @param string $url URL.
		 */
		$url   = (string) apply_filters( 'nexora_import_api_url', self::URL );
		$parts = wp_parse_url( $url );
		/**
		 * Hosts the import may call.
		 *
		 * @param string[] $hosts Allowed hosts.
		 */
		$hosts = (array) apply_filters( 'nexora_import_api_hosts', array( 'dummyjson.com' ) );

		if ( ! $parts || empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) || ! in_array( strtolower( $parts['host'] ), $hosts, true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}

		return $url;
	}

	/**
	 * One page, from the cache or the source.
	 *
	 * @param string $url Page URL.
	 * @return array products, total, error ('' when fine).
	 */
	private static function page( $url ) {

		$key    = 'nexora_api_' . md5( $url );
		$cached = get_transient( $key );

		if ( is_array( $cached ) && isset( $cached['products'], $cached['total'] ) ) {
			return array(
				'products' => $cached['products'],
				'total'    => $cached['total'],
				'error'    => '',
			);
		}

		$fail = function ( $message ) {
			return array(
				'products' => array(),
				'total'    => 0,
				'error'    => $message,
			);
		};

		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 2,
				'reject_unsafe_urls'  => true,
				'limit_response_size' => self::MAX_BYTES,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $fail( __( 'Could not reach the product source. Please try again later.', 'nexora' ) );
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return $fail( __( 'The product source answered with an error. Please try again later.', 'nexora' ) );
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( '' === $body || strlen( $body ) >= self::MAX_BYTES ) {
			return $fail( __( 'The product source sent an unusable answer.', 'nexora' ) );
		}

		$json = json_decode( $body, true );

		if ( ! is_array( $json ) || ! isset( $json['products'] ) || ! is_array( $json['products'] ) || ! array_is_list( $json['products'] ) ) {
			return $fail( __( 'The product source sent an unexpected answer.', 'nexora' ) );
		}

		$page = array(
			'products' => $json['products'],
			'total'    => isset( $json['total'] ) && is_numeric( $json['total'] ) ? (int) $json['total'] : count( $json['products'] ),
		);

		set_transient( $key, $page, self::CACHE );

		return $page + array( 'error' => '' );
	}

	/**
	 * Maps one DummyJSON product to the raw product fields.
	 *
	 * @param mixed $product One entry of the list.
	 * @return array external_id, raw, image_url - or error [ ref, message ].
	 */
	private static function map( $product ) {

		if ( ! is_array( $product ) ) {
			return array(
				'error' => array(
					'ref'     => '?',
					'message' => __( 'Not a product.', 'nexora' ),
				),
			);
		}

		$id = isset( $product['id'] ) && ( is_int( $product['id'] ) || ( is_string( $product['id'] ) && ctype_digit( $product['id'] ) ) ) ? (string) $product['id'] : '';

		if ( '' === $id || strlen( $id ) > 12 ) {
			return array(
				'error' => array(
					'ref'     => '?',
					'message' => __( 'A product without a usable id.', 'nexora' ),
				),
			);
		}

		$text = function ( $key, $max ) use ( $product ) {
			return isset( $product[ $key ] ) && is_string( $product[ $key ] ) ? mb_substr( $product[ $key ], 0, $max ) : '';
		};
		$num  = function ( $key ) use ( $product ) {
			return isset( $product[ $key ] ) && ( is_int( $product[ $key ] ) || is_float( $product[ $key ] ) || is_string( $product[ $key ] ) ) ? (string) $product[ $key ] : '';
		};

		$sku   = $text( 'sku', 40 );
		$map   = self::category_map();
		$cat   = isset( $product['category'] ) && is_string( $product['category'] ) && isset( $map[ $product['category'] ] ) ? $map[ $product['category'] ] : 'other';
		$cat   = isset( Fields::categories()[ $cat ] ) ? $cat : 'other';
		$thumb = $text( 'thumbnail', 300 );

		return array(
			'external_id' => $id,
			'raw'         => array(
				'name'        => $text( 'title', Validator::NAME_MAX ),
				'description' => $text( 'description', Validator::DESCRIPTION_MAX ),
				'price'       => $num( 'price' ),
				'stock'       => $num( 'stock' ),
				'sku'         => '' !== $sku ? $sku : 'DJ-' . $id,
				'category'    => $cat,
				'image_id'    => '0',
			),
			'image_url'   => Products::external_image_ok( $thumb ) ? $thumb : '',
		);
	}
}
