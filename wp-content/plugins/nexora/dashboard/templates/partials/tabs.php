<?php
/**
 * partials/tabs.php
 *
 * Renders the tab navigation bar.
 * Tab labels defined here — rename without touching any other file.
 *
 * Requires:
 *   $visible_tabs  (string[])
 *   $unread_count  (int)
 *   $context       (array)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Tab label map.
 * To add a tab: add slug => label here AND create templates/tabs/{slug}.php.
 * Extend via the filter from another plugin/module.
 */
$tab_labels = apply_filters( 'nexora_dashboard_tab_labels', [
    NEXORA_DASHBOARD_HELPER::TAB_INFO          => 'Information',
    NEXORA_DASHBOARD_HELPER::TAB_CONNECTIONS   => 'Connections',
    NEXORA_DASHBOARD_HELPER::TAB_CONTENT       => 'Content',
    NEXORA_DASHBOARD_HELPER::TAB_MARKET        => 'Marketplace',
    NEXORA_DASHBOARD_HELPER::TAB_NOTIFICATIONS => 'Notifications',
] );

$first_tab = $visible_tabs[0] ?? NEXORA_DASHBOARD_HELPER::TAB_INFO;
?>

<div class="profile-tabs">

    <?php foreach ( $visible_tabs as $slug ) :

        $label     = $tab_labels[ $slug ] ?? ucwords( str_replace( '-', ' ', $slug ) );
        $is_active = ( $slug === $first_tab );
    ?>

        <button class="tab-btn <?php echo $is_active ? 'active' : ''; ?>"
                data-tab="<?php echo esc_attr( $slug ); ?>">

            <?php echo esc_html( $label ); ?>

            <?php if ( $slug === NEXORA_DASHBOARD_HELPER::TAB_NOTIFICATIONS && $unread_count > 0 ) : ?>
                <span class="noti-badge"><?php echo (int) $unread_count; ?></span>
            <?php endif; ?>

        </button>

    <?php endforeach; ?>

</div><!-- .profile-tabs -->
