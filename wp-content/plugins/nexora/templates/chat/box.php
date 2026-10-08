<?php
$is_admin = current_user_can( 'manage_options' );
?>

<div class="chat-box">

	<!-- HEADER -->
	<div class="chat-header">
		<div class="chat-header-left">
			<div class="chat-avatar"></div>
			<div>
				<div id="chat-title"><?php esc_html_e( 'Select Chat', 'nexora' ); ?></div>

				<div id="chat-subject-area"></div>
			</div>
		</div>
		<button id="chat-close">×</button>
	</div>

	<div id="chat-sub-header"></div>

	<!-- BODY -->
	<div class="chat-body" id="chat-messages"></div>

	<!-- FOOTER -->
	<?php if ( ! $is_admin ) : ?>
		<div class="chat-footer">
			<input type="text" id="chat-input" placeholder="<?php echo esc_attr__( 'Type message...', 'nexora' ); ?>">
			<button id="chat-send"><?php esc_html_e( 'Send', 'nexora' ); ?></button>
		</div>
	<?php endif; ?>

</div>