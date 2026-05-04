<?php
/**
 * partials/header.php
 *
 * Cover image, avatar, display name, contact line.
 *
 * Requires:
 *   $header   (array) from NEXORA_DASHBOARD_HELPER::get_profile_header()
 *   $context  (array)
 *   $is_owner (bool)
 */

if ( ! defined( 'ABSPATH' ) ) exit;
?>

<div class="profile-cover"
     style="background-image: url('<?php echo esc_url( $header['cover'] ); ?>');">
</div>

<div class="profile-header">

    <img src="<?php echo esc_url( $header['image'] ); ?>"
         alt="<?php echo esc_attr( $header['username'] ); ?>"
         class="profile-avatar">

    <h2><?php echo esc_html( $header['username'] ); ?></h2>
    <h4><?php echo esc_html( $header['name'] ); ?></h4>

    <p>
        <?php echo esc_html( $header['email'] ); ?>
        <?php if ( $header['phone'] ) echo ' | ' . esc_html( $header['phone'] ); ?>
    </p>

    <?php
    /**
     * Hook: nexora_dashboard_after_header
     * Inject badges, follow buttons, vendor badges, etc.
     */
    do_action( 'nexora_dashboard_after_header', $context );
    ?>

</div><!-- .profile-header -->
