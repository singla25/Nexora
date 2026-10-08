<?php
/** @var WP_User $user */
?>
<div class="login-state-wrapper">

	<div class="login-state-card">

		<div class="login-avatar">
			<span><?php echo esc_html( mb_strtoupper( mb_substr( $user->display_name, 0, 1 ) ) ); ?></span>
		</div>

		<h2>Welcome back, <?php echo esc_html( $user->display_name ); ?> 👋</h2>
		<p>You are already logged in</p>

		<div class="login-actions">
			<a href="<?php echo esc_url( $profile_url ); ?>" class="btn-primary">
				Go to Profile
			</a>

			<a href="<?php echo esc_url( $logout_url ); ?>" class="btn-danger">
				Logout
			</a>
		</div>

	</div>

</div>
