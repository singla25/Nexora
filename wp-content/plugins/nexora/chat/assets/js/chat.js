/* ===============================
   GLOBAL STATE
=============================== */
let currentThread = null;
let currentUserContext = null;

let currentChatUserName = '';
let currentChatPairName = '';

/* ===============================
   OPEN / CLOSE CHAT
=============================== */
function openChat(threadId = null) {

    currentThread = threadId;

    jQuery('#nexora-chat-modal').fadeIn();
    updateChatHeader();

    // NEW CHAT
    if (!threadId) {

        if (window.selectedUserForThread) {
            renderSubjectUI('', true);
        } else {
            jQuery('#chat-subject-area').html('');
        }

        jQuery('#chat-sub-header').html('');

        jQuery('#chat-messages').html(`
            <div class="chat-empty-state">
                <div class="chat-empty-icon">💬</div>
                <div class="chat-empty-title">Start a Conversation</div>
                <div class="chat-empty-sub">Select a user and begin chatting</div>
            </div>
        `);

        return;
    }

    // EXISTING CHAT
    loadMessages();
    renderSubHeader(true);   // Sub header

    jQuery.post(nexoraChat.ajax_url, {
        action: 'nexora_get_thread_subject',
        thread_id: threadId,
        nonce: nexoraChat.nonce
    }, function(res){
        if (res.success) {
            renderSubjectUI(res.data.subject);
        }
    });
}

function closeChat() {
    jQuery('#nexora-chat-modal').fadeOut();

    // ✅ RELOAD PAGE AFTER CLOSE
    setTimeout(() => {
        location.reload();
    }, 200);
}

jQuery(document).on('click', '#chat-close, .chat-overlay', function () {
    closeChat();
});

/* ===============================
   LOAD MESSAGES
=============================== */
function loadMessages() {

    if (!currentThread) return;

    jQuery.post(nexoraChat.ajax_url, {
        action: 'nexora_get_messages',
        thread_id: currentThread,
        nonce: nexoraChat.nonce
    }, function (res) {

        if (!res.success) return;

        let html = '';

        res.data.forEach(msg => {

            let compareId = currentUserContext 
                ? currentUserContext 
                : nexoraChat.user_id;

            let side = (msg.sender_id == compareId) ? 'right' : 'left';

            let time = new Date(msg.created_at).toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit'
            });

            let name = currentUserContext && msg.sender_name
                ? `<div class="chat-name">${msg.sender_name}</div>`
                : '';

            html += `
                <div class="chat-msg ${side}">
                    <div class="chat-text">
                        ${name}
                        ${msg.message}
                        <div class="chat-time">${time}</div>
                    </div>
                </div>
            `;
        });

        jQuery('#chat-messages').html(html);

        let box = document.getElementById('chat-messages');
        box.scrollTop = box.scrollHeight;
    });
}

/* ===============================
   SEND MESSAGE FLOW
=============================== */
jQuery(document).on('click', '#chat-send', function(){

    let message = jQuery('#chat-input').val();
    let subject = jQuery('#chat-subject').val();

    if (!message) return;

    // NEW THREAD
    if (!currentThread && window.selectedUserForThread) {

        if (!subject) {
            alert('Please enter subject');
            return;
        }

        jQuery.post(nexoraChat.ajax_url, {
            action: 'nexora_create_thread_with_subject',
            user_id: window.selectedUserForThread,
            subject: subject,
            nonce: nexoraChat.nonce
        }, function(res){

            if (res.success) {

                currentThread = res.data.thread_id;

                renderSubjectUI(subject);
                sendMessageNow(message);
                loadUserThreads();
            }
        });

    } else {
        sendMessageNow(message);
    }
});

function sendMessageNow(message) {

    jQuery.post(nexoraChat.ajax_url, {
        action: 'nexora_send_message',
        thread_id: currentThread,
        message: message,
        nonce: nexoraChat.nonce
    }, function(res){

        if (res.success) {
            jQuery('#chat-input').val('');
            loadMessages();
        }
    });
}

/* ===============================
   THREAD LIST
=============================== */
function loadUserThreads() {

    jQuery.post(nexoraChat.ajax_url, {
        action: 'nexora_get_user_threads',
        nonce: nexoraChat.nonce
    }, function(res){

        if (!res.success) return;

        let html = '';

        res.data.forEach(thread => {

            let subject = thread.subject || 'No subject';

            let badge = thread.unread_count > 0
                ? `<span class="chat-badge">${thread.unread_count}</span>`
                : '';

            html += `
                <div class="chat-thread" data-thread="${thread.id}" data-user="${thread.other_user_id}">
                    <div class="chat-thread-name">${thread.name}</div>
                    <div class="chat-thread-badge">${badge}</div>
                    <div class="chat-thread-last">${subject}</div>
                </div>
            `;
        });

        jQuery('#chat-thread-list').html(html);
    });
}

/* ===============================
   THREAD CLICK
=============================== */
jQuery(document).on('click', '.chat-thread', function(){

    jQuery('.chat-thread').removeClass('active');
    jQuery(this).addClass('active');

    let name = jQuery(this).find('.chat-thread-name').text();
    let threadId = jQuery(this).data('thread');
    let userId   = jQuery(this).data('user');

    if (!userId) {
        console.error("User ID missing in thread");
    }

    currentChatUserName = name;
    currentUserContext = null;

    window.selectedUserForThread = userId;

    updateChatHeader();
    openChat(threadId);
    // loadUserThreads();
});

/* ===============================
   SEARCH SYSTEM
=============================== */
jQuery(document).on('focus', '#chat-search', function(){

    jQuery.post(nexoraChat.ajax_url, {
        action: 'nexora_search_users',
        keyword: '',
        nonce: nexoraChat.nonce
    }, res => renderSearchList(res.data));
});

jQuery(document).on('keyup', '#chat-search', function(){

    let val = jQuery(this).val();

    jQuery.post(nexoraChat.ajax_url, {
        action: 'nexora_search_users',
        keyword: val,
        nonce: nexoraChat.nonce
    }, res => renderSearchList(res.data));
});

function renderSearchList(users){

    let html = '';

    users.forEach(user => {
        html += `<div class="chat-user" data-user="${user.user_id}">${user.username}</div>`;
    });

    jQuery('#chat-search-results').html(html);
}

jQuery(document).on('click', '.chat-user', function(){

    let userId = jQuery(this).data('user');
    let username = jQuery(this).text();

    if (!userId) {
        console.error("User ID missing in thread");
    }

    currentChatUserName = username;
    currentUserContext = null;

    updateChatHeader();

    jQuery.post(nexoraChat.ajax_url, {
        action: 'nexora_get_latest_thread_between_users',
        user_id: userId,
        nonce: nexoraChat.nonce
    }, function(res){

        if (res.success && res.data.thread_id) {

            currentThread = res.data.thread_id;
            window.selectedUserForThread = userId;

            openChat(currentThread);

        } else {

            window.selectedUserForThread = userId;
            openChat(null);
        }
    });

    jQuery('#chat-search').val('');
    jQuery('#chat-search-results').html('');
});

/* ===============================
   HEADER
=============================== */
function updateChatHeader() {

    let title = currentUserContext
        ? `Chat Between ${currentChatPairName}`
        : (currentChatUserName || 'Select Chat');

    jQuery('#chat-title').text(title);
}

/* ===============================
   SUBJECT UI
=============================== */
function renderSubjectUI(subject = '', isNew = false) {

    let html = '';

    if (isNew) {

        html = `
            <div class="chat-subject-input-wrap">
                <input type="text" id="chat-subject" placeholder="Enter subject..." />
            </div>
        `;
    }
    else if (!subject) {

        html = `
            <div class="chat-subject-input-wrap">
                <input type="text" id="chat-subject" placeholder="Add subject..." />
                <button id="save-subject">Save</button>
            </div>
        `;
    }
    else {

        html = `
            <div class="chat-subject-text">${subject}</div>
        `;
    }

    jQuery('#chat-subject-area').html(html);
}

/* ===============================
   SAVE SUBJECT
=============================== */
jQuery(document).on('click', '#save-subject', function(){

    let subject = jQuery('#chat-subject').val();
    if (!subject) return;

    jQuery.post(nexoraChat.ajax_url, {
        action: 'nexora_update_subject',
        thread_id: currentThread,
        subject: subject,
        nonce: nexoraChat.nonce
    }, function(res){
        if (res.success) renderSubjectUI(subject);
    });
});

/* ===============================
   SUB HEADER (USER ONLY)
=============================== */
function renderSubHeader(existingThread = false){

    if (existingThread && !currentUserContext) {
        jQuery('#chat-sub-header').html(`
            <div class="chat-sub-header">
                <button id="start-new-chat">+ Start New Conversation</button>
            </div>
        `);
    } else {
        jQuery('#chat-sub-header').html('');
    }
}

jQuery(document).on('click', '#start-new-chat', function(){

    if (!window.selectedUserForThread) {
        alert('User not selected');
        return;
    }

    currentThread = null;

    renderSubjectUI('', true);

    jQuery('#chat-messages').html(`
        <div class="chat-empty-state">
            <div class="chat-empty-icon">🚀</div>
            <div class="chat-empty-title">New Conversation</div>
            <div class="chat-empty-sub">Enter subject & send message</div>
        </div>
    `);

    console.log("Thread:", currentThread);
    console.log("Selected User:", window.selectedUserForThread);
});

/* ===============================
   AUTO REFRESH
=============================== */
setInterval(() => {
    if (currentThread) loadMessages();
}, 3000);

/* ===============================
   ADMIN: OPEN CHAT 
=============================== */
jQuery(document).on('click', '.nexora-open-chat', function () {

    let threadId = jQuery(this).data('thread');
    let userId = jQuery(this).data('user');
    let name     = jQuery(this).data('name');

    if (!userId) {
        console.error("User ID missing in thread");
    }

    currentUserContext = userId;
    currentChatPairName = name;

    updateChatHeader();

    openChat(threadId);
});

/* ===============================
   USER: OPEN CHAT SYSTEM
=============================== */
jQuery(document).on('click', '[data-type="chat"]', function(){

    currentUserContext = null;

    openChat();          // open empty
    loadUserThreads();   // load sidebar
});