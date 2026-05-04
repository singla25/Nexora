<?php
/**
 * tabs/user-info.php
 *
 * Role matrix:
 *
 *  owner (user or vendor)
 *    → Sub-tab bar (Personal / Address / Work|Business / Documents / Security [/ Vendor Details for vendor])
 *    → Editable forms (JS-driven, data pre-filled via nexoraDashboard.userData)
 *
 *  viewer (logged-in, not owner) or guest
 *    → Read-only info cards, no sub-tabs
 *    → Vendor profiles: business section always visible to everyone
 *
 * Requires:
 *   $context, $is_owner, $is_logged_in,
 *   $profile_id, $profile_role, $info_subtabs
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$pid = $profile_id;
$m   = fn( $key ) => get_post_meta( $pid, $key, true );

$personal_fields = NEXORA_DASHBOARD_HELPER::get_personal_fields();
$address_fields  = NEXORA_DASHBOARD_HELPER::get_address_fields();
$work_config     = NEXORA_DASHBOARD_HELPER::get_work_fields( $profile_role );
$vendor_fields   = NEXORA_DASHBOARD_HELPER::get_vendor_detail_fields();
$doc_fields      = NEXORA_DASHBOARD_HELPER::get_document_fields( $profile_role );
?>

<!-- ── Info Tab Header ──────────────────────────────────────── -->
<div class="user-info-header">

    <?php if ( $is_owner ) : ?>

        <div class="user-info-left">
            <h3>Your Information</h3>
            <span class="user-info-sub">Manage your details</span>
        </div>

        <!-- Fix #2: sub-tabs rendered in the correct order defined by get_info_subtabs() -->
        <div class="user-info-right">
            <?php foreach ( $info_subtabs as $slug => $label ) : ?>
                <button class="user-edit-info"
                        data-type="<?php echo esc_attr( $slug ); ?>">
                    <?php echo esc_html( $label ); ?>
                </button>
            <?php endforeach; ?>
        </div>

    <?php else : ?>

        <!-- Fix #4: removed "business_name" heading for non-owner vendor profiles.
             The heading is gone entirely — no unnecessary text in the header. -->
        <div class="user-info-center">
            <?php if ( ! $is_logged_in ) : ?>
                <span class="user-info-sub">Login to explore more</span>
            <?php endif; ?>
        </div>

    <?php endif; ?>

</div><!-- .user-info-header -->


<!-- ── Info Content Panels ──────────────────────────────────── -->
<div id="user-info-content">


    <!-- ── PERSONAL ──────────────────────────────────────────── -->
    <div class="info-card" data-section="personal-info">
        <h3>Personal Information</h3>
        <div class="info-grid">
            <?php foreach ( $personal_fields as $key => $label ) : ?>
                <div class="info-item">
                    <span class="info-label"><?php echo esc_html( $label ); ?></span>
                    <span class="info-value"><?php echo esc_html( $m( $key ) ); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="info-full">
            <span class="info-label">Bio</span>
            <p class="info-value"><?php echo esc_html( $m( 'bio' ) ); ?></p>
        </div>
    </div><!-- personal-info -->


    <!-- ── ADDRESS ───────────────────────────────────────────── -->
    <div class="info-card" data-section="address-info">
        <h3>Address Information</h3>

        <div class="info-section">
            <h4>Permanent Address</h4>
            <div class="info-grid">
                <?php foreach ( $address_fields['permanent'] as $key => $label ) : ?>
                    <div class="info-item">
                        <span class="info-label"><?php echo esc_html( $label ); ?></span>
                        <span class="info-value"><?php echo esc_html( $m( $key ) ); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="info-section">
            <h4>Correspondence Address</h4>
            <div class="info-grid">
                <?php foreach ( $address_fields['correspondence'] as $key => $label ) : ?>
                    <div class="info-item">
                        <span class="info-label"><?php echo esc_html( $label ); ?></span>
                        <span class="info-value"><?php echo esc_html( $m( $key ) ); ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div><!-- address-info -->


    <!-- ── WORK / BUSINESS (role-adaptive, Fix #1: correct meta keys shown for all roles) -->
    <div class="info-card" data-section="work-info">
        <h3><?php echo esc_html( $work_config['heading'] ); ?></h3>
        <div class="info-grid">
            <?php foreach ( $work_config['fields'] as $key => $label ) : ?>
                <div class="info-item">
                    <span class="info-label"><?php echo esc_html( $label ); ?></span>
                    <span class="info-value"><?php echo esc_html( $m( $key ) ); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div><!-- work-info -->


    <!-- ── VENDOR DETAILS (vendor-owner only) ────────────────── -->
    <?php if ( $profile_role === 'vendor' && $is_owner ) : ?>
    <div class="info-card" data-section="vendor-info">
        <h3>Vendor Details</h3>
        <div class="info-grid">
            <?php foreach ( $vendor_fields as $key => $label ) : ?>
                <div class="info-item">
                    <span class="info-label"><?php echo esc_html( $label ); ?></span>
                    <span class="info-value"><?php echo esc_html( $m( $key ) ); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div><!-- vendor-info -->
    <?php endif; ?>


    <!-- ── DOCUMENTS ──────────────────────────────────────────── -->
    <!-- Fix #3: Documents section now visible for vendor-owner too (via sub-tab) -->
    <div class="info-card" data-section="docs-info">
        <h3>Documents</h3>
        <div class="doc-grid">
            <?php foreach ( $doc_fields as $key => $label ) :

                $option_key = in_array( $key, [ 'profile_image', 'cover_image' ], true )
                    ? 'default_' . $key
                    : 'default_document_image';

                $url = NEXORA_DASHBOARD_HELPER::get_image_url( $pid, $key, $option_key );
            ?>
                <div class="doc-card">
                    <span class="doc-title"><?php echo esc_html( $label ); ?></span>
                    <?php if ( $url ) : ?>
                        <a href="<?php echo esc_url( $url ); ?>" target="_blank">
                            <img src="<?php echo esc_url( $url ); ?>"
                                 class="doc-img"
                                 alt="<?php echo esc_attr( $label ); ?>">
                        </a>
                    <?php else : ?>
                        <div class="doc-empty-box">No File</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div><!-- docs-info -->


    <?php
    do_action( 'nexora_dashboard_user_info_after_docs', $context );
    ?>

</div><!-- #user-info-content -->
