<?php
/**
 * partials/gate-login.php
 * Shown to a guest visiting /dashboard with no username in the URL.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="nexora-gate">
    <h2>🔒 Access Restricted</h2>
    <p>Please login or sign up to access your dashboard.</p>
    <a href="<?php echo esc_url( home_url( '/login-page' ) ); ?>" class="btn btn-primary">Login</a>
    <a href="<?php echo esc_url( home_url( '/registration-page' ) ); ?>" class="btn btn-success">Sign Up</a>
</div>
