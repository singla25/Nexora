<?php /** @var WP_User $user */ ?>
<div class="register-state-wrapper">

	<div class="register-state-card">

		<div class="register-avatar">
			<span><?php echo esc_html( mb_strtoupper( mb_substr( $user->display_name, 0, 1 ) ) ); ?></span>
		</div>

		<h2><?php /* translators: %s: member's display name. */ printf( esc_html__( 'Hey %s', 'nexora' ), esc_html( $user->display_name ) ); ?> 👋</h2>
		<p><?php esc_html_e( 'You are already logged in', 'nexora' ); ?></p>

		<a href="<?php echo esc_url( $profile_url ); ?>" class="btn-primary">
			<?php esc_html_e( 'Go to Profile', 'nexora' ); ?>
		</a>

	</div>

</div>
