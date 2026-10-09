<?php
/** @var string $login_url */
/** @var string $register_url */
?>
<div style="max-width:500px;margin:100px auto;text-align:center;padding:40px;background:#fff;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.1);">
	<h2 style="margin-bottom:10px;"><?php esc_html_e( '🔒 Access Restricted', 'nexora' ); ?></h2>
	<p style="color:#6b7280; margin-bottom:20px;">
		<?php esc_html_e( 'Please login or sign up to access your profile', 'nexora' ); ?>
	</p>

	<a href="<?php echo esc_url( $login_url ); ?>" 
	style="display:inline-block; padding:10px 20px; background:#14275c; color:#fff; border-radius:8px; text-decoration:none; margin-right:10px;">
	<?php esc_html_e( 'Login', 'nexora' ); ?>
	</a>

	<a href="<?php echo esc_url( $register_url ); ?>" 
	style="display:inline-block; padding:10px 20px; background:#16a34a; color:#fff; border-radius:8px; text-decoration:none;">
	<?php esc_html_e( 'Sign Up', 'nexora' ); ?>
	</a>
</div>
