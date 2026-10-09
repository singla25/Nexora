<?php
/** @var string $captcha_html  Captcha widget markup from Recaptcha::render() (already safe HTML) */
/** @var string $login_url */
?>
<div class="profile-registration-form-div">
	<form id="profile-registration-form" class="profile-registration-form" enctype="multipart/form-data">

		<h2><?php esc_html_e( 'Create Your Account', 'nexora' ); ?></h2>

		<div class="profile-registration-form-grid">

			<!-- FULL WIDTH -->
			<input type="email" name="email" placeholder="<?php echo esc_attr__( 'Email *', 'nexora' ); ?>" class="full-width" required>

			<!-- ROW 1 -->
			<input type="text" name="user_name" placeholder="<?php echo esc_attr__( 'User Name *', 'nexora' ); ?>" required>
			<select name="gender" required>
				<option value=""><?php esc_html_e( 'Select Gender *', 'nexora' ); ?></option>
				<option value="male"><?php esc_html_e( 'Male', 'nexora' ); ?></option>
				<option value="female"><?php esc_html_e( 'Female', 'nexora' ); ?></option>
				<option value="other"><?php esc_html_e( 'Other', 'nexora' ); ?></option>
			</select>

			<!-- ROW 2 -->
			<input type="text" name="first_name" placeholder="<?php echo esc_attr__( 'First Name *', 'nexora' ); ?>" required>
			<input type="text" name="last_name" placeholder="<?php echo esc_attr__( 'Last Name *', 'nexora' ); ?>" required>

			<!-- ROW 3 -->
			<input type="text" name="phone" placeholder="<?php echo esc_attr__( 'Phone *', 'nexora' ); ?>" required>
			<input type="text" name="birthdate" placeholder="<?php echo esc_attr__( 'Date of Birth *', 'nexora' ); ?>" required
					onfocus="(this.type='date')"
					onblur="if(!this.value)this.type='text'">

			<!-- ROW 4 -->
			<input type="password" name="password" placeholder="<?php echo esc_attr__( 'Password * (min 8 characters)', 'nexora' ); ?>" minlength="8" required>
			<input type="password" name="confirm_password" placeholder="<?php echo esc_attr__( 'Confirm Password *', 'nexora' ); ?>" required>

			<!-- 🔥 Toggle Switch -->
			<div class="password-toggle-wrapper full-width">
				<label class="switch">
					<input type="checkbox" id="toggle-passwords">
					<span class="slider"></span>
				</label>
				<span class="toggle-label"><?php esc_html_e( 'Show Password', 'nexora' ); ?></span>
			</div>

		</div>

		<?php echo $captcha_html; // phpcs:ignore WordPress.Security.EscapeOutput -- markup built and escaped by Recaptcha::render() ?>

		<button type="submit" class="profile-registration-form-btn"><?php esc_html_e( 'Create Account', 'nexora' ); ?></button>

		<div class="profile-registration-extra">
			<?php esc_html_e( 'Already have an account?', 'nexora' ); ?> 
			<a href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Login', 'nexora' ); ?></a>
		</div>
	</form>
</div>
