'use strict';

/* global $, alfred_config */
/* alfred_config is injected by desktop/php/alfred.php */

$(function () {

    // =========================================================================
    // State
    // =========================================================================

    var currentSessionId  = null;
    var isStreaming       = false;
    var currentSource     = null; // active EventSource
    var knownMsgCount     = 0;    // number of messages last rendered, for background poll

    // Voice dictation state
    var recognition     = null;
    var isListening     = false;
    var micAutoSend     = localStorage.getItem('alfred_mic_autosend') === '1';
    var micInitialText  = ''; // textarea content when mic started
    var micCommitted    = ''; // accumulated final transcripts

    // Text-to-speech state
    var ttsEnabled    = localStorage.getItem('alfred_tts') === '1';
    var ttsVoice      = null; // SpeechSynthesisVoice object
    var ttsRate       = parseFloat(localStorage.getItem('alfred_tts_rate') || '1');
    var ttsCurrentMsg = null; // $msg element currently playing/paused
    var ttsCurrentUtt = null; // current SpeechSynthesisUtterance

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
        knownMsgCount    = 0;
        localStorage.setItem('alfred_last_session', currentSessionId);
        renderWelcome();
        setInputEnabled(true);
        $('.alfred-session-item').removeClass('active');
    }

    // =========================================================================
    // Load session list
    // =========================================================================

    function loadSessions(callback) {
        $.ajax({
            type: 'POST',
            url: 'plugins/alfred/core/ajax/alfred.ajax.php',
            data: { action: 'getSessions' },
            dataType: 'json',
            success: function (resp) {
                if (resp.state === 'ok') renderSessionList(resp.result);
                if (callback) callback();
            },
            error: function () {
                if (callback) callback();
            }
        });
    }

    function renderSessionList(sessions) {
        var $container = $('#alfred-conversations').empty();
        if (!sessions || sessions.length === 0) {
            $container.append('<div class="alfred-sessions-empty">{{No conversations yet}}</div>');
            return;
        }

        sessions.forEach(function (s) {
            var $title = $('<span class="alfred-session-title">').text(s.title || s.session_id.substr(0, 8) + '…');
            var $del = $('<button class="alfred-session-delete" title="{{Delete}}">')
                .html('<i class="fas fa-trash"></i>')
                .on('click', function (e) {
                    e.stopPropagation();
                    deleteSession(s.session_id);
                });
            var $item = $('<div class="alfred-session-item">')
                .attr('data-session-id', s.session_id)
                .append($title)
                .append($del)
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

    function deleteSession(sessionId) {
        $.ajax({
            type: 'POST',
            url: 'plugins/alfred/core/ajax/alfred.ajax.php',
            data: { action: 'deleteSession', session_id: sessionId },
            dataType: 'json',
            success: function (resp) {
                if (resp.state !== 'ok') return;
                if (sessionId === currentSessionId) {
                    localStorage.removeItem('alfred_last_session');
                    startNewSession();
                }
                loadSessions();
            }
        });
    }

    function loadSession(sessionId) {
        currentSessionId = sessionId;
        localStorage.setItem('alfred_last_session', sessionId);
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
                setInputEnabled(!!alfred_config.isConfigured);
            }
        });
    }

    function renderHistory(messages) {
        knownMsgCount = messages.length;
        $('#alfred-messages').empty();
        var toolInputMap = {};
        messages.forEach(function (msg) {
            if (msg.role === 'assistant') {
                if (msg.tool_calls) {
                    msg.tool_calls.forEach(function (tc) {
                        toolInputMap[tc.id] = tc.input;
                    });
                }
                if (msg.content !== '') {
                    appendBubble('assistant', msg.content);
                }
            } else if (msg.role === 'user') {
                if (msg.content.indexOf('[SCHEDULED]') === 0) {
                    appendScheduledBubble(msg.content.replace('[SCHEDULED]', '').trim());
                } else {
                    appendBubble('user', msg.content);
                }
            } else if (msg.role === 'tool') {
                var input = toolInputMap[msg.tool_call_id];
                var result;
                try { result = JSON.parse(msg.content); } catch (e) { result = msg.content; }
                appendToolCall(msg.name, 'done', input, result);
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
        if (isListening) { isListening = false; recognition.stop(); }
        if (ttsCurrentMsg) {
            setMsgTtsState(ttsCurrentMsg, 'idle');
            ttsCurrentMsg = null;
            ttsCurrentUtt = null;
        }
        speechSynthesis.cancel();
        var text = $('#alfred-input').val().trim();
        if (!text) return;

        if (!currentSessionId) {
            currentSessionId = generateUUID();
        }

        $('#alfred-input').val('').css('height', 'auto');
        sendMessage(currentSessionId, text);
    });

    // =========================================================================
    // Text-to-speech
    // =========================================================================

    (function initTts() {
        if (!window.speechSynthesis) return;
        var $btn = $('#alfred-tts');
        $btn.toggleClass('active', ttsEnabled);

        $btn.on('click', function () {
            ttsEnabled = !ttsEnabled;
            localStorage.setItem('alfred_tts', ttsEnabled ? '1' : '0');
            $btn.toggleClass('active', ttsEnabled);
            if (!ttsEnabled) {
                if (ttsCurrentMsg) {
                    setMsgTtsState(ttsCurrentMsg, 'idle');
                    ttsCurrentMsg = null;
                    ttsCurrentUtt = null;
                }
                speechSynthesis.cancel();
            }
        });

        // Build settings popover
        var rateDisplay = isNaN(ttsRate) ? '1.0' : ttsRate.toFixed(1);
        var $popover = $('<div id="alfred-tts-popover">')
            .append('<span class="alfred-tts-label">{{Voice}}</span>')
            .append('<select id="alfred-tts-voice"></select>')
            .append('<span class="alfred-tts-label">{{Speed}}</span>')
            .append(
                $('<div class="alfred-tts-rate-row">')
                    .append('<input type="range" id="alfred-tts-rate" min="0.5" max="2" step="0.1" value="' + (isNaN(ttsRate) ? 1 : ttsRate) + '">')
                    .append('<span class="alfred-tts-rate-val" id="alfred-tts-rate-val">' + rateDisplay + 'x</span>')
            );
        $('#alfred-tts-wrap').append($popover);

        function populateVoices() {
            var voices = speechSynthesis.getVoices();
            if (!voices.length) return;
            // Voices are now available — remove handler to prevent Chrome from
            // re-triggering it on speak/cancel and resetting the user's selection.
            speechSynthesis.onvoiceschanged = null;
            var $select = $('#alfred-tts-voice').empty();
            var savedName = localStorage.getItem('alfred_tts_voice') || '';
            var lang = (navigator.language || 'fr-FR').split('-')[0];
            var autoSelected = false;
            for (var i = 0; i < voices.length; i++) {
                var v = voices[i];
                var $opt = $('<option>').val(v.name).text(v.name + ' (' + v.lang + ')');
                if (v.name === savedName) {
                    $opt.prop('selected', true);
                    ttsVoice = v;
                    autoSelected = true;
                }
                $select.append($opt);
            }
            if (!autoSelected) {
                for (var j = 0; j < voices.length; j++) {
                    if (voices[j].lang.indexOf(lang) === 0) {
                        $select.find('option').eq(j).prop('selected', true);
                        ttsVoice = voices[j];
                        break;
                    }
                }
            }
        }

        populateVoices();
        if (typeof speechSynthesis.onvoiceschanged !== 'undefined') {
            speechSynthesis.onvoiceschanged = populateVoices;
        }

        $('#alfred-tts-voice').on('change', function () {
            var name = $(this).val();
            localStorage.setItem('alfred_tts_voice', name);
            var voices = speechSynthesis.getVoices();
            ttsVoice = null;
            for (var i = 0; i < voices.length; i++) {
                if (voices[i].name === name) { ttsVoice = voices[i]; break; }
            }
        });

        $('#alfred-tts-rate').on('input', function () {
            ttsRate = parseFloat(this.value);
            localStorage.setItem('alfred_tts_rate', String(ttsRate));
            $('#alfred-tts-rate-val').text(ttsRate.toFixed(1) + 'x');
        });

        $('#alfred-tts-settings').on('click', function (e) {
            e.stopPropagation();
            $popover.toggleClass('open');
        });

        $(document).on('click.tts-popover', function (e) {
            if (!$(e.target).closest('#alfred-tts-wrap').length) {
                $popover.removeClass('open');
            }
        });
    }());

    function cleanTtsText(text) {
        return text
            .replace(/```[\s\S]*?```/g, '')
            .replace(/`[^`]*`/g, '')
            .replace(/\*\*([^*]+)\*\*/g, '$1')
            .replace(/\*([^*]+)\*/g, '$1')
            .replace(/#+\s*/g, '')
            .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
            .replace(/[_~>|]/g, '')
            .replace(/\n+/g, ' ')
            .trim();
    }

    function buildMsgUtterance(text, $msgEl) {
        var utt = new SpeechSynthesisUtterance(text);
        if (ttsVoice) {
            utt.voice = ttsVoice;
            utt.lang  = ttsVoice.lang;
        } else {
            utt.lang = navigator.language || 'fr-FR';
        }
        utt.rate = (ttsRate > 0) ? ttsRate : 1;
        utt.onend = function () {
            if (ttsCurrentMsg && ttsCurrentMsg[0] === $msgEl[0]) {
                setMsgTtsState($msgEl, 'idle');
                ttsCurrentMsg = null;
                ttsCurrentUtt = null;
            }
        };
        return utt;
    }

    function setMsgTtsState($msg, state) {
        $msg.find('.alfred-tts-play').toggleClass('hidden', state !== 'idle');
        $msg.find('.alfred-tts-pause').toggleClass('hidden', state !== 'playing');
        $msg.find('.alfred-tts-resume').toggleClass('hidden', state !== 'paused');
        $msg.find('.alfred-tts-stop').toggleClass('hidden', state !== 'paused');
    }

    function speakMsg($msg) {
        if (!window.speechSynthesis) return;
        if (ttsCurrentMsg && ttsCurrentMsg[0] !== $msg[0]) {
            setMsgTtsState(ttsCurrentMsg, 'idle');
        }
        speechSynthesis.cancel();
        var text = $msg.data('tts-text');
        if (!text) return;
        var clean = cleanTtsText(text);
        if (!clean) return;
        var utt = buildMsgUtterance(clean, $msg);
        ttsCurrentMsg = $msg;
        ttsCurrentUtt = utt;
        setMsgTtsState($msg, 'playing');
        speechSynthesis.speak(utt);
    }

    function pauseMsg($msg) {
        if (!ttsCurrentMsg || ttsCurrentMsg[0] !== $msg[0]) return;
        speechSynthesis.pause();
        setMsgTtsState($msg, 'paused');
    }

    function resumeMsg($msg) {
        if (!ttsCurrentMsg || ttsCurrentMsg[0] !== $msg[0]) return;
        speechSynthesis.resume();
        setMsgTtsState($msg, 'playing');
    }

    function stopMsg($msg) {
        if (!ttsCurrentMsg || ttsCurrentMsg[0] !== $msg[0]) return;
        speechSynthesis.cancel();
        setMsgTtsState($msg, 'idle');
        ttsCurrentMsg = null;
        ttsCurrentUtt = null;
    }

    function speak(text, $msgEl) {
        if (!ttsEnabled || !window.speechSynthesis || !text) return;
        if (ttsCurrentMsg) {
            setMsgTtsState(ttsCurrentMsg, 'idle');
        }
        speechSynthesis.cancel();
        var clean = cleanTtsText(text);
        if (!clean) return;
        if ($msgEl) {
            var utt = buildMsgUtterance(clean, $msgEl);
            ttsCurrentMsg = $msgEl;
            ttsCurrentUtt = utt;
            setMsgTtsState($msgEl, 'playing');
            speechSynthesis.speak(utt);
        } else {
            speechSynthesis.speak(new SpeechSynthesisUtterance(clean));
        }
    }

    // =========================================================================
    // Voice dictation
    // =========================================================================

    function buildRecognition() {
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) return null;
        var r = new SR();
        r.lang           = navigator.language || 'fr-FR';
        r.continuous     = false; // always false: continuous=true is unreliable on Android Chrome
        r.interimResults = true;

        r.onresult = function (e) {
            var committed = '';
            var interim   = '';
            for (var i = 0; i < e.results.length; i++) {
                if (e.results[i].isFinal) committed += e.results[i][0].transcript;
                else                      interim   += e.results[i][0].transcript;
            }
            micCommitted = committed;
            var base = micInitialText;
            var sep  = base && base.slice(-1) !== ' ' && (committed || interim) ? ' ' : '';
            $('#alfred-input').val(base + sep + committed + interim).trigger('input');
        };

        r.onend = function () {
            setMicListening(false);
            if (!isListening) return; // manually stopped — do nothing

            if (micAutoSend) {
                isListening = false;
                if (micCommitted.trim()) $('#alfred-send').trigger('click');
            } else {
                // Fill mode: update base to current textarea, then restart cleanly
                micInitialText = $('#alfred-input').val();
                micCommitted   = '';
                try { recognition.start(); } catch (ex) {}
                setMicListening(true);
            }
        };

        r.onerror = function () {
            isListening = false;
            setMicListening(false);
        };

        return r;
    }

    function toggleMic() {
        if (isListening) {
            isListening = false; // set before stop so onend won't restart
            recognition.stop();
            setMicListening(false);
        } else {
            micInitialText = $('#alfred-input').val();
            micCommitted   = '';
            recognition    = buildRecognition();
            if (!recognition) return;
            recognition.start();
            isListening = true;
            setMicListening(true);
        }
    }

    function setMicListening(active) {
        var $btn = $('#alfred-mic');
        $btn.toggleClass('listening', active);
        $btn.find('i').attr('class', active ? 'fas fa-stop' : 'fas fa-microphone');
        $btn.attr('title', active ? "{{Stop recording}}" : "{{Voice input}}");
    }

    // Init mic buttons
    (function () {
        if (!(window.SpeechRecognition || window.webkitSpeechRecognition)) {
            $('#alfred-mic, #alfred-mic-autosend').hide();
            return;
        }
        $('#alfred-mic-autosend').toggleClass('active', micAutoSend)
            .attr('title', micAutoSend ? "{{Auto-send: ON}}" : "{{Auto-send: OFF}}");

        $('#alfred-mic').on('click', function () {
            if (!isStreaming) toggleMic();
        });

        $('#alfred-mic-autosend').on('click', function () {
            micAutoSend = !micAutoSend;
            localStorage.setItem('alfred_mic_autosend', micAutoSend ? '1' : '0');
            $(this).toggleClass('active', micAutoSend)
                   .attr('title', micAutoSend ? "{{Auto-send: ON}}" : "{{Auto-send: OFF}}");
        });
    }());

    // =========================================================================
    // SSE streaming
    // =========================================================================

    function sendMessage(sessionId, text) {
        $('#alfred-welcome').remove();
        appendBubble('user', text);
        showTyping();
        setInputEnabled(false);
        isStreaming = true;
        if (currentSource) { currentSource.close(); currentSource = null; }
        openStream(sessionId, text, 0);
    }

    function sendContinue(sessionId, extraIterations) {
        showTyping();
        setInputEnabled(false);
        isStreaming = true;
        if (currentSource) { currentSource.close(); currentSource = null; }
        openStream(sessionId, '', extraIterations);
    }

    function openStream(sessionId, message, extraIterations) {
        var url = 'plugins/alfred/api/chat.php'
            + '?session_id='       + encodeURIComponent(sessionId)
            + '&message='          + encodeURIComponent(message)
            + '&extra_iterations=' + extraIterations
            + '&user_hash='        + encodeURIComponent(alfred_config.userHash)
            + '&_=' + Date.now();

        var source = new EventSource(url);
        currentSource = source;

        var $assistantBubble = null;
        var assistantText    = '';

        source.addEventListener('tool_call', function (e) {
            var d = JSON.parse(e.data);
            hideTyping();
            appendToolCall(d.name, 'running', d.input);
        });

        source.addEventListener('tool_result', function (e) {
            var d = JSON.parse(e.data);
            updateToolCall(d.name, 'done', d.result);
        });

        source.addEventListener('debug', function (e) {
            if (!alfred_config.isAdmin) return;
            var d = JSON.parse(e.data);
            var $block = $('<div class="alfred-debug-prompt">')
                .append(
                    $('<details>')
                        .append($('<summary>').text('🔍 System prompt'))
                        .append($('<pre>').text(d.system_prompt))
                );
            $('#alfred-messages').append($block);
            scrollToBottom();
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
            var finalText = d.text || assistantText;
            if (!$assistantBubble && finalText) {
                $assistantBubble = appendBubble('assistant', finalText);
            }
            if ($assistantBubble) {
                $assistantBubble.data('tts-text', finalText);
            }
            if (d.limit_reached) {
                appendLimitReached(sessionId);
            } else {
                speak(finalText, $assistantBubble);
            }
            source.close();
            currentSource = null;
            isStreaming   = false;
            setInputEnabled(true);
            loadSessions();
            $('.alfred-session-item').removeClass('active');
            $('[data-session-id="' + sessionId + '"]').addClass('active');
        });

        source.addEventListener('error', function (e) {
            hideTyping();
            var technical = null;
            try { technical = JSON.parse(e.data).message; } catch (_) {}
            var display = alfred_config.isAdmin && technical
                ? technical
                : "{{An error occurred.}}";
            var $bubble = appendBubble('assistant', '⚠️ ' + display);
            if (alfred_config.isAdmin && technical && technical !== display) {
                $bubble.find('.alfred-msg-bubble').append(
                    $('<details style="margin-top:6px;font-size:11px;opacity:0.7">')
                        .append($('<summary>').text('Details'))
                        .append($('<pre style="white-space:pre-wrap;margin:4px 0 0">').text(technical))
                );
            }
            source.close();
            currentSource = null;
            isStreaming   = false;
            setInputEnabled(true);
        });

        source.onerror = function () {
            if (source.readyState === EventSource.CLOSED) {
                hideTyping();
                isStreaming = false;
                setInputEnabled(true);
                currentSource = null;
            }
        };
    }

    function appendLimitReached(sessionId) {
        var $el = $('<div class="alfred-limit-reached">');
        $('<span>').text("{{Maximum iterations reached}}").appendTo($el);
        var steps = [5, 10, 20];
        for (var i = 0; i < steps.length; i++) {
            (function (n) {
                $('<button class="alfred-limit-btn">').text('+' + n)
                    .on('click', function () {
                        $el.remove();
                        sendContinue(sessionId, n);
                    })
                    .appendTo($el);
            }(steps[i]));
        }
        $('#alfred-messages').append($el);
        scrollToBottom();
    }

    // =========================================================================
    // DOM helpers
    // =========================================================================

    function appendScheduledBubble(instruction) {
        var $summary = $('<summary class="alfred-tool-call-header">')
            .append($('<i>').addClass('fas fa-clock'))
            .append($('<span>').text(instruction));
        var $el = $('<div class="alfred-tool-call">')
            .append($('<details class="alfred-tool-details alfred-tool-no-content">').append($summary));
        $('#alfred-messages').append($el);
        scrollToBottom();
    }

    function appendBubble(role, text) {
        var $bubble = $('<div class="alfred-msg-bubble">').html(markdownToHtml(text));
        var $msg    = $('<div class="alfred-msg ' + role + '">').append($bubble);
        if (role === 'assistant' && window.speechSynthesis) {
            $msg.data('tts-text', text);
            var $actions = $('<div class="alfred-msg-actions">');
            var $btnPlay   = $('<button class="alfred-tts-btn alfred-tts-play"          title="{{Play}}"><i class="fas fa-volume-up"></i></button>');
            var $btnPause  = $('<button class="alfred-tts-btn alfred-tts-pause  hidden" title="{{Pause}}"><i class="fas fa-pause"></i></button>');
            var $btnResume = $('<button class="alfred-tts-btn alfred-tts-resume hidden" title="{{Resume}}"><i class="fas fa-play"></i></button>');
            var $btnStop   = $('<button class="alfred-tts-btn alfred-tts-stop   hidden" title="{{Stop}}"><i class="fas fa-stop"></i></button>');
            $btnPlay.on('click',   function () { speakMsg($msg); });
            $btnPause.on('click',  function () { pauseMsg($msg); });
            $btnResume.on('click', function () { resumeMsg($msg); });
            $btnStop.on('click',   function () { stopMsg($msg); });
            $actions.append($btnPlay, $btnPause, $btnResume, $btnStop);
            $msg.append($actions);
        }
        $('#alfred-messages').append($msg);
        scrollToBottom();
        return $msg;
    }

    var _toolCallMap = {};

    function appendToolCall(name, status, input, result) {
        var icon = status === 'running' ? 'fa-spinner fa-spin' : 'fa-check text-success';
        var $summary = $('<summary class="alfred-tool-call-header">')
            .append($('<i>').addClass('fas ' + icon))
            .append($('<code>').text(name));
        var $details = $('<details class="alfred-tool-details">').append($summary);

        var hasInput = input && Object.keys(input).length > 0;
        var hasResult = result !== undefined && result !== null;
        if (!hasInput && !hasResult) {
            $details.addClass('alfred-tool-no-content');
        }
        if (hasInput) {
            $details.append(
                $('<div class="alfred-tool-section">')
                    .append($('<span class="alfred-tool-label">').text('Paramètres'))
                    .append($('<pre class="alfred-tool-pre">').text(JSON.stringify(input, null, 2)))
            );
        }
        if (hasResult) {
            var resultStr = typeof result === 'string' ? result : JSON.stringify(result, null, 2);
            $details.append(
                $('<div class="alfred-tool-section">')
                    .append($('<span class="alfred-tool-label">').text('Résultat'))
                    .append($('<pre class="alfred-tool-pre">').text(resultStr))
            );
        }

        var $el = $('<div class="alfred-tool-call">').attr('data-tool', name).append($details);
        _toolCallMap[name] = $el;
        $('#alfred-messages').append($el);
        scrollToBottom();
    }

    function updateToolCall(name, status, result) {
        var $el = _toolCallMap[name];
        if (!$el) return;
        var icon = status === 'done' ? 'fa-check text-success' : 'fa-times text-danger';
        $el.find('i').attr('class', 'fas ' + icon);

        if (result !== undefined && result !== null) {
            var resultStr = typeof result === 'string' ? result : JSON.stringify(result, null, 2);
            var $details = $el.find('.alfred-tool-details');
            $details.removeClass('alfred-tool-no-content');
            $details.append(
                $('<div class="alfred-tool-section">')
                    .append($('<span class="alfred-tool-label">').text('Résultat'))
                    .append($('<pre class="alfred-tool-pre">').text(resultStr))
            );
        }
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
        $('#alfred-input, #alfred-send, #alfred-mic, #alfred-mic-autosend').prop('disabled', !enabled);
        if (!enabled && isListening) { isListening = false; recognition.stop(); }
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
    // Background poll — picks up messages written by scheduled tasks
    // =========================================================================

    setInterval(function () {
        if (isStreaming || !currentSessionId) return;
        $.ajax({
            type: 'POST',
            url: 'plugins/alfred/core/ajax/alfred.ajax.php',
            data: { action: 'getMessages', session_id: currentSessionId },
            dataType: 'json',
            success: function (resp) {
                if (resp.state !== 'ok' || !resp.result) return;
                if (resp.result.length > knownMsgCount) {
                    renderHistory(resp.result);
                    loadSessions();
                }
            }
        });
    }, 10000);

    // =========================================================================
    // Boot
    // =========================================================================

    loadSessions(function () {
        var lastSessionId = localStorage.getItem('alfred_last_session');
        if (lastSessionId && $('[data-session-id="' + lastSessionId + '"]').length) {
            loadSession(lastSessionId);
        } else {
            startNewSession();
            setInputEnabled(!!alfred_config.isConfigured);
        }
    });
});
