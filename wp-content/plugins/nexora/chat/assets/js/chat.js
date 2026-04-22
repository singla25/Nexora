/* ===============================
   GLOBAL STATE
=============================== */
let currentThread = null;
let currentUserContext = null;

let currentChatUserName = '';
let currentChatPairName = '';

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
        html += `<div class="chat-user" 
                    data-user="${user.user_id}"
                    data-connection-id="${user.connection_id}"
                    data-status="${user.status}">
                    ${user.username}
                </div>`
        ;
    });

    jQuery('#chat-search-results').html(html);
}

jQuery(document).on('click', '.chat-user', function(){

    let userId = jQuery(this).data('user');
    let username = jQuery(this).text();
    let connectionId = jQuery(this).data('connection-id');
    let status = jQuery(this).data('status');

    if (!userId) {
        console.error("User ID missing in thread");
    }

    currentChatUserName = username;
    currentUserContext = null;

    updateChatHeader();

    jQuery.post(nexoraChat.ajax_url, {
        action: 'nexora_get_latest_thread_between_users',
        user_id: userId,
        connection_id: connectionId,
        nonce: nexoraChat.nonce
    }, function(res){

        if (res.success && res.data.thread_id) {

            currentThread = res.data.thread_id;

            window.currentThreadStatus = res.data.status; // ✅ FIX

            window.selectedUserForThread = userId;
            window.selectedConnectionId = connectionId;
            window.currentConnectionStatus = status;

            openChat(currentThread);

        } else {

            window.selectedUserForThread = userId;
            window.selectedConnectionId = connectionId;
            window.currentConnectionStatus = status;

            openChat(null);
        }

        // ✅ NOW THIS WILL WORK
        console.log("User:", window.selectedUserForThread);
        console.log("Connection:", window.selectedConnectionId);
        console.log("Status:", window.currentConnectionStatus);
    });

    jQuery('#chat-search').val('');
    jQuery('#chat-search-results').html('');
});

/* ===============================
   OPEN / CLOSE CHAT
=============================== */
function openChat(threadId = null) {

    currentThread = threadId;

    jQuery('#nexora-chat-modal').fadeIn();
    updateChatHeader();

    // 🧹 Always reset UI first
    jQuery('#chat-messages').html('');
    jQuery('#chat-subject-area').html('');
    jQuery('#chat-sub-header').html('');
    jQuery('.chat-footer').show();

    /* ===============================
       🟢 NEW CHAT
    =============================== */
    if (!threadId) {

        if (window.selectedUserForThread) {
            renderSubjectUI('', true);
        }

        jQuery('#chat-messages').html(`
            <div class="chat-empty-state">
                <div class="chat-empty-icon">💬</div>
                <div class="chat-empty-title">Start a Conversation</div>
                <div class="chat-empty-sub">Enter subject & send message</div>
            </div>
        `);

        // ✅ ADD THIS (IMPORTANT)
        if (window.currentConnectionStatus === 'accepted') {

            jQuery('.chat-footer').html(`
                <input type="text" id="chat-input" placeholder="Type message..." />
                <button id="chat-send">Send</button>
            `).show();

        } else {

            jQuery('.chat-footer').html(`
                <div class="chat-disabled-msg">
                    🚫 This conversation is no longer active.<br>
                    You can only view previous messages.
                </div>
            `).show();
        }

        return;
    }

    /* ===============================
       🔵 EXISTING CHAT
    =============================== */

    // Load messages
    loadMessages();

    let isInactive = false;

    if (threadId) {
        isInactive = (window.currentThreadStatus === 'inactive');
    } else {
        isInactive = (window.currentConnectionStatus !== 'accepted');
    }

    if (isInactive) {

        jQuery('.chat-footer').html(`
            <div class="chat-disabled-msg">
                🚫 This conversation is no longer active.<br>
                You can only view previous messages.
            </div>
        `);

        jQuery('#chat-sub-header').html('');

    } else {

        jQuery('.chat-footer').html(`
            <input type="text" id="chat-input" placeholder="Type message..." />
            <button id="chat-send">Send</button>
        `);

        renderSubHeader(true);
    }

    /* ===============================
       📌 LOAD SUBJECT
    =============================== */
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
            connection_id: window.selectedConnectionId,
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
    CHAT THREAD FLOW
=============================== */

// LOAD THREAD LIST IN SIDEBAR
function loadUserThreads() {

    jQuery.post(nexoraChat.ajax_url, {
        action: 'nexora_get_user_threads',
        nonce: nexoraChat.nonce
    }, function(res){

        if (!res.success) return;

        let html = '';

        // ✅ SORT BY LATEST UPDATED
        // res.data.sort((a, b) => {
        //     return new Date(b.updated_at) - new Date(a.updated_at);
        // });

        let activeHTML = '';
        let inactiveHTML = '';

        res.data.forEach(thread => {

            // let subject = thread.subject || 'No subject';
            let subject = thread.subject || 'No subject yet';

            let last_message = thread.last_message || 'No messages yet';

            let badge = thread.unread_count > 0
                ? `<span class="chat-badge">${thread.unread_count}</span>`
                : '';

            let time = new Date(thread.updated_at).toLocaleString([], {
                day: '2-digit',
                month: 'short',
                hour: '2-digit',
                minute: '2-digit'
            });

            let item = `
                <div class="chat-thread" 
                    data-thread="${thread.id}" 
                    data-user="${thread.other_user_id}" 
                    data-connection-id="${thread.connection_id}" 
                    data-status="${thread.status}">
                    
                    <div class="chat-thread-name">${thread.name}</div>
                    <div class="chat-thread-badge">${badge}</div>
                    <div class="chat-thread-last">
                        ${subject}
                        <div class="chat-thread-time">${last_message} : ${time}</div>
                    </div>
                </div>
            `;

            if (thread.status === 'active') {
                activeHTML += item;
            } else {
                inactiveHTML += item;
            }

            // html += `
            //     <div class="chat-thread" data-thread="${thread.id}" data-user="${thread.other_user_id}" data-connection-id="${thread.connection_id}" data-status="${thread.status}">
            //         <div class="chat-thread-name">${thread.name}</div>
            //         <div class="chat-thread-badge">${badge}</div>
            //         <div class="chat-thread-last">
            //             ${subject}
            //             <div class="chat-thread-time">${time}</div>
            //         </div>
            //     </div>
            // `;
        });

        let finalHTML = `
            <div class="chat-section-title">Active Chats</div>
            ${activeHTML}

            <div class="chat-section-title">Old Conversations</div>
            ${inactiveHTML}
        `;

        jQuery('#chat-thread-list').html(finalHTML);
        // jQuery('#chat-thread-list').html(html);
    });
}

// OPEN CHAT (THREAD)
jQuery(document).on('click', '.chat-thread', function(){

    let name = jQuery(this).find('.chat-thread-name').text();
    let threadId = jQuery(this).data('thread');
    let userId   = jQuery(this).data('user');
    let status   = jQuery(this).data('status');
    let connectionId = jQuery(this).data('connection-id'); // ✅

    currentChatUserName = name;
    currentUserContext = null;

    window.selectedUserForThread = userId;
    window.currentThreadStatus = status;
    window.selectedConnectionId = connectionId; // ✅ IMPORTANT

    console.log("Thread Click → Connection:", connectionId);

    updateChatHeader();
    openChat(threadId);
});


/* ===============================
   CHAT HEADER (Admin Side)
=============================== */
function updateChatHeader() {

    let title = currentUserContext
        ? `Chat Between ${currentChatPairName}`
        : (currentChatUserName || 'Select Chat');

    jQuery('#chat-title').text(title);
}

/* ===============================
   SUBJECT UI (USER SIDE)
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

/* ====================================================
   SUB HEADER - Start New Conversatation (USER ONLY)
==================================================== */
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

// START NEW CHAT (USER ONLY)
jQuery(document).on('click', '#start-new-chat', function(){

    if (!window.selectedUserForThread) {
        alert('User not selected');
        return;
    }

    // ENSURE CONNECTION DATA EXISTS
    if (!window.selectedConnectionId) {
        alert('Connection missing. Please select user again.');
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

    // ✅ DEBUG
    console.log("New Chat Context:");
    console.log("User:", window.selectedUserForThread);
    console.log("Connection:", window.selectedConnectionId);
    console.log("Status:", window.currentConnectionStatus);
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
    let userId   = jQuery(this).data('user');
    let name     = jQuery(this).data('name');

    openAdminChat(threadId, userId, name);
});

function openAdminChat(threadId, userId, name) {

    if (!threadId) return;

    // set admin context
    currentThread = threadId;
    currentUserContext = userId;
    currentChatPairName = name;

    // open modal
    jQuery('#nexora-chat-modal').fadeIn();

    // header
    jQuery('#chat-title').text('Chat Between ' + name);

    // ❌ REMOVE EVERYTHING EXTRA
    jQuery('#chat-subject-area').html('');
    jQuery('#chat-sub-header').html('');
    jQuery('.chat-footer').hide();

    // clean messages
    jQuery('#chat-messages').html('<div class="chat-loading">Loading...</div>');

    // load messages only
    jQuery.post(nexoraChat.ajax_url, {
        action: 'nexora_get_messages',
        thread_id: threadId,
        nonce: nexoraChat.nonce
    }, function (res) {

        if (!res.success) return;

        let html = '';

        res.data.forEach(msg => {

            let side = (msg.sender_id == userId) ? 'left' : 'right';

            let time = new Date(msg.created_at).toLocaleTimeString([], {
                hour: '2-digit',
                minute: '2-digit'
            });

            html += `
                <div class="chat-msg ${side}">
                    <div class="chat-text">
                        <div class="chat-name">${msg.sender_name || ''}</div>
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
   USER: OPEN CHAT SYSTEM
=============================== */
jQuery(document).on('click', '[data-type="chat"]', function(){

    // RESET STATE
    currentThread = null;
    currentUserContext = null;

    window.selectedUserForThread = null;
    window.selectedConnectionId = null;
    window.currentThreadStatus = null;
    window.currentConnectionStatus = null;

    currentChatUserName = '';
    currentChatPairName = '';

    updateChatHeader();

    // OPEN CHAT MODAL
    jQuery('#nexora-chat-modal').fadeIn();

    // LOAD SIDEBAR (IMPORTANT)
    loadUserThreads();

    // EMPTY STATE UI
    jQuery('#chat-messages').html(`
        <div class="chat-empty-state">
            <div class="chat-empty-icon">💬</div>
            <div class="chat-empty-title">Start a Conversation</div>
            <div class="chat-empty-sub">Enter subject & send message</div>
        </div>
    `);

    jQuery('#chat-subject-area').html('');
    jQuery('#chat-sub-header').html('');

    // hide footer until user selected
    jQuery('.chat-footer').html('').hide();
});