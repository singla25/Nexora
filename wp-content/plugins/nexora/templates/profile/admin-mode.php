<?php
/** @var WP_User $user */
/** @var string  $dashboard_url */
?>
<div style="max-width:520px;margin:120px auto;text-align:center;padding:50px 40px;background:#ffffff;
border-radius:16px;box-shadow:0 20px 50px rgba(0,0,0,0.08);font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">
	
	<div style="font-size:40px; margin-bottom:10px;">⚙️</div>

	<h2 style="margin-bottom:8px;font-size:22px;font-weight:600;color:#111827;">
		Welcome back, <?php echo esc_html( $user->display_name ); ?> 👋
	</h2>

	<p style="color:#9ca3af;font-size:14px;margin-bottom:6px;">
		You are currently in admin mode
	</p>

	<p style="color:#4b5563;font-size:15px;margin-bottom:25px;">
		Manage users, content and system settings from your dashboard.
	</p>

	<a href="<?php echo esc_url( $dashboard_url ); ?>"
	style="display:inline-block;padding:12px 24px;background:linear-gradient(135deg,#14275c,#2c8fd1);color:#fff;
	border-radius:10px;text-decoration:none;font-size:14px;font-weight:500;box-shadow:0 8px 20px rgba(20,39,92,0.3);transition:all 0.2s ease;">
		Go to Dashboard →
	</a>

</div>
