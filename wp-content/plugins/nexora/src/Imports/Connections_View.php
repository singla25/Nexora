<?php

namespace Nexora\Imports;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What the "Import from API" popup is built from: the platform sources the administrator enabled (name and format only),
 * the vendor's own saved connections (never their keys) and whether "My own API" is available.
 */
class Connections_View {

	/**
	 * The popup's data.
	 *
	 * @param int $vendor_id The vendor's application post ID.
	 * @return array platform, connections, own, max
	 */
	public static function for_popup( $vendor_id ) {

		$platform = array();

		foreach ( Sources::enabled() as $source ) {
			$platform[] = array(
				'id'     => $source['id'],
				'name'   => $source['name'],
				'format' => $source['format'],
			);
		}

		return array(
			'platform'    => $platform,
			'connections' => Connection_Repository::for_vendor( (int) $vendor_id ),
			'own'         => Sources::vendor_apis_enabled(),
			'max'         => Connection_Repository::MAX,
		);
	}
}
