<?php /** @var WP_User $user */ ?>
<div style='font-family:Segoe UI, sans-serif; padding:20px; background:#f8fafc;'>
	<div style='max-width:500px; margin:auto; background:#fff; padding:20px; border-radius:10px;'>
		<h2 style='color:#16a34a;'>Password Reset Successful</h2>
		<p>Hi <strong><?php echo esc_html( $user->display_name ); ?></strong>,</p>
		<p>Your password has been successfully reset.</p>
		<p>If this was you, enjoy using <b>Nexora</b>.</p>
		<p style='color:#ef4444;'>If not, please contact support immediately.</p>
		<hr>
		<p style='font-size:12px; color:#64748b;'>— Nexora Team</p>
	</div>
</div>
