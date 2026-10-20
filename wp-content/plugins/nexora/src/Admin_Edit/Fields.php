<?php

namespace Nexora\Admin_Edit;

use Nexora\Core\Post_Types;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What an administrator sees and may change on each record type: ONE registry that the ACF field groups, the validation and the saver
 * all read, so they cannot disagree. A field is `edit` (an administrator may change it, after the same validation as everyone) or
 * `readonly` (shown, never written: identity, the owner, the links between records, where a product came from).
 *
 * Field names are the stored meta keys, so nothing about how the data is kept changes. A few names are not meta keys but are read and
 * written through their own owner (a product's name / description / status live on the post; a seller's status lives on the account).
 */
class Fields {

	/**
	 * The record types with an administrator's edit form.
	 *
	 * @return string[]
	 */
	public static function types() {
		return array( Post_Types::CUSTOMER, Post_Types::SELLER, Post_Types::CUST_SELLER, 'nexora_product', 'user_connections', 'user_content' );
	}

	/**
	 * Field names of a record type.
	 *
	 * @param string $type Record type.
	 * @param string $kind edit | readonly | all.
	 * @return string[]
	 */
	public static function names( $type, $kind = 'all' ) {

		$out = array();

		foreach ( self::definitions( $type ) as $name => $def ) {
			if ( 'all' === $kind || ( 'edit' === $kind && ! $def['readonly'] ) || ( 'readonly' === $kind && $def['readonly'] ) ) {
				$out[] = $name;
			}
		}

		return $out;
	}

	/**
	 * Every field of a record type: name => label, acf (field type), readonly, and for choices the choices.
	 *
	 * @param string $type Record type.
	 * @return array[]
	 */
	public static function definitions( $type ) {

		$defs = array();

		switch ( $type ) {

			case Post_Types::CUSTOMER:
				$defs = self::personal() + self::address() + self::work() + self::images() + self::identity( true ) + self::documents();
				// Order: editable first, then what is read-only.
				break;

			case Post_Types::SELLER:
				$defs = self::personal( false ) + self::business() + self::status() + self::identity( false );
				break;

			case Post_Types::CUST_SELLER:
				$defs = self::business() + self::status() + array(
					'_wp_user_id'      => self::def( __( 'Owner (user ID)', 'nexora' ), 'number', true ),
					'customer_post_id' => self::def( __( 'Customer profile (post ID)', 'nexora' ), 'number', true ),
				);
				break;

			case 'nexora_product':
				$defs = array(
					'name'        => self::def( __( 'Name', 'nexora' ), 'text' ),
					'description' => self::def( __( 'Description', 'nexora' ), 'textarea' ),
					'price'       => self::def( __( 'Price', 'nexora' ), 'number' ),
					'stock'       => self::def( __( 'Stock', 'nexora' ), 'number' ),
					'sku'         => self::def( __( 'SKU', 'nexora' ), 'text' ),
					'category'    => self::def( __( 'Category', 'nexora' ), 'select', false, \Nexora\Products\Fields::categories() ),
					'image_id'    => self::def( __( 'Image', 'nexora' ), 'image' ),
					'status'      => self::def(
						__( 'Status', 'nexora' ),
						'select',
						false,
						array(
							'publish' => __( 'Published', 'nexora' ),
							'draft'   => __( 'Draft (not listed)', 'nexora' ),
						)
					),
					'vendor_id'   => self::def( __( 'Seller record (post ID)', 'nexora' ), 'number', true ),
					'source'      => self::def( __( 'Source', 'nexora' ), 'text', true ),
					'external_id' => self::def( __( 'External ID', 'nexora' ), 'text', true ),
					'image_url'   => self::def( __( 'External image URL', 'nexora' ), 'text', true ),
				);
				break;

			case 'user_connections':
				$defs = array(
					'status'              => self::def(
						__( 'Status', 'nexora' ),
						'select',
						false,
						array(
							'pending'  => __( 'Pending', 'nexora' ),
							'accepted' => __( 'Accepted', 'nexora' ),
							'rejected' => __( 'Rejected', 'nexora' ),
							'removed'  => __( 'Removed', 'nexora' ),
						)
					),
					'sender_user_id'      => self::def( __( 'Sender user ID', 'nexora' ), 'number', true ),
					'sender_profile_id'   => self::def( __( 'Sender profile ID', 'nexora' ), 'number', true ),
					'sender_user_name'    => self::def( __( 'Sender user name', 'nexora' ), 'text', true ),
					'receiver_user_id'    => self::def( __( 'Receiver user ID', 'nexora' ), 'number', true ),
					'receiver_profile_id' => self::def( __( 'Receiver profile ID', 'nexora' ), 'number', true ),
					'receiver_user_name'  => self::def( __( 'Receiver user name', 'nexora' ), 'text', true ),
				);
				break;

			case 'user_content':
				$defs = array(
					'title'           => self::def( __( 'Title', 'nexora' ), 'text' ),
					'description'     => self::def( __( 'Text', 'nexora' ), 'textarea' ),
					'user_id'         => self::def( __( 'Author user ID', 'nexora' ), 'number', true ),
					'user_profile_id' => self::def( __( 'Author profile ID', 'nexora' ), 'number', true ),
					'user_name'       => self::def( __( 'Author user name', 'nexora' ), 'text', true ),
				);
				break;
		}

		// Editable fields come first, in the order they were declared, then the read-only ones.
		$edit = array_filter(
			$defs,
			function ( $d ) {
				return ! $d['readonly'];
			}
		);
		$ro   = array_filter(
			$defs,
			function ( $d ) {
				return $d['readonly'];
			}
		);

		return $edit + $ro;
	}

	/**
	 * The ACF field key of a field: derived, so it is the same on every request and every site.
	 *
	 * @param string $type Record type.
	 * @param string $name Field name.
	 * @return string
	 */
	public static function field_key( $type, $name ) {
		return 'field_nx_' . $type . '_' . ltrim( $name, '_' );
	}

	/**
	 * The field name behind an ACF field key of a record type ('' when it is not one of ours).
	 *
	 * @param string $type Record type.
	 * @param string $key  ACF field key.
	 * @return string
	 */
	public static function name_for_key( $type, $key ) {

		foreach ( array_keys( self::definitions( $type ) ) as $name ) {
			if ( self::field_key( $type, $name ) === $key ) {
				return $name;
			}
		}

		return '';
	}

	/**
	 * One field definition.
	 *
	 * @param string $label    Label.
	 * @param string $acf      ACF field type: text, textarea, number, select, image.
	 * @param bool   $locked Shown but never written.
	 * @param array  $choices  Choices for a select.
	 * @return array
	 */
	private static function def( $label, $acf, $locked = false, array $choices = array() ) {
		return array(
			'label'    => $label,
			'acf'      => $acf,
			'readonly' => $locked,
			'choices'  => $choices,
		);
	}

	/**
	 * Personal details a member also edits themselves.
	 *
	 * @param bool $with_extras Include linkedin and bio (a direct seller's post does not carry them).
	 * @return array[]
	 */
	private static function personal( $with_extras = true ) {

		$defs = array(
			'first_name' => self::def( __( 'First name', 'nexora' ), 'text' ),
			'last_name'  => self::def( __( 'Last name', 'nexora' ), 'text' ),
			'phone'      => self::def( __( 'Phone', 'nexora' ), 'text' ),
			'gender'     => self::def(
				__( 'Gender', 'nexora' ),
				'select',
				false,
				array(
					''       => __( '— not set —', 'nexora' ),
					'male'   => __( 'Male', 'nexora' ),
					'female' => __( 'Female', 'nexora' ),
					'other'  => __( 'Other', 'nexora' ),
				)
			),
			'birthdate'  => self::def( __( 'Date of birth (YYYY-MM-DD)', 'nexora' ), 'text' ),
		);

		if ( $with_extras ) {
			$defs['linkedin_id'] = self::def( __( 'LinkedIn', 'nexora' ), 'text' );
			$defs['bio']         = self::def( __( 'Bio', 'nexora' ), 'textarea' );
		}

		return $defs;
	}

	/**
	 * Address fields.
	 *
	 * @return array[]
	 */
	private static function address() {

		$labels = array(
			'perm_address' => __( 'Permanent address', 'nexora' ),
			'perm_city'    => __( 'Permanent city', 'nexora' ),
			'perm_state'   => __( 'Permanent state', 'nexora' ),
			'perm_pincode' => __( 'Permanent pincode', 'nexora' ),
			'corr_address' => __( 'Correspondence address', 'nexora' ),
			'corr_city'    => __( 'Correspondence city', 'nexora' ),
			'corr_state'   => __( 'Correspondence state', 'nexora' ),
			'corr_pincode' => __( 'Correspondence pincode', 'nexora' ),
		);

		return self::text_defs( $labels );
	}

	/**
	 * Work fields.
	 *
	 * @return array[]
	 */
	private static function work() {

		return self::text_defs(
			array(
				'company_name'    => __( 'Company name', 'nexora' ),
				'designation'     => __( 'Designation', 'nexora' ),
				'company_email'   => __( 'Company email', 'nexora' ),
				'company_phone'   => __( 'Company phone', 'nexora' ),
				'company_address' => __( 'Company address', 'nexora' ),
			)
		);
	}

	/**
	 * Profile and cover image.
	 *
	 * @return array[]
	 */
	private static function images() {
		return array(
			'profile_image' => self::def( __( 'Profile image', 'nexora' ), 'image' ),
			'cover_image'   => self::def( __( 'Cover image', 'nexora' ), 'image' ),
		);
	}

	/**
	 * Identity and links that are shown and never changed.
	 *
	 * @param bool $customer A customer post (has a link to a seller record).
	 * @return array[]
	 */
	private static function identity( $customer ) {

		$defs = array(
			'user_name'   => self::def( __( 'User name', 'nexora' ), 'text', true ),
			'email'       => self::def( __( 'Email', 'nexora' ), 'text', true ),
			'_wp_user_id' => self::def( __( 'Owner (user ID)', 'nexora' ), 'number', true ),
		);

		if ( $customer ) {
			$defs['seller_post_id'] = self::def( __( 'Seller record (post ID)', 'nexora' ), 'number', true );
		}

		return $defs;
	}

	/**
	 * The ID documents: private files, shown to the administrator as a number and never edited here.
	 *
	 * @return array[]
	 */
	private static function documents() {
		return array(
			'aadhaar_card'    => self::def( __( 'Aadhaar card (private file)', 'nexora' ), 'number', true ),
			'driving_license' => self::def( __( 'Driving license (private file)', 'nexora' ), 'number', true ),
			'company_id_card' => self::def( __( 'Company ID card (private file)', 'nexora' ), 'number', true ),
		);
	}

	/**
	 * The business details of a seller.
	 *
	 * @return array[]
	 */
	private static function business() {

		$defs = array();

		foreach ( \Nexora\Vendors\Fields::labels() as $key => $label ) {
			$defs[ $key ] = 'business_type' === $key
				? self::def( $label, 'select', false, \Nexora\Vendors\Fields::business_types() )
				: self::def( $label, 'text' );
		}

		return $defs;
	}

	/**
	 * The seller's status (stored on the account, changed through the same logic as the decision buttons).
	 *
	 * @return array[]
	 */
	private static function status() {
		return array(
			'status' => self::def(
				__( 'Seller status', 'nexora' ),
				'select',
				false,
				array(
					'pending'   => __( 'Pending', 'nexora' ),
					'approved'  => __( 'Approved', 'nexora' ),
					'rejected'  => __( 'Rejected', 'nexora' ),
					'suspended' => __( 'Suspended', 'nexora' ),
				)
			),
		);
	}

	/**
	 * Text field definitions from a name => label list.
	 *
	 * @param array $labels Name => label.
	 * @return array[]
	 */
	private static function text_defs( array $labels ) {

		$defs = array();

		foreach ( $labels as $name => $label ) {
			$defs[ $name ] = self::def( $label, 'text' );
		}

		return $defs;
	}
}
