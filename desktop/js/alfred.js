/* Alfred — Phase 1 skeleton
 * Full implementation in Phase 4 (agent loop) + Phase 5 (SSE streaming)
 */
'use strict';

$(function () {

    // ---- Sidebar toggle (mobile) ----
    $('#alfred-sidebar-toggle').on('click', function () {
        $('#alfred-sidebar').toggleClass('open');
    });

    // Close sidebar when clicking outside on mobile
    $('#alfred-main').on('click', function () {
        if ($(window).width() < 768) {
            $('#alfred-sidebar').removeClass('open');
        }
    });

    // ---- Auto-resize textarea ----
    $('#alfred-input').on('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });

    // ---- Send on Enter (Shift+Enter = newline) ----
    $('#alfred-input').on('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            $('#alfred-send').trigger('click');
        }
    });

    // ---- Send message (stub — wired in Phase 5) ----
    $('#alfred-send').on('click', function () {
        var text = $('#alfred-input').val().trim();
        if (!text) return;

        appendMessage('user', text);
        $('#alfred-input').val('').css('height', 'auto');
        showTyping();

        // TODO Phase 5: open SSE stream to api/chat.php
        setTimeout(function () {
            hideTyping();
            appendMessage('assistant', 'Alfred is not yet connected — coming in Phase 5!');
        }, 800);
    });

    // ---- New chat ----
    $('#alfred-new-chat').on('click', function () {
        clearMessages();
        $('#alfred-conversations .alfred-session-item').removeClass('active');
        $('#alfred-sidebar').removeClass('open');
    });

    // =========================================================================
    // Helpers
    // =========================================================================

    function clearMessages() {
        $('#alfred-messages').empty().append(
            '<div id="alfred-welcome">' +
            '  <div class="alfred-welcome-icon"><i class="fas fa-robot"></i></div>' +
            '  <h2>' + (alfred_i18n['Hello, I\'m Alfred.'] || "Hello, I'm Alfred.") + '</h2>' +
            '  <p>' + (alfred_i18n['Ask me anything about your home automation system.'] || 'Ask me anything about your home automation system.') + '</p>' +
            '</div>'
        );
    }

    function appendMessage(role, text) {
        // Remove welcome screen on first message
        $('#alfred-welcome').remove();

        var $bubble = $('<div class="alfred-msg ' + role + '">')
            .append($('<div class="alfred-msg-bubble">').text(text));
        $('#alfred-messages').append($bubble);
        scrollToBottom();
    }

    function showTyping() {
        $('#alfred-messages').append(
            '<div class="alfred-typing" id="alfred-typing-indicator">' +
            '<span></span><span></span><span></span></div>'
        );
        scrollToBottom();
    }

    function hideTyping() {
        $('#alfred-typing-indicator').remove();
    }

    function scrollToBottom() {
        var $msgs = $('#alfred-messages');
        $msgs.scrollTop($msgs[0].scrollHeight);
    }
});

// i18n strings injected by PHP (populated in Phase 6)
var alfred_i18n = alfred_i18n || {};
