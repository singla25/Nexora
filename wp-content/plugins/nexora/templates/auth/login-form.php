<?php
/** @var string $captcha_html  Captcha widget markup from Recaptcha::render() (already safe HTML) */
/** @var string $register_url */
?>
<div class="profile-login-wrapper">
    <div class="profile-login-card">

        <form id="profile-login-form">

            <h2>Welcome Back 👋</h2>

            <input type="text" name="user_name" placeholder="Username or Email" required>
            <input type="password" name="password" placeholder="Password" required>

            <div class="password-toggle-wrapper full-width">
                <label class="switch">
                    <input type="checkbox" id="toggle-passwords">
                    <span class="slider"></span>
                </label>
                <span class="toggle-label">Show Password</span>
            </div>

            <?php echo $captcha_html; // phpcs:ignore WordPress.Security.EscapeOutput -- markup built and escaped by Recaptcha::render() ?>

            <button type="submit">Login</button>

            <div class="profile-login-extra">
                Don’t have an account? 
                <a href="<?php echo esc_url($register_url); ?>">Register</a>
            </div>

            <div class="profile-login-password">
                <button type="button" id="forgot-password-btn" class="forgot-password-btn" data-type="forgot-password">
                    Forgot Password?
                </button>
            </div>
        </form>
    </div>
</div>
