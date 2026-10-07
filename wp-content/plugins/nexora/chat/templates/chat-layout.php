<?php

$user_id = get_current_user_id();
$is_admin = current_user_can('manage_options');
?>

<div id="nexora-chat-modal" style="display:none;">

    <div class="chat-overlay"></div>

    <div class="chat-container">

        <?php if (!$is_admin): ?>
            <?php include NEXORA_PATH . 'chat/templates/chat-sidebar.php'; ?>
        <?php endif; ?>

        <?php include NEXORA_PATH . 'chat/templates/chat-box.php'; ?>

    </div>

</div>