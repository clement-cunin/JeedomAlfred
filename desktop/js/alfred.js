'use strict';

/* global $, alfred_config */
/* alfred_config is injected by desktop/php/alfred.php */

$(function () {

    // =========================================================================
    // State
    // =========================================================================

    var currentSessionId = null;
    var isStreaming      = false;
    var currentSource    = null; // active EventSource

    // =========================================================================
    // Sidebar toggle (mobile)
    // =========================================================================

    $('#alfred-sidebar-toggle').on('click', function (e) {
        e.stopPropagation();
        $('#alfred-sidebar').toggleClass('open');
    });

    $('#alfred-main').on('click', function () {
        if ($(window).width() < 768) {
            $('#alfred-sidebar').removeClass('open');
        }
    });

    // =========================================================================
    // New conversation
    // =========================================================================

    $('#alfred-new-chat').on('click', function () {
        startNewSession();
        $('#alfred-sidebar').removeClass('open');
    });

    function startNewSession() {
        currentSessionId = generateUUID();
        renderWelcome();
        setInputEnabled(true);
        $('.alfred-session-item').removeClass('active');
    }

    // =========================================================================
    // Load session list
    // =========================================================================

    function loadSessions() {
        $.ajax({
            type: 'POST',
            url: 'plugins/alfred/core/ajax/alfred.ajax.php',
            data: { action: 'getSessions' },
            dataType: 'json',
            success: function (resp) {
                if (resp.state !== 'ok') return;
                renderSessionList(resp.result);
            }
        });
    }

    function renderSessionList(sessions) {
        var $container = $('#alfred-conversations').empty();
        if (!sessions || sessions.length === 0) return;

        sessions.forEach(function (s) {
            var $item = $('<div class="alfred-session-item">')
                .attr('data-session-id', s.session_id)
                .text(s.title || s.session_id.substr(0, 8) + '…')
                .on('click', function () {
                    loadSession(s.session_id);
                    $('#alfred-sidebar').removeClass('open');
                });
            if (s.session_id === currentSessionId) {
                $item.addClass('active');
            }
            $container.append($item);
        });
    }

    function loadSession(sessionId) {
        currentSessionId = sessionId;
        $('.alfred-session-item').removeClass('active');
        $('[data-session-id="' + sessionId + '"]').addClass('active');

        $.ajax({
            type: 'POST',
            url: 'plugins/alfred/core/ajax/alfred.ajax.php',
            data: { action: 'getMessages', session_id: sessionId },
            dataType: 'json',
            success: function (resp) {
                if (resp.state !== 'ok') return;
                renderHistory(resp.result);
                setInputEnabled(true);
            }
        });
    }

    function renderHistory(messages) {
        $('#alfred-messages').empty();
        messages.forEach(function (msg) {
            if (msg.role === 'user') {
                appendBubble('user', msg.content);
            } else if (msg.role === 'assistant' && msg.content !== '') {
                appendBubble('assistant', msg.content);
            } else if (msg.role === 'tool') {
                // skip tool results in history display
            }
        });
        scrollToBottom();
    }

    // =========================================================================
    // Input & send
    // =========================================================================

    $('#alfred-input').on('input', function () {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 120) + 'px';
    });

    $('#alfred-input').on('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (!isStreaming) $('#alfred-send').trigger('click');
        }
    });

    $('#alfred-send').on('click', function () {
        if (isStreaming) return;
        var text = $('#alfred-input').val().trim();
        if (!text) return;

        if (!currentSessionId) {
            currentSessionId = generateUUID();
        }

        $('#alfred-input').val('').css('height', 'auto');
        sendMessage(currentSessionId, text);
    });

    // =========================================================================
    // SSE streaming
    // =========================================================================

    function sendMessage(sessionId, text) {
        // Remove welcome screen
        $('#alfred-welcome').remove();

        appendBubble('user', text);
        showTyping();
        setInputEnabled(false);
        isStreaming = true;

        // Close any previous stream
        if (currentSource) { currentSource.close(); currentSource = null; }

        var url = 'plugins/alfred/api/chat.php'
            + '?session_id=' + encodeURIComponent(sessionId)
            + '&message='    + encodeURIComponent(text)
            + '&user_hash='  + encodeURIComponent(alfred_config.userHash)
            + '&_=' + Date.now();

        var source = new EventSource(url);
        currentSource = source;

        var $assistantBubble = null;
        var assistantText    = '';

        source.addEventListener('tool_call', function (e) {
            var d = JSON.parse(e.data);
            hideTyping();
            appendToolCall(d.name, 'running');
        });

        source.addEventListener('tool_result', function (e) {
            var d = JSON.parse(e.data);
            updateToolCall(d.name, 'done');
        });

        source.addEventListener('delta', function (e) {
            var d = JSON.parse(e.data);
            hideTyping();
            if (!$assistantBubble) {
                $assistantBubble = appendBubble('assistant', '');
            }
            assistantText += d.text;
            $assistantBubble.find('.alfred-msg-bubble').html(markdownToHtml(assistantText));
            scrollToBottom();
        });

        source.addEventListener('done', function (e) {
            var d = JSON.parse(e.data);
            hideTyping();
            // If delta never fired (tool-only turn with empty text), show the text now
            if (!$assistantBubble && d.text) {
                appendBubble('assistant', d.text);
            }
            source.close();
            currentSource = null;
            isStreaming   = false;
            setInputEnabled(true);
            // Refresh session list to show new/updated session
            loadSessions();
            // Mark session active
            $('.alfred-session-item').removeClass('active');
            $('[data-session-id="' + sessionId + '"]').addClass('active');
        });

        source.addEventListener('error', function (e) {
            hideTyping();
            var msg = 'An error occurred.';
            try { msg = JSON.parse(e.data).message; } catch (_) {}
            appendBubble('assistant', '⚠️ ' + msg);
            source.close();
            currentSource = null;
            isStreaming   = false;
            setInputEnabled(true);
        });

        // Network-level SSE error (connection dropped)
        source.onerror = function () {
            if (source.readyState === EventSource.CLOSED) {
                hideTyping();
                isStreaming = false;
                setInputEnabled(true);
                currentSource = null;
            }
        };
    }

    // =========================================================================
    // DOM helpers
    // =========================================================================

    function appendBubble(role, text) {
        var $bubble = $('<div class="alfred-msg-bubble">').html(markdownToHtml(text));
        var $msg    = $('<div class="alfred-msg ' + role + '">').append($bubble);
        $('#alfred-messages').append($msg);
        scrollToBottom();
        return $msg;
    }

    var _toolCallMap = {};

    function appendToolCall(name, status) {
        var icon = status === 'running' ? 'fa-spinner fa-spin' : 'fa-check';
        var $el = $('<div class="alfred-tool-call" data-tool="' + name + '">')
            .html('<i class="fas ' + icon + '"></i> <code>' + escHtml(name) + '</code>');
        _toolCallMap[name] = $el;
        $('#alfred-messages').append($el);
        scrollToBottom();
    }

    function updateToolCall(name, status) {
        var $el = _toolCallMap[name];
        if (!$el) return;
        var icon = status === 'done' ? 'fa-check text-success' : 'fa-times text-danger';
        $el.find('i').attr('class', 'fas ' + icon);
    }

    function showTyping() {
        if ($('#alfred-typing-indicator').length) return;
        $('#alfred-messages').append(
            '<div class="alfred-typing" id="alfred-typing-indicator">' +
            '<span></span><span></span><span></span></div>'
        );
        scrollToBottom();
    }

    function hideTyping() {
        $('#alfred-typing-indicator').remove();
    }

    function renderWelcome() {
        $('#alfred-messages').empty().append(
            '<div id="alfred-welcome">' +
            '<div class="alfred-welcome-icon"><i class="fas fa-robot"></i></div>' +
            '<h2>' + escHtml(alfred_config.i18n.hello) + '</h2>' +
            '<p>' + escHtml(alfred_config.i18n.ask) + '</p>' +
            '</div>'
        );
    }

    function setInputEnabled(enabled) {
        $('#alfred-input, #alfred-send').prop('disabled', !enabled);
    }

    function scrollToBottom() {
        var el = document.getElementById('alfred-messages');
        if (el) el.scrollTop = el.scrollHeight;
    }

    // =========================================================================
    // Minimal Markdown → HTML
    // =========================================================================

    function markdownToHtml(text) {
        if (!text) return '';
        // Escape first, then apply markdown
        var s = escHtml(text);
        // Code blocks ```...```
        s = s.replace(/```[\w]*\n?([\s\S]*?)```/g, '<pre><code>$1</code></pre>');
        // Inline code
        s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
        // Bold
        s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        // Italic
        s = s.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        // Headers (##, ###)
        s = s.replace(/^### (.+)$/gm, '<h5>$1</h5>');
        s = s.replace(/^## (.+)$/gm,  '<h4>$1</h4>');
        s = s.replace(/^# (.+)$/gm,   '<h3>$1</h3>');
        // Bullet lists
        s = s.replace(/^[-*] (.+)$/gm, '<li>$1</li>');
        s = s.replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>');
        // Line breaks
        s = s.replace(/\n/g, '<br>');
        return s;
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g,  '&amp;')
            .replace(/</g,  '&lt;')
            .replace(/>/g,  '&gt;')
            .replace(/"/g,  '&quot;')
            .replace(/'/g,  '&#39;');
    }

    // =========================================================================
    // UUID
    // =========================================================================

    function generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            var v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    // =========================================================================
    // Boot
    // =========================================================================

    loadSessions();
    startNewSession();
    setInputEnabled(!!alfred_config.isConfigured);
});
