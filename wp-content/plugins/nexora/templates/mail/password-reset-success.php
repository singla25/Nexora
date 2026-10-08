<?php /** @var WP_User $user */ ?>
<div style='font-family:Segoe UI, sans-serif; padding:20px; background:#f8fafc;'>
	<div style='max-width:500px; margin:auto; background:#fff; padding:20px; border-radius:10px;'>
		<h2 style='color:#16a34a;'><?php esc_html_e( 'Password Reset Successful', 'nexora' ); ?></h2>
		<p><?php esc_html_e( 'Hi', 'nexora' ); ?> <strong><?php echo esc_html( $user->display_name ); ?></strong>,</p>
		<p><?php esc_html_e( 'Your password has been successfully reset.', 'nexora' ); ?></p>
		<p><?php esc_html_e( 'If this was you, enjoy using', 'nexora' ); ?> <b><?php esc_html_e( 'Nexora', 'nexora' ); ?></b>.</p>
		<p style='color:#ef4444;'><?php esc_html_e( 'If not, please contact support immediately.', 'nexora' ); ?></p>
		<hr>
		<p style='font-size:12px; color:#64748b;'><?php esc_html_e( '— Nexora Team', 'nexora' ); ?></p>
	</div>
</div>
