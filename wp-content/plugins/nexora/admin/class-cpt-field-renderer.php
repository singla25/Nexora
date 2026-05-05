<?php
/**
 * admin/class-cpt-field-renderer.php
 *
 * Pure static render helpers.
 * No hooks. No state. No WordPress side-effects.
 *
 * Used by:
 *   NEXORA_Metabox_User   — personal + address sections
 *   NEXORA_Metabox_Vendor — personal + address sections (identical fields)
 *
 * ── Adding a new shared field ─────────────────────────────────────────────
 *   Add a method here, call it from whichever metabox class needs it.
 *   Never duplicate HTML across metabox files — put it here instead.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_CPT_Field_Renderer {

    /**
     * Render personal information fields.
     * Used for both user_profile and vendor_profile CPTs.
     *
     * @param WP_Post $post
     */
    public static function personal_fields( WP_Post $post ): void {

        $m = fn( $key ) => esc_attr( get_post_meta( $post->ID, $key, true ) );
        ?>

        <input type="text" name="user_name" placeholder="User Name"
            value="<?php echo $m( 'user_name' ); ?>"
            class="widefat" readonly><br><br>

        <input type="email" name="email" placeholder="Email"
            value="<?php echo $m( 'email' ); ?>"
            class="widefat" readonly><br><br>

        <input type="text" name="first_name" placeholder="First Name"
            value="<?php echo $m( 'first_name' ); ?>"
            class="widefat"><br><br>

        <input type="text" name="last_name" placeholder="Last Name"
            value="<?php echo $m( 'last_name' ); ?>"
            class="widefat"><br><br>

        <input type="text" name="phone" placeholder="Phone"
            value="<?php echo $m( 'phone' ); ?>"
            class="widefat"><br><br>

        <input type="text" name="linkedin_id" placeholder="LinkedIn"
            value="<?php echo $m( 'linkedin_id' ); ?>"
            class="widefat"><br><br>

        <label>Gender</label>
        <select name="gender" class="widefat">
            <option value="">Select Gender</option>
            <?php
            $gender = get_post_meta( $post->ID, 'gender', true );
            foreach ( [ 'male' => 'Male', 'female' => 'Female', 'other' => 'Other' ] as $val => $label ) {
                printf(
                    '<option value="%s" %s>%s</option>',
                    esc_attr( $val ),
                    selected( $gender, $val, false ),
                    esc_html( $label )
                );
            }
            ?>
        </select><br><br>

        <label>Birthdate</label>
        <input type="date" name="birthdate"
            value="<?php echo $m( 'birthdate' ); ?>"
            class="widefat"><br><br>

        <textarea name="bio" placeholder="Bio" class="widefat"
            ><?php echo esc_textarea( get_post_meta( $post->ID, 'bio', true ) ); ?></textarea>

        <?php
    }

    /**
     * Render permanent + correspondence address fields.
     * Used for both user_profile and vendor_profile CPTs.
     *
     * @param WP_Post $post
     */
    public static function address_fields( WP_Post $post ): void {

        $m = fn( $key ) => esc_attr( get_post_meta( $post->ID, $key, true ) );
        ?>

        <h3>Permanent Address</h3>
        <input type="text" name="perm_address" placeholder="Address"   value="<?php echo $m( 'perm_address' ); ?>"  class="widefat"><br><br>
        <input type="text" name="perm_city"    placeholder="City"      value="<?php echo $m( 'perm_city' ); ?>"     class="widefat"><br><br>
        <input type="text" name="perm_state"   placeholder="State"     value="<?php echo $m( 'perm_state' ); ?>"    class="widefat"><br><br>
        <input type="text" name="perm_pincode" placeholder="Pincode"   value="<?php echo $m( 'perm_pincode' ); ?>"  class="widefat"><br><br>

        <h3>Correspondence Address</h3>
        <input type="text" name="corr_address" placeholder="Address"   value="<?php echo $m( 'corr_address' ); ?>"  class="widefat"><br><br>
        <input type="text" name="corr_city"    placeholder="City"      value="<?php echo $m( 'corr_city' ); ?>"     class="widefat"><br><br>
        <input type="text" name="corr_state"   placeholder="State"     value="<?php echo $m( 'corr_state' ); ?>"    class="widefat"><br><br>
        <input type="text" name="corr_pincode" placeholder="Pincode"   value="<?php echo $m( 'corr_pincode' ); ?>"  class="widefat"><br><br>

        <?php
    }

    /**
     * Render a single document upload box (media picker).
     * Used by both user and vendor document metaboxes.
     *
     * @param int    $post_id
     * @param string $key    Meta key storing the attachment ID
     * @param string $label  Human-readable label
     */
    public static function document_field( int $post_id, string $key, string $label ): void {

        $image_id  = get_post_meta( $post_id, $key, true );
        $image_url = $image_id ? wp_get_attachment_url( $image_id ) : '';
        ?>

        <div class="profile-upload-box">
            <label><strong><?php echo esc_html( $label ); ?></strong></label><br>

            <img src="<?php echo esc_url( $image_url ); ?>"
                 class="profile-preview"
                 style="max-width:150px; display:<?php echo $image_url ? 'block' : 'none'; ?>; margin-bottom:10px;">

            <input type="hidden"
                   name="<?php echo esc_attr( $key ); ?>"
                   value="<?php echo esc_attr( $image_id ); ?>">

            <button type="button" class="button upload-btn">Upload</button>
            <button type="button" class="button remove-btn"
                    style="<?php echo $image_url ? '' : 'display:none;'; ?>">Remove</button>
        </div>
        <hr>

        <?php
    }
}
