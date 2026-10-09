<?php

$user_id  = get_current_user_id();
$is_admin = current_user_can( 'manage_options' );
?>

<div id="nexora-chat-modal" style="display:none;">

	<div class="chat-overlay"></div>

	<div class="chat-container">

		<?php if ( ! $is_admin ) : ?>
			<?php \Nexora\Core\View::output( 'chat/sidebar' ); ?>
		<?php endif; ?>

		<?php \Nexora\Core\View::output( 'chat/box' ); ?>

	</div>

</div>