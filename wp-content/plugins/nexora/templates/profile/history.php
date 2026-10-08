<?php
/** @var array[] $received  rows: status, username, name, image, date, time, link */
/** @var array[] $sent */
?>
<div class="history-wrapper">

	<!-- ===============================
		RECEIVED
	=============================== -->
	<div class="history-section">
		<h3><?php esc_html_e( '📥 Received Requests', 'nexora' ); ?></h3>

		<?php
		if ( $received ) :
			foreach ( $received as $row ) :
				?>

							<?php \Nexora\Core\View::output( 'profile/history-card', array( 'row' => $row ) ); ?>

					<?php endforeach; else : ?>
			<p class="history-empty"><?php esc_html_e( 'No received requests', 'nexora' ); ?></p>
		<?php endif; ?>

	</div>

	<!-- ===============================
		SENT
	=============================== -->
	<div class="history-section">
		<h3><?php esc_html_e( '📤 Sent Requests', 'nexora' ); ?></h3>

		<?php
		if ( $sent ) :
			foreach ( $sent as $row ) :
				?>

							<?php \Nexora\Core\View::output( 'profile/history-card', array( 'row' => $row ) ); ?>

					<?php endforeach; else : ?>
			<p class="history-empty"><?php esc_html_e( 'No sent requests', 'nexora' ); ?></p>
		<?php endif; ?>
	</div>
</div>
