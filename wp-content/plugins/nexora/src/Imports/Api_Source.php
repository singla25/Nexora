<?php

namespace Nexora\Imports;

use Nexora\Core\Secrets;
use Nexora\Products\Fields;
use Nexora\Products\Repository as Products;
use Nexora\Products\Validator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fetches and maps a product API: the published "Nexora format", the DummyJSON adapter or the Fake Store adapter. Every request goes through
 * Url_Guard (https, public addresses only, checked after DNS, pinned to the checked address), follows no redirect, has
 * a 10 second timeout and a 2 MB response cap, reads at most 10 pages and 500 items, and reports only generic errors:
 * an upstream body, the address or the key never reach the caller.
 *
 * A source is an array: key, name, url, format, auth_type, auth_header, secret (PLAIN text, only here in memory),
 * kind (platform | vendor), host_allowlist, and for the Fake Store format stock_default and create_categories.
 */
class Api_Source {

	const PAGE      = 100;
	const MAX_ITEMS = 500;
	const MAX_PAGES = 10;
	const MAX_BYTES = 2 * MB_IN_BYTES;
	const CACHE     = 10 * MINUTE_IN_SECONDS;

	// Fake Store adapter: categories it may ask to create in one import, the size at which the list is "full", and the longest label.
	const NEW_CATEGORIES_MAX = 10;
	const CATEGORIES_MAX     = 50;
	const CATEGORY_LABEL_MAX = 40;

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
	 * A platform source from the Settings list, ready to fetch (the key decrypted, in memory only).
	 *
	 * @param array $row A row of Sources::all().
	 * @return array
	 */
	public static function from_platform( array $row ) {

		return array(
			'key'               => 'p-' . $row['id'],
			'name'              => $row['name'],
			'url'               => $row['url'],
			'format'            => $row['format'],
			'auth_type'         => $row['auth_type'],
			'auth_header'       => $row['auth_header'],
			'secret'            => Secrets::decrypt( $row['secret'] ),
			'kind'              => 'platform',
			'host_allowlist'    => array(),
			'stock_default'     => Sources::stock_default_of( $row['stock_default'] ?? Sources::STOCK_DEFAULT ),
			'create_categories' => ! isset( $row['create_categories'] ) || ! empty( $row['create_categories'] ),
		);
	}

	/**
	 * Fetches up to 500 products.
	 *
	 * @param array|null $source A source (see the class comment); null = the first enabled platform source.
	 * @param array      $limits Optional smaller limits: pages, items (the "Test my API" button reads one page) and valid (the
	 *                           import's "How many products": stop as soon as this many VALID items are collected; invalid
	 *                           ones are still returned, to be reported, but are not counted).
	 * @return array items [ external_id, raw, image_url ], errors [ ref, message ], fetch_error ('' when fine), label,
	 *               feed [ taken, seen, total (null when unknown), more ]
	 */
	public static function fetch( $source = null, array $limits = array() ) {

		$max_pages = isset( $limits['pages'] ) ? max( 1, min( self::MAX_PAGES, (int) $limits['pages'] ) ) : self::MAX_PAGES;
		$max_items = isset( $limits['items'] ) ? max( 1, min( self::MAX_ITEMS, (int) $limits['items'] ) ) : self::MAX_ITEMS;
		$want      = isset( $limits['valid'] ) ? max( 1, min( self::MAX_ITEMS, (int) $limits['valid'] ) ) : self::MAX_ITEMS;

		if ( null === $source ) {

			$enabled = Sources::enabled();
			$source  = $enabled ? self::from_platform( $enabled[0] ) : null;
		}

		$out = array(
			'items'       => array(),
			'errors'      => array(),
			'fetch_error' => '',
			'label'       => $source ? (string) $source['name'] : '',
			'feed'        => array(
				'taken' => 0,
				'seen'  => 0,
				'total' => null,
				'more'  => false,
			),
		);

		if ( ! $source ) {
			$out['fetch_error'] = __( 'No product source is available.', 'nexora' );
			return $out;
		}

		// A vendor's own API is always the Nexora format; the adapters (and creating categories) are the administrator's.
		if ( 'vendor' === $source['kind'] && 'nexora' !== $source['format'] ) {
			$out['fetch_error'] = __( 'This kind of product source is not available.', 'nexora' );
			return $out;
		}

		$opts  = 'vendor' === $source['kind'] ? array( 'host_allowlist' => (array) $source['host_allowlist'] ) : array();
		$first = Url_Guard::check( $source['url'], $opts );

		if ( is_wp_error( $first ) ) {
			$out['fetch_error'] = Url_Guard::MESSAGE;
			return $out;
		}

		$host    = $first['host'];
		$url     = 'dummyjson' === $source['format'] ? add_query_arg(
			array(
				'limit' => self::PAGE,
				'skip'  => 0,
			),
			$first['url']
		) : $first['url'];
		$state   = array(
			'known' => Fields::categories(),
			'new'   => array(),
		);
		$seen    = array();
		$pages   = 0;
		$skip    = 0;
		$counted = 0;
		$valid   = 0;
		$entries = 0;
		$more    = false;
		$stopped = false;
		$total   = null;

		while ( '' !== $url && $counted < $max_items ) {

			if ( isset( $seen[ $url ] ) ) {
				$out['fetch_error'] = __( 'The product source has too many pages, or its pages repeat.', 'nexora' );
				break;
			}

			if ( $pages >= $max_pages ) {
				if ( $max_pages >= self::MAX_PAGES ) {
					$out['fetch_error'] = __( 'The product source has too many pages, or its pages repeat.', 'nexora' );
				}
				break;
			}

			$seen[ $url ] = true;
			++$pages;

			$page = self::page( $url, $source, $host, 'platform' === $source['kind'] );

			if ( '' !== $page['error'] ) {
				$out['fetch_error'] = $page['error'];
				break;
			}

			$entries += count( $page['products'] );

			if ( 'dummyjson' === $source['format'] || ! empty( $page['total_known'] ) ) {
				$total = (int) $page['total'];
			}

			foreach ( $page['products'] as $product ) {

				// Enough valid items: the rest of the page is left alone.
				if ( $valid >= $want ) {
					$more = true;
					break;
				}

				if ( 'dummyjson' === $source['format'] ) {
					$mapped = self::map_dummyjson( $product, $host );
				} elseif ( 'fakestore' === $source['format'] ) {
					$mapped = self::map_fakestore( $product, $source, $host, $state );
				} else {
					$mapped = self::map_nexora( $product, $source['key'], $host );
				}

				++$counted;

				if ( isset( $mapped['error'] ) ) {
					/* translators: 1: position of the entry in the answer, 2: what is wrong. */
					$mapped['error']['message'] = sprintf( __( 'Entry %1$d: %2$s', 'nexora' ), $counted, $mapped['error']['message'] );
					$out['errors'][]            = $mapped['error'];
				} else {
					$out['items'][] = $mapped;

					if ( ! Validator::validate( $mapped['raw'] )['errors'] ) {
						++$valid;
					}
				}
			}

			// Enough valid items: no later page is requested. Whether the feed has more is read from this page alone.
			if ( $valid >= $want ) {

				$stopped = true;
				$more    = $more || ( 'dummyjson' === $source['format'] ? ( $page['products'] && $skip + self::PAGE < $page['total'] ) : null !== $page['next'] );
				$url     = '';
				break;
			}

			$url = '';

			if ( 'dummyjson' === $source['format'] ) {
				$skip += self::PAGE;

				if ( $page['products'] && $skip < $page['total'] ) {
					$url = add_query_arg(
						array(
							'limit' => self::PAGE,
							'skip'  => $skip,
						),
						$first['url']
					);
				}
			} elseif ( null !== $page['next'] ) {

				$next = Url_Guard::check( $page['next'], array( 'same_host' => $host ) );

				if ( is_wp_error( $next ) ) {
					$out['fetch_error'] = Url_Guard::MESSAGE;
				} else {
					$url = $next['url'];
				}
			}
		}

		$out['items'] = array_slice( $out['items'], 0, $max_items );

		// The loop left a next page unread because of the 500 entry limit: the feed has more.
		if ( ! $stopped && '' !== $url && '' === $out['fetch_error'] ) {
			$more = true;
		}

		$out['feed'] = array(
			'taken' => $valid,
			'seen'  => $entries,
			'total' => $more ? $total : $entries,
			'more'  => $more,
		);

		return $out;
	}

	/**
	 * One page: from the cache (platform sources only) or from the source.
	 *
	 * @param string $url      Page URL (already checked).
	 * @param array  $source   The source.
	 * @param string $host     The source's host.
	 * @param bool   $cacheable Whether the page may be cached.
	 * @return array products, total, next, error ('' when fine)
	 */
	private static function page( $url, array $source, $host, $cacheable ) {

		$key    = 'nexora_api_' . md5( $source['key'] . '|' . $url );
		$cached = $cacheable ? get_transient( $key ) : false;

		if ( is_array( $cached ) && isset( $cached['products'] ) ) {
			return $cached + array( 'error' => '' );
		}

		$fail = function ( $message ) {
			return array(
				'products' => array(),
				'total'    => 0,
				'next'     => null,
				'error'    => $message,
			);
		};

		// A second, checked lookup is the one the request is pinned to.
		$checked = Url_Guard::check( $url, array( 'same_host' => $host ) );

		if ( is_wp_error( $checked ) ) {
			return $fail( Url_Guard::MESSAGE );
		}

		$headers = array( 'Accept' => 'application/json' );

		if ( '' !== (string) $source['secret'] ) {

			if ( 'bearer' === $source['auth_type'] ) {
				$headers['Authorization'] = 'Bearer ' . $source['secret'];
			} elseif ( 'header' === $source['auth_type'] && '' !== (string) $source['auth_header'] ) {
				$headers[ $source['auth_header'] ] = $source['secret'];
			}
		}

		// Pinning the request to the address that was just checked needs cURL options; WordPress has no API for it.
		// phpcs:disable WordPress.WP.AlternativeFunctions
		$pin  = $checked['ips'] ? Url_Guard::curl_pin( $host, $checked['ips'] ) : array();
		$hook = function ( $handle ) use ( $pin ) {
			curl_setopt( $handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS );
			curl_setopt( $handle, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS );

			if ( $pin ) {
				curl_setopt( $handle, CURLOPT_RESOLVE, $pin );
			}
		};
		// phpcs:enable WordPress.WP.AlternativeFunctions

		add_action( 'http_api_curl', $hook );

		try {
			$response = wp_remote_get(
				$checked['url'],
				array(
					'timeout'             => 10,
					'redirection'         => 0,
					'reject_unsafe_urls'  => true,
					'limit_response_size' => self::MAX_BYTES,
					'headers'             => $headers,
				)
			);
		} finally {
			remove_action( 'http_api_curl', $hook );
		}

		if ( is_wp_error( $response ) ) {
			return $fail( __( 'Could not reach the product source. Please try again later.', 'nexora' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 300 && $code < 400 ) {
			return $fail( __( 'The product source sent us somewhere else. Redirects are not followed: use its final address.', 'nexora' ) );
		}

		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			return $fail( sprintf( __( 'The product source answered with an error (HTTP %d).', 'nexora' ), $code ) );
		}

		$body = (string) wp_remote_retrieve_body( $response );

		if ( '' === $body || strlen( $body ) >= self::MAX_BYTES ) {
			return $fail( __( 'The product source sent an unusable answer (empty, or larger than 2 MB).', 'nexora' ) );
		}

		$json = json_decode( $body, true );

		// The Fake Store answer is a bare list: no wrapper, no total, no next page.
		if ( 'fakestore' === $source['format'] ) {

			if ( ! is_array( $json ) || ! array_is_list( $json ) ) {
				return $fail( __( 'The product source did not answer in the expected format: a JSON list of products.', 'nexora' ) );
			}

			$page = array(
				'products' => $json,
				'total'    => count( $json ),
				'next'     => null,
			);

			if ( $cacheable ) {
				set_transient( $key, $page, self::CACHE );
			}

			return $page + array( 'error' => '' );
		}

		if ( ! is_array( $json ) || array_is_list( $json ) || ! isset( $json['products'] ) || ! is_array( $json['products'] ) || ! array_is_list( $json['products'] ) ) {
			return $fail( __( 'The product source did not answer in the expected format: a JSON object with a "products" list.', 'nexora' ) );
		}

		$page = array(
			'products'    => $json['products'],
			'total'       => isset( $json['total'] ) && is_numeric( $json['total'] ) ? (int) $json['total'] : count( $json['products'] ),
			'total_known' => isset( $json['total'] ) && is_numeric( $json['total'] ),
			'next'        => array_key_exists( 'next', $json ) && null !== $json['next'] && '' !== $json['next'] ? $json['next'] : null,
		);

		if ( $cacheable ) {
			set_transient( $key, $page, self::CACHE );
		}

		return $page + array( 'error' => '' );
	}

	/**
	 * Maps one entry of the published Nexora format. Required: id, name, price, stock, category. Optional: sku
	 * (defaults to the id), description, image. The fields are passed on as given: the shared product validator
	 * judges them and says what is wrong in plain words.
	 *
	 * @param mixed  $product One entry of the list.
	 * @param string $key     The source key (prefixes the external id).
	 * @param string $host    The source host (an image must be on it).
	 * @return array external_id, raw, image_url - or error [ ref, message ].
	 */
	private static function map_nexora( $product, $key, $host ) {

		if ( ! is_array( $product ) || array_is_list( $product ) ) {
			return self::item_error( '?', __( 'This entry is not an object.', 'nexora' ) );
		}

		$id = self::id_of( $product );

		if ( '' === $id ) {
			return self::item_error( '?', __( 'This product has no usable "id" (1-64 letters, numbers, dot, dash, colon or underscore).', 'nexora' ) );
		}

		$sku   = isset( $product['sku'] ) && is_string( $product['sku'] ) && '' !== trim( $product['sku'] ) ? $product['sku'] : $id;
		$image = isset( $product['image'] ) && is_string( $product['image'] ) ? $product['image'] : '';
		$ok    = '' !== $image && Products::external_image_ok( $image, $host );

		return array(
			'external_id' => $key . ':' . $id,
			'ref'         => $id,
			'raw'         => array(
				'name'        => isset( $product['name'] ) && is_string( $product['name'] ) ? $product['name'] : '',
				'description' => isset( $product['description'] ) && is_string( $product['description'] ) ? $product['description'] : '',
				'price'       => self::num( $product, 'price' ),
				'stock'       => self::num( $product, 'stock' ),
				'sku'         => $sku,
				'category'    => self::category( $product['category'] ?? '' ),
				'image_id'    => '0',
			),
			'image_url'   => $ok ? $image : '',
			'notes'       => '' !== $image && ! $ok ? array( __( 'the image was ignored: use an https address on your API\'s own domain', 'nexora' ) ) : array(),
		);
	}

	/**
	 * Maps one DummyJSON product (the adapter): title, description, category (mapped to the fixed list), price, stock,
	 * sku (DJ-{id} when missing), thumbnail.
	 *
	 * @param mixed  $product One entry of the list.
	 * @param string $host    The source host.
	 * @return array external_id, raw, image_url - or error [ ref, message ].
	 */
	private static function map_dummyjson( $product, $host ) {

		if ( ! is_array( $product ) ) {
			return self::item_error( '?', __( 'Not a product.', 'nexora' ) );
		}

		$id = isset( $product['id'] ) && ( is_int( $product['id'] ) || ( is_string( $product['id'] ) && ctype_digit( $product['id'] ) ) ) ? (string) $product['id'] : '';

		if ( '' === $id || strlen( $id ) > 12 ) {
			return self::item_error( '?', __( 'A product without a usable id.', 'nexora' ) );
		}

		$text  = function ( $field, $max ) use ( $product ) {
			return isset( $product[ $field ] ) && is_string( $product[ $field ] ) ? mb_substr( $product[ $field ], 0, $max ) : '';
		};
		$sku   = $text( 'sku', 40 );
		$map   = self::category_map();
		$cat   = isset( $product['category'] ) && is_string( $product['category'] ) && isset( $map[ $product['category'] ] ) ? $map[ $product['category'] ] : 'other';
		$thumb = $text( 'thumbnail', 300 );

		return array(
			'external_id' => $id,
			'ref'         => $id,
			'raw'         => array(
				'name'        => $text( 'title', Validator::NAME_MAX ),
				'description' => $text( 'description', Validator::DESCRIPTION_MAX ),
				'price'       => self::num( $product, 'price' ),
				'stock'       => self::num( $product, 'stock' ),
				'sku'         => '' !== $sku ? $sku : 'DJ-' . $id,
				'category'    => isset( Fields::categories()[ $cat ] ) ? $cat : 'other',
				'image_id'    => '0',
			),
			'image_url'   => Products::external_image_ok( $thumb, $host ) ? $thumb : '',
		);
	}

	/**
	 * Maps one Fake Store product (the adapter): id, title (the name), price, description, category and image pass through;
	 * the rating is ignored. The API has no stock, so the source's "stock for items without stock" is used (never a number from
	 * the API); there is no sku either, so FS-{id}. A category that is not in the list is created later (only when the source
	 * says so, and only when the import is applied) or fails the item with a plain message. Everything else is judged by the
	 * shared product validator, like every other format.
	 *
	 * @param mixed  $product One entry of the list.
	 * @param array  $source  The source.
	 * @param string $host    The source host (the image must be on it).
	 * @param array  $state   Categories known and categories asked for so far in this fetch (by reference).
	 * @return array external_id, raw, image_url (and new_category) - or error [ ref, message ].
	 */
	private static function map_fakestore( $product, array $source, $host, array &$state ) {

		if ( ! is_array( $product ) || array_is_list( $product ) ) {
			return self::item_error( '?', __( 'This entry is not an object.', 'nexora' ) );
		}

		$id = self::id_of( $product );

		if ( '' === $id ) {
			return self::item_error( '?', __( 'This product has no usable "id".', 'nexora' ) );
		}

		$text  = function ( $field, $max ) use ( $product ) {
			$value = $product[ $field ] ?? null;
			$value = is_int( $value ) || is_float( $value ) ? (string) $value : $value;
			return is_string( $value ) ? mb_substr( trim( $value ), 0, $max ) : '';
		};
		$image = $text( 'image', 300 );
		$cat   = self::fakestore_category( $product['category'] ?? null, $id, ! empty( $source['create_categories'] ), $state );

		if ( isset( $cat['error'] ) ) {
			return $cat;
		}

		$item = array(
			'external_id' => $source['key'] . ':' . $id,
			'ref'         => $id,
			'raw'         => array(
				'name'        => $text( 'title', 500 ),
				'description' => $text( 'description', Validator::DESCRIPTION_MAX ),
				'price'       => self::num( $product, 'price' ),
				'stock'       => (string) Sources::stock_default_of( $source['stock_default'] ?? Sources::STOCK_DEFAULT ),
				'sku'         => 'FS-' . $id,
				'category'    => $cat['slug'],
				'image_id'    => '0',
			),
			'image_url'   => Products::external_image_ok( $image, $host ) ? $image : '',
		);

		if ( $cat['new'] ) {
			$item['new_category'] = array(
				'slug'  => $cat['slug'],
				'label' => $cat['label'],
			);
		}

		return $item;
	}

	/**
	 * The category of a Fake Store item: an existing one (by slug or label), a new one to create, or an error.
	 *
	 * @param mixed  $value  The category field.
	 * @param string $ref    Item reference.
	 * @param bool   $create Whether this source may ask for missing categories.
	 * @param array  $state  Categories known and asked for so far in this fetch (by reference).
	 * @return array slug, label, new - or error [ ref, message ].
	 */
	private static function fakestore_category( $value, $ref, $create, array &$state ) {

		$label = is_string( $value ) ? mb_substr( sanitize_text_field( $value ), 0, 60 ) : '';

		if ( '' === $label ) {
			return self::item_error( $ref, __( 'This product has no category.', 'nexora' ) );
		}

		foreach ( $state['known'] + $state['new'] as $slug => $known_label ) {

			if ( 0 === strcasecmp( $label, $slug ) || 0 === strcasecmp( $label, $known_label ) || sanitize_title( $label ) === $slug ) {
				return array(
					'slug'  => $slug,
					'label' => $known_label,
					'new'   => isset( $state['new'][ $slug ] ),
				);
			}
		}

		if ( ! $create ) {
			/* translators: %s: the category name the source gave. */
			return self::item_error( $ref, sprintf( __( 'The category "%s" is not in the category list. Add it in Settings, or let this source create missing categories.', 'nexora' ), $label ) );
		}

		$new_label = trim( mb_substr( mb_convert_case( $label, MB_CASE_TITLE, 'UTF-8' ), 0, self::CATEGORY_LABEL_MAX ) );
		$new_slug  = sanitize_title( $new_label );

		if ( '' === $new_slug ) {
			/* translators: %s: the category name the source gave. */
			return self::item_error( $ref, sprintf( __( 'The category "%s" cannot be used as a category name.', 'nexora' ), $label ) );
		}

		if ( isset( $state['known'][ $new_slug ] ) || isset( $state['new'][ $new_slug ] ) ) {
			return array(
				'slug'  => $new_slug,
				'label' => $state['known'][ $new_slug ] ?? $state['new'][ $new_slug ],
				'new'   => isset( $state['new'][ $new_slug ] ),
			);
		}

		if ( count( $state['new'] ) >= self::NEW_CATEGORIES_MAX || count( $state['known'] ) + count( $state['new'] ) >= self::CATEGORIES_MAX ) {
			/* translators: %s: the category name the source gave. */
			return self::item_error( $ref, sprintf( __( 'The category "%s" was not created: too many new categories in one import, or the category list is full.', 'nexora' ), $label ) );
		}

		$state['new'][ $new_slug ] = $new_label;

		return array(
			'slug'  => $new_slug,
			'label' => $new_label,
			'new'   => true,
		);
	}

	/**
	 * The item id: a positive integer or a short string of letters, numbers and . _ : -.
	 *
	 * @param array $product The entry.
	 * @return string '' when unusable.
	 */
	private static function id_of( array $product ) {

		$id = $product['id'] ?? null;

		if ( is_int( $id ) && $id > 0 ) {
			return (string) $id;
		}

		return is_string( $id ) && preg_match( '/^[A-Za-z0-9._:-]{1,64}$/', $id ) ? $id : '';
	}

	/**
	 * A number or numeric text as text ('' for anything else, so the validator refuses it).
	 *
	 * @param array  $product The entry.
	 * @param string $field   Field name.
	 * @return string
	 */
	private static function num( array $product, $field ) {

		$value = $product[ $field ] ?? null;

		return ( is_int( $value ) || is_float( $value ) || is_string( $value ) ) ? (string) $value : '';
	}

	/**
	 * A category given as slug or label becomes the slug; anything else is passed on to be refused.
	 *
	 * @param mixed $value Category field.
	 * @return string
	 */
	private static function category( $value ) {

		$value = is_string( $value ) ? trim( $value ) : '';

		foreach ( Fields::categories() as $slug => $label ) {

			if ( 0 === strcasecmp( $value, $slug ) || 0 === strcasecmp( $value, $label ) ) {
				return $slug;
			}
		}

		return $value;
	}

	/**
	 * An item-level error.
	 *
	 * @param string $ref     Item reference.
	 * @param string $message What is wrong.
	 * @return array
	 */
	private static function item_error( $ref, $message ) {

		return array(
			'error' => array(
				'ref'     => $ref,
				'message' => $message,
			),
		);
	}
}
