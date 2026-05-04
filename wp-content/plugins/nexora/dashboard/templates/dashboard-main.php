<?php
/**
 * templates/dashboard-main.php
 *
 * Orchestrates all partials and tab files.
 * Receives $context from NEXORA_DASHBOARD_CORE::render() via extract().
 *
 * Available variables (all extracted from $context):
 *   @var int    $profile_id
 *   @var string $role_type      guest | owner | viewer
 *   @var string $profile_role   user | vendor  (the profile being viewed)
 *   @var string $viewer_role    user | vendor  (the logged-in visitor's type)
 *   @var bool   $is_owner
 *   @var bool   $is_logged_in
 *   @var array  $context        full context array (for partials that need it)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Shared data for all partials ──────────────────────────────
$header       = NEXORA_DASHBOARD_HELPER::get_profile_header( $profile_id );
$visible_tabs = NEXORA_DASHBOARD_HELPER::get_visible_tabs( $context );
$info_subtabs = NEXORA_DASHBOARD_HELPER::get_info_subtabs( $context );
$unread_count = NEXORA_DASHBOARD_HELPER::get_unread_notification_count( $context['current_user_id'] );

$first_tab = $visible_tabs[0] ?? NEXORA_DASHBOARD_HELPER::TAB_INFO;
?>

<div class="profile-container">
    <div class="profile-wrapper">

        <?php
        // ── Cover + Avatar ─────────────────────────────────────
        include __DIR__ . '/partials/header.php';

        // ── Tab Navigation ─────────────────────────────────────
        include __DIR__ . '/partials/tabs.php';
        ?>

        <!-- Tab Contents -->
        <div class="profile-content">

            <?php foreach ( $visible_tabs as $tab_slug ) :

                $is_active = ( $tab_slug === $first_tab );
                $tab_file  = __DIR__ . '/tabs/' . $tab_slug . '.php';

                if ( ! file_exists( $tab_file ) ) continue;
            ?>

            <div class="tab-content <?php echo $is_active ? 'active' : ''; ?>"
                 id="<?php echo esc_attr( $tab_slug ); ?>">

                <?php include $tab_file; ?>

            </div>

            <?php endforeach; ?>

        </div><!-- .profile-content -->

    </div><!-- .profile-wrapper -->

    <?php if ( $is_owner ) : ?>
    <div class="dashboard-logout">
        <a class="logout-btn"
           href="<?php echo esc_url( wp_logout_url( home_url( '/login-page' ) ) ); ?>">
            Logout
        </a>
    </div>
    <?php endif; ?>

</div><!-- .profile-container -->
