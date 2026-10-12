<?php
/** @var string $captcha_html  Captcha widget markup from Recaptcha::render() (already safe HTML) */
/** @var string $register_url */
?>
<div class="profile-login-wrapper">
	<div class="profile-login-card">

		<form id="profile-login-form">

			<h2><?php esc_html_e( 'Welcome Back 👋', 'nexora' ); ?></h2>

			<input type="text" name="user_name" placeholder="<?php echo esc_attr__( 'Username or Email', 'nexora' ); ?>" required>
			<input type="password" name="password" placeholder="<?php echo esc_attr__( 'Password', 'nexora' ); ?>" required>

			<div class="password-toggle-wrapper full-width">
				<label class="switch">
					<input type="checkbox" id="toggle-passwords">
					<span class="slider"></span>
				</label>
				<span class="toggle-label"><?php esc_html_e( 'Show Password', 'nexora' ); ?></span>
			</div>

			<?php echo $captcha_html; // phpcs:ignore WordPress.Security.EscapeOutput -- markup built and escaped by Recaptcha::render() ?>

			<button type="submit"><?php esc_html_e( 'Login', 'nexora' ); ?></button>

			<div class="profile-login-extra">
				<?php esc_html_e( 'Don’t have an account?', 'nexora' ); ?> 
				<a href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Register', 'nexora' ); ?></a>
			</div>

			<div class="profile-login-password">
				<button type="button" id="forgot-password-btn" class="forgot-password-btn" data-type="forgot-password">
					<?php esc_html_e( 'Forgot Password?', 'nexora' ); ?>
				</button>
			</div>
		</form>
	</div>
</div>
