<?php
/**
 * admin/class-metabox-vendor.php
 *
 * Metabox render callbacks for the vendor_profile CPT only.
 *
 * ── Adding a new vendor metabox ───────────────────────────────────────────
 *   1. Add the render method here.
 *   2. Register it in NEXORA_CPT_Register::add_meta_boxes().
 *   3. Add the field key(s) to NEXORA_CPT_Register::SAVE_FIELDS['vendor_profile'].
 *   That's it — saving is handled automatically.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_Metabox_Vendor {

    /* =========================================================================
       PERSONAL  (shared renderer — identical fields to user)
    ========================================================================= */

    public function personal_details( WP_Post $post ): void {
        NEXORA_CPT_Field_Renderer::personal_fields( $post );
    }

    /* =========================================================================
       ADDRESS  (shared renderer — identical fields to user)
    ========================================================================= */

    public function address_details( WP_Post $post ): void {
        NEXORA_CPT_Field_Renderer::address_fields( $post );
    }

    /* =========================================================================
       BUSINESS DETAILS
    ========================================================================= */

    public function business_details( WP_Post $post ): void {

        $m = fn( $key ) => esc_attr( get_post_meta( $post->ID, $key, true ) );
        ?>

        <input type="text"  name="business_name"     placeholder="Business Name"     value="<?php echo $m( 'business_name' ); ?>"     class="widefat"><br><br>
        <input type="text"  name="business_type"     placeholder="Business Type"     value="<?php echo $m( 'business_type' ); ?>"     class="widefat"><br><br>
        <input type="email" name="business_email"    placeholder="Business Email"    value="<?php echo $m( 'business_email' ); ?>"    class="widefat"><br><br>
        <input type="text"  name="business_phone"    placeholder="Business Phone"    value="<?php echo $m( 'business_phone' ); ?>"    class="widefat"><br><br>
        <input type="text"  name="gst_number"        placeholder="GST Number"        value="<?php echo $m( 'gst_number' ); ?>"        class="widefat"><br><br>
        <input type="text"  name="business_category" placeholder="Business Category" value="<?php echo $m( 'business_category' ); ?>" class="widefat"><br><br>
        <input type="text"  name="service_areas"     placeholder="Service Areas (e.g. Delhi, NCR)" value="<?php echo $m( 'service_areas' ); ?>" class="widefat"><br><br>
        <input type="number" name="years_in_business" placeholder="Years in Business" value="<?php echo $m( 'years_in_business' ); ?>" class="widefat"><br><br>
        <input type="url"   name="website_url"       placeholder="Website URL"       value="<?php echo $m( 'website_url' ); ?>"       class="widefat"><br><br>

        <textarea name="business_address" placeholder="Business Address"
                  class="widefat"><?php echo esc_textarea( get_post_meta( $post->ID, 'business_address', true ) ); ?></textarea>

        <?php
    }

    /* =========================================================================
       DOCUMENTS
    ========================================================================= */

    public function document_details( WP_Post $post ): void {

        $fields = [
            'profile_image'    => 'Profile Image',
            'cover_image'      => 'Cover Image',
            'aadhaar_card'     => 'Aadhaar Card',
            'company_id_card'  => 'Company ID Card',
            'gst_certificate'  => 'GST Certificate',
            'business_license' => 'Business License',
            'pan_card'         => 'PAN Card',
            'bank_proof'       => 'Bank Proof',
        ];

        foreach ( $fields as $key => $label ) {
            NEXORA_CPT_Field_Renderer::document_field( $post->ID, $key, $label );
        }
    }
}
