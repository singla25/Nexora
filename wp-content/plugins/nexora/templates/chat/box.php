<?php
$is_admin = current_user_can('manage_options');
?>

<div class="chat-box">

    <!-- HEADER -->
    <div class="chat-header">
        <div class="chat-header-left">
            <div class="chat-avatar"></div>
            <div>
                <div id="chat-title">Select Chat</div>

                <div id="chat-subject-area"></div>
            </div>
        </div>
        <button id="chat-close">×</button>
    </div>

    <div id="chat-sub-header"></div>

    <!-- BODY -->
    <div class="chat-body" id="chat-messages"></div>

    <!-- FOOTER -->
    <?php if (!$is_admin): ?>
        <div class="chat-footer">
            <input type="text" id="chat-input" placeholder="Type message...">
            <button id="chat-send">Send</button>
        </div>
    <?php endif; ?>

</div>