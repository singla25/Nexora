<?php if ( ! defined( 'ABSPATH' ) ) exit;
$current_user = wp_get_current_user();
?>
<div class="nexora-gate">
    <div class="gate-icon">⚙️</div>
    <h2>Welcome back, <?php echo esc_html( $current_user->display_name ); ?> 👋</h2>
    <p class="gate-sub">You are currently in admin mode.</p>
    <p>Manage users, content and system settings from your dashboard.</p>
    <a href="<?php echo esc_url( admin_url() ); ?>" class="btn btn-primary">Go to Dashboard →</a>
</div>
