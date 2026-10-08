<?php
/** @var WP_User $user */
?>
<div class="login-state-wrapper">

	<div class="login-state-card">

		<div class="login-avatar">
			<span><?php echo esc_html( mb_strtoupper( mb_substr( $user->display_name, 0, 1 ) ) ); ?></span>
		</div>

		<h2><?php /* translators: %s: member's display name. */ printf( esc_html__( 'Welcome back, %s', 'nexora' ), esc_html( $user->display_name ) ); ?> 👋</h2>
		<p><?php esc_html_e( 'You are already logged in', 'nexora' ); ?></p>

		<div class="login-actions">
			<a href="<?php echo esc_url( $profile_url ); ?>" class="btn-primary">
				<?php esc_html_e( 'Go to Profile', 'nexora' ); ?>
			</a>

			<a href="<?php echo esc_url( $logout_url ); ?>" class="btn-danger">
				<?php esc_html_e( 'Logout', 'nexora' ); ?>
			</a>
		</div>

	</div>

</div>
