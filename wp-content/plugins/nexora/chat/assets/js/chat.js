/* ===============================
   NEXORA CHAT — chat.js
   Fixes applied:
   - All DOM insertions use textContent / escapeHtml() — no raw innerHTML with user data
   - Auto-refresh only fires when modal is open AND a thread is selected
   - closeChat() only reloads when NOT in admin context
   - Dead/commented-out code removed
   - Footer is written once per openChat(); not re-injected redundantly
   - Proper validation before every AJAX call
   - Enter key sends message
   - Load-older messages (cursor pagination)
=============================== */

/* ===============================
   UTILS
=============================== */

/**
 * Escape a string for safe insertion into HTML.
 * The server already esc_html()s everything, but we double-escape client-side
 * for defence-in-depth.
 */
function escapeHtml( str ) {
    if ( str == null ) return '';
    return String( str )
        .replace( /&/g,  '&amp;'  )
        .replace( /</g,  '&lt;'   )
        .replace( />/g,  '&gt;'   )
        .replace( /"/g,  '&quot;' )
        .replace( /'/g,  '&#039;' );
}

/* ===============================
   GLOBAL STATE
=============================== */
let currentThread             = null;
let currentUserContext        = null;
let currentChatUserName       = '';
let currentChatPairName       = '';
let oldestLoadedMessageId     = null;  // for "load older" pagination
let isChatModalOpen           = false;

/* ===============================
   SEARCH SYSTEM
=============================== */
jQuery(document).on('focus', '#chat-search', function () {
    doUserSearch('');
});

jQuery(document).on('keyup', '#chat-search', function () {
    doUserSearch( jQuery(this).val() );
});

function doUserSearch( keyword ) {
    jQuery.post( nexoraChat.ajax_url, {
        action:  'nexora_search_users',
        keyword: keyword,
        nonce:   nexoraChat.nonce
    }, function ( res ) {
        if ( res.success ) renderSearchList( res.data );
    });
}

function renderSearchList( users ) {
    const $list = jQuery('#chat-search-results').empty();

    if ( ! users || ! users.length ) return;

    users.forEach( function ( user ) {
        // Build element without innerHTML to avoid XSS.
        const $div = jQuery('<div class="chat-user"></div>')
            .text( user.username )
            .attr({
                'data-user':          user.user_id,
                'data-connection-id': user.connection_id,
                'data-status':        user.status
            });
        $list.append( $div );
    });
}

jQuery(document).on('click', '.chat-user', function () {

    const userId       = jQuery(this).data('user');
    const username     = jQuery(this).text().trim();
    const connectionId = jQuery(this).data('connection-id');
    const status       = jQuery(this).data('status');

    if ( ! userId ) {
        console.error('User ID missing');
        return;
    }

    currentChatUserName                = username;
    currentUserContext                 = null;
    window.selectedUserForThread       = userId;
    window.selectedConnectionId        = connectionId;
    window.currentConnectionStatus     = status;

    updateChatHeader();

    jQuery.post( nexoraChat.ajax_url, {
        action:        'nexora_get_latest_thread_between_users',
        user_id:       userId,
        connection_id: connectionId,
        nonce:         nexoraChat.nonce
    }, function ( res ) {

        if ( res.success && res.data.thread_id ) {
            currentThread              = res.data.thread_id;
            window.currentThreadStatus = res.data.status;
            openChat( currentThread );
        } else {
            openChat( null );
        }
    });

    jQuery('#chat-search').val('');
    jQuery('#chat-search-results').empty();
});

/* ===============================
   OPEN / CLOSE CHAT
=============================== */
function openChat( threadId ) {

    currentThread          = threadId || null;
    oldestLoadedMessageId  = null;

    isChatModalOpen = true;
    jQuery('#nexora-chat-modal').fadeIn();
    updateChatHeader();

    // Reset UI slate.
    jQuery('#chat-messages').empty();
    jQuery('#chat-subject-area').empty();
    jQuery('#chat-sub-header').empty();

    /* --- Determine active / inactive once --- */
    const isInactive = threadId
        ? ( window.currentThreadStatus === 'inactive' )
        : ( window.currentConnectionStatus !== 'accepted' );

    /* --- Render footer once --- */
    renderChatFooter( isInactive );

    /* --- NEW THREAD --- */
    if ( ! threadId ) {

        if ( window.selectedUserForThread ) {
            renderSubjectUI( '', /* isNew = */ true );
        }

        setEmptyState( '💬', 'Start a Conversation', 'Enter a subject & send your first message' );
        return;
    }

    /* --- EXISTING THREAD --- */
    loadMessages();

    if ( ! isInactive ) {
        renderSubHeader( /* existingThread = */ true );
    }

    // Load subject separately (non-blocking).
    jQuery.post( nexoraChat.ajax_url, {
        action:    'nexora_get_thread_subject',
        thread_id: threadId,
        nonce:     nexoraChat.nonce
    }, function ( res ) {
        if ( res.success ) renderSubjectUI( res.data.subject );
    });
}

/**
 * Render the correct footer based on whether the thread is active.
 * Called once per openChat() so we never have duplicate inputs.
 */
function renderChatFooter( isInactive ) {

    const $footer = jQuery('.chat-footer');

    if ( isInactive ) {
        $footer.html(
            '<div class="chat-disabled-msg">' +
            '🚫 This conversation is no longer active.<br>' +
            'You can only view previous messages.' +
            '</div>'
        ).show();
    } else {
        $footer.html(
            '<input type="text" id="chat-input" placeholder="Type message…" maxlength="2000" />' +
            '<button id="chat-send">Send</button>'
        ).show();
    }
}

function closeChat() {
    isChatModalOpen = false;
    jQuery('#nexora-chat-modal').fadeOut();

    // Only reload on the front-end; admin views don't need it.
    if ( ! currentUserContext ) {
        setTimeout( function () { location.reload(); }, 200 );
    }
}

jQuery(document).on('click', '#chat-close, .chat-overlay', function () {
    closeChat();
});

/* ===============================
   LOAD MESSAGES
=============================== */
function loadMessages( beforeId ) {

    if ( ! currentThread ) return;

    const params = {
        action:    'nexora_get_messages',
        thread_id: currentThread,
        nonce:     nexoraChat.nonce
    };

    if ( beforeId ) params.before_id = beforeId;

    jQuery.post( nexoraChat.ajax_url, params, function ( res ) {

        if ( ! res.success || ! res.data ) return;

        const msgs = res.data;

        if ( ! msgs.length ) return;

        oldestLoadedMessageId = msgs[0].id;

        const $box    = jQuery('#chat-messages');
        const isFirst = ! beforeId;

        // Build a fragment to avoid reflow on every message.
        const fragment = document.createDocumentFragment();

        msgs.forEach( function ( msg ) {
            const compareId = currentUserContext || nexoraChat.user_id;
            const side      = ( String(msg.sender_id) === String(compareId) ) ? 'right' : 'left';

            const time = new Date( msg.created_at ).toLocaleTimeString( [], {
                hour:   '2-digit',
                minute: '2-digit'
            });

            const wrap = document.createElement('div');
            wrap.className = 'chat-msg ' + side;

            const textDiv = document.createElement('div');
            textDiv.className = 'chat-text';

            if ( currentUserContext && msg.sender_name ) {
                const nameDiv = document.createElement('div');
                nameDiv.className = 'chat-name';
                nameDiv.textContent = msg.sender_name;   // safe: textContent
                textDiv.appendChild( nameDiv );
            }

            // Message body — textContent so no XSS possible.
            const msgSpan = document.createElement('span');
            msgSpan.textContent = msg.message;
            textDiv.appendChild( msgSpan );

            const timeDiv = document.createElement('div');
            timeDiv.className   = 'chat-time';
            timeDiv.textContent = time;
            textDiv.appendChild( timeDiv );

            wrap.appendChild( textDiv );
            fragment.appendChild( wrap );
        });

        if ( beforeId ) {
            // Prepend older messages; preserve current scroll.
            const box       = document.getElementById('chat-messages');
            const prevHeight = box.scrollHeight;
            box.insertBefore( fragment, box.firstChild );
            box.scrollTop = box.scrollHeight - prevHeight;
        } else {
            $box.empty()[0].appendChild( fragment );
            const box = document.getElementById('chat-messages');
            box.scrollTop = box.scrollHeight;
        }

        // Show "load older" button if a full page came back.
        if ( msgs.length >= 30 ) {
            renderLoadOlderButton();
        } else {
            jQuery('#chat-load-older').remove();
        }
    });
}

function renderLoadOlderButton() {
    if ( jQuery('#chat-load-older').length ) return;
    const $btn = jQuery('<button id="chat-load-older">↑ Load older messages</button>');
    jQuery('#chat-messages').prepend( $btn );
}

jQuery(document).on('click', '#chat-load-older', function () {
    jQuery(this).remove();
    loadMessages( oldestLoadedMessageId );
});

/* ===============================
   SEND MESSAGE
=============================== */
jQuery(document).on('click', '#chat-send', function () {
    sendIfValid();
});

// Send on Enter (Shift+Enter = newline is not applicable here since it's <input>).
jQuery(document).on('keydown', '#chat-input', function ( e ) {
    if ( e.key === 'Enter' ) sendIfValid();
});

function sendIfValid() {
    const message = jQuery('#chat-input').val().trim();
    const subject = jQuery('#chat-subject').val();

    if ( ! message ) return;

    // NEW THREAD — need to create it first.
    if ( ! currentThread && window.selectedUserForThread ) {

        if ( ! subject ) {
            alert('Please enter a subject before sending.');
            return;
        }

        jQuery.post( nexoraChat.ajax_url, {
            action:        'nexora_create_thread_with_subject',
            user_id:       window.selectedUserForThread,
            connection_id: window.selectedConnectionId,
            subject:       subject,
            nonce:         nexoraChat.nonce
        }, function ( res ) {
            if ( res.success ) {
                currentThread = res.data.thread_id;
                renderSubjectUI( subject );
                sendMessageNow( message );
                loadUserThreads();
            }
        });

    } else {
        sendMessageNow( message );
    }
}

function sendMessageNow( message ) {

    // Disable button while sending to prevent double-send.
    jQuery('#chat-send').prop('disabled', true);

    jQuery.post( nexoraChat.ajax_url, {
        action:    'nexora_send_message',
        thread_id: currentThread,
        message:   message,
        nonce:     nexoraChat.nonce
    }, function ( res ) {

        jQuery('#chat-send').prop('disabled', false);

        if ( res.success ) {
            jQuery('#chat-input').val('');
            loadMessages();
        } else {
            alert( res.data && res.data.message ? res.data.message : 'Failed to send message.' );
        }
    });
}

/* ===============================
   THREAD LIST (SIDEBAR)
=============================== */
function loadUserThreads() {

    jQuery.post( nexoraChat.ajax_url, {
        action: 'nexora_get_user_threads',
        nonce:  nexoraChat.nonce
    }, function ( res ) {

        if ( ! res.success ) return;

        let activeHTML   = '';
        let inactiveHTML = '';

        res.data.forEach( function ( thread ) {

            const subject      = escapeHtml( thread.subject      || 'No subject yet' );
            const last_message = escapeHtml( thread.last_message || 'No messages yet' );
            const name         = escapeHtml( thread.name );

            const badge = thread.unread_count > 0
                ? '<span class="chat-badge">' + thread.unread_count + '</span>'
                : '';

            const time = new Date( thread.updated_at ).toLocaleString( [], {
                day:    '2-digit',
                month:  'short',
                hour:   '2-digit',
                minute: '2-digit'
            });

            const item = '<div class="chat-thread"' +
                ' data-thread="'        + escapeHtml( thread.id )            + '"' +
                ' data-user="'          + escapeHtml( thread.other_user_id ) + '"' +
                ' data-connection-id="' + escapeHtml( thread.connection_id ) + '"' +
                ' data-status="'        + escapeHtml( thread.status )        + '">' +
                    '<div class="chat-thread-name">' + name + badge + '</div>' +
                    '<div class="chat-thread-last">' + subject +
                        '<div class="chat-thread-time">' + last_message + ' · ' + time + '</div>' +
                    '</div>' +
                '</div>';

            if ( thread.status === 'active' ) {
                activeHTML += item;
            } else {
                inactiveHTML += item;
            }
        });

        const html =
            '<div class="chat-section-title">Active Chats</div>'   + activeHTML +
            '<div class="chat-section-title">Past Conversations</div>' + inactiveHTML;

        jQuery('#chat-thread-list').html( html );
    });
}

// Click thread in sidebar.
jQuery(document).on('click', '.chat-thread', function () {

    const name         = jQuery(this).find('.chat-thread-name').text().trim();
    const threadId     = jQuery(this).data('thread');
    const userId       = jQuery(this).data('user');
    const status       = jQuery(this).data('status');
    const connectionId = jQuery(this).data('connection-id');

    currentChatUserName            = name;
    currentUserContext             = null;

    window.selectedUserForThread   = userId;
    window.currentThreadStatus     = status;
    window.selectedConnectionId    = connectionId;

    updateChatHeader();
    openChat( threadId );
});

/* ===============================
   CHAT HEADER
=============================== */
function updateChatHeader() {
    const title = currentUserContext
        ? 'Chat Between ' + escapeHtml( currentChatPairName )
        : ( currentChatUserName || 'Select Chat' );
    jQuery('#chat-title').text( title );
}

/* ===============================
   SUBJECT UI
=============================== */
function renderSubjectUI( subject, isNew ) {

    let html = '';

    if ( isNew ) {
        html = '<div class="chat-subject-input-wrap">' +
               '<input type="text" id="chat-subject" placeholder="Enter subject…" maxlength="200" />' +
               '</div>';
    } else if ( ! subject ) {
        html = '<div class="chat-subject-input-wrap">' +
               '<input type="text" id="chat-subject" placeholder="Add subject…" maxlength="200" />' +
               '<button id="save-subject">Save</button>' +
               '</div>';
    } else {
        html = '<div class="chat-subject-text">' + escapeHtml( subject ) + '</div>';
    }

    jQuery('#chat-subject-area').html( html );
}

jQuery(document).on('click', '#save-subject', function () {

    const subject = jQuery('#chat-subject').val().trim();
    if ( ! subject || ! currentThread ) return;

    jQuery.post( nexoraChat.ajax_url, {
        action:    'nexora_update_subject',
        thread_id: currentThread,
        subject:   subject,
        nonce:     nexoraChat.nonce
    }, function ( res ) {
        if ( res.success ) renderSubjectUI( subject );
    });
});

/* ===============================
   SUB HEADER — "Start New Conversation"
=============================== */
function renderSubHeader( existingThread ) {

    if ( existingThread && ! currentUserContext ) {
        jQuery('#chat-sub-header').html(
            '<div class="chat-sub-header">' +
            '<button id="start-new-chat">+ Start New Conversation</button>' +
            '</div>'
        );
    } else {
        jQuery('#chat-sub-header').empty();
    }
}

jQuery(document).on('click', '#start-new-chat', function () {

    if ( ! window.selectedUserForThread ) {
        alert('No user selected. Please select a user first.');
        return;
    }

    if ( ! window.selectedConnectionId ) {
        alert('Connection missing. Please select a user again.');
        return;
    }

    currentThread         = null;
    oldestLoadedMessageId = null;

    renderSubjectUI( '', /* isNew = */ true );
    setEmptyState( '🚀', 'New Conversation', 'Enter a subject & send your first message' );
});

/* ===============================
   EMPTY STATE HELPER
=============================== */
function setEmptyState( icon, title, sub ) {
    jQuery('#chat-messages').html(
        '<div class="chat-empty-state">' +
        '<div class="chat-empty-icon">'  + icon  + '</div>' +
        '<div class="chat-empty-title">' + escapeHtml(title) + '</div>' +
        '<div class="chat-empty-sub">'   + escapeHtml(sub)   + '</div>' +
        '</div>'
    );
}

/* ===============================
   AUTO REFRESH (only when open)
=============================== */
setInterval( function () {
    if ( isChatModalOpen && currentThread && ! currentUserContext ) {
        loadMessages();
    }
}, 4000 );

/* ===============================
   ADMIN: OPEN CHAT
=============================== */
jQuery(document).on('click', '.nexora-open-chat', function () {
    const threadId = jQuery(this).data('thread');
    const userId   = jQuery(this).data('user');
    const name     = jQuery(this).data('name');
    openAdminChat( threadId, userId, name );
});

function openAdminChat( threadId, userId, name ) {

    if ( ! threadId ) return;

    currentThread       = threadId;
    currentUserContext  = userId;
    currentChatPairName = name;
    isChatModalOpen     = true;

    jQuery('#nexora-chat-modal').fadeIn();
    jQuery('#chat-title').text( 'Chat Between ' + name );
    jQuery('#chat-subject-area').empty();
    jQuery('#chat-sub-header').empty();
    jQuery('.chat-footer').hide();
    jQuery('#chat-messages').html('<div class="chat-loading">Loading…</div>');

    jQuery.post( nexoraChat.ajax_url, {
        action:    'nexora_get_messages',
        thread_id: threadId,
        nonce:     nexoraChat.nonce
    }, function ( res ) {

        if ( ! res.success ) return;

        const fragment = document.createDocumentFragment();

        res.data.forEach( function ( msg ) {

            // In admin view, userId is the "left" user (the non-current viewer).
            const side = ( String(msg.sender_id) === String(userId) ) ? 'left' : 'right';
            const time = new Date( msg.created_at ).toLocaleTimeString( [], {
                hour:   '2-digit',
                minute: '2-digit'
            });

            const wrap = document.createElement('div');
            wrap.className = 'chat-msg ' + side;

            const textDiv = document.createElement('div');
            textDiv.className = 'chat-text';

            const nameDiv = document.createElement('div');
            nameDiv.className   = 'chat-name';
            nameDiv.textContent = msg.sender_name || '';
            textDiv.appendChild( nameDiv );

            const msgSpan = document.createElement('span');
            msgSpan.textContent = msg.message;
            textDiv.appendChild( msgSpan );

            const timeDiv = document.createElement('div');
            timeDiv.className   = 'chat-time';
            timeDiv.textContent = time;
            textDiv.appendChild( timeDiv );

            wrap.appendChild( textDiv );
            fragment.appendChild( wrap );
        });

        const box = document.getElementById('chat-messages');
        box.innerHTML = '';
        box.appendChild( fragment );
        box.scrollTop = box.scrollHeight;
    });
}

/* ===============================
   USER: OPEN CHAT SYSTEM
=============================== */
jQuery(document).on('click', '[data-type="chat"]', function () {

    // Reset all state.
    currentThread                  = null;
    currentUserContext             = null;
    oldestLoadedMessageId          = null;
    window.selectedUserForThread   = null;
    window.selectedConnectionId    = null;
    window.currentThreadStatus     = null;
    window.currentConnectionStatus = null;
    currentChatUserName            = '';
    currentChatPairName            = '';
    isChatModalOpen                = true;

    updateChatHeader();

    jQuery('#nexora-chat-modal').fadeIn();

    loadUserThreads();

    setEmptyState( '💬', 'Start a Conversation', 'Search for a user or select a chat' );
    jQuery('#chat-subject-area').empty();
    jQuery('#chat-sub-header').empty();
    jQuery('.chat-footer').empty().hide();
});
