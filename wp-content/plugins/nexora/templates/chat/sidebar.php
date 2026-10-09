<?php /** Chat sidebar: search and thread list (filled by chat.js). */ ?>
<div class="chat-sidebar">

	<!-- HEADER -->
	<div class="chat-sidebar-header">
		<h3><?php esc_html_e( 'Chats', 'nexora' ); ?></h3>
	</div>

	<!-- SEARCH -->
	<div class="chat-search-box">
		<input type="text" id="chat-search" placeholder="<?php echo esc_attr__( 'Search users...', 'nexora' ); ?>">
		<div id="chat-search-results"></div>
	</div>

	<!-- THREAD LIST -->
	<div id="chat-thread-list" class="chat-thread-list">
		<!-- AJAX -->
	</div>

</div>