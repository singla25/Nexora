<?php

namespace Nexora\Admin_Edit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ACF field groups, registered in code (nothing is stored in the database): one per record type, built from the Fields registry.
 * Field names are the stored meta keys. Read-only fields are shown and cannot be typed into.
 */
class Groups {

	/**
	 * The ACF group key of a record type.
	 *
	 * @param string $type Record type.
	 * @return string
	 */
	public static function group_key( $type ) {
		return 'group_nx_' . $type;
	}

	/**
	 * Registers the groups with ACF (does nothing when ACF is not there).
	 */
	public static function register() {

		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		foreach ( Fields::types() as $type ) {
			acf_add_local_field_group( self::group( $type ) );
		}
	}

	/**
	 * The ACF definition of one record type's group.
	 *
	 * @param string $type Record type.
	 * @return array
	 */
	public static function group( $type ) {

		$fields = array();

		foreach ( Fields::definitions( $type ) as $name => $def ) {
			$fields[] = self::field( $type, $name, $def );
		}

		return array(
			'key'             => self::group_key( $type ),
			'title'           => __( 'Administrator edit', 'nexora' ),
			'fields'          => $fields,
			'location'        => array(
				array(
					array(
						'param'    => 'post_type',
						'operator' => '==',
						'value'    => $type,
					),
				),
			),
			'menu_order'      => 0,
			'position'        => 'normal',
			'style'           => 'default',
			'label_placement' => 'left',
			'active'          => true,
		);
	}

	/**
	 * One ACF field.
	 *
	 * @param string $type Record type.
	 * @param string $name Field name (the meta key).
	 * @param array  $def  Definition from Fields.
	 * @return array
	 */
	private static function field( $type, $name, array $def ) {

		$field = array(
			'key'   => Fields::field_key( $type, $name ),
			'label' => $def['label'],
			'name'  => $name,
			'type'  => $def['acf'],
		);

		switch ( $def['acf'] ) {

			case 'select':
				$field['choices']       = $def['choices'];
				$field['allow_null']    = 0;
				$field['multiple']      = 0;
				$field['ui']            = 0;
				$field['return_format'] = 'value';
				break;

			case 'image':
				$field['return_format'] = 'id';
				$field['preview_size']  = 'thumbnail';
				$field['library']       = 'all';
				break;

			case 'number':
				if ( 'price' === $name ) {
					$field['step'] = '0.01';
					$field['min']  = '0.01';
				} elseif ( 'stock' === $name ) {
					$field['step'] = '1';
					$field['min']  = '0';
				}
				break;

			case 'textarea':
				$field['rows']      = 4;
				$field['new_lines'] = '';
				break;
		}

		if ( $def['readonly'] ) {
			$field['readonly'] = 1;
			$field['disabled'] = 1;
		}

		return $field;
	}
}
