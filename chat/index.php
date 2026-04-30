<?php
/**
 * Alfred — Standalone PWA chat page
 * No Jeedom chrome. Installable via Chrome / Safari "Add to Home Screen".
 */
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

// PHP session works only when accessed through Jeedom's router.
// Fallback: JS reads user_hash from localStorage (saved by the desktop page).
$_userHash     = '';
$_isConfigured = false;
$_isAdmin      = false;
if (isConnect()) {
    require_once dirname(__FILE__) . '/../core/class/alfred.class.php';
    $_userHash     = $_SESSION['user']->getHash();
    $_isConfigured = alfred::getApiKey() !== '';
    $_isAdmin      = $_SESSION['user']->getProfils() === 'admin';
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Alfred">
    <meta name="theme-color" content="#337ab7">
    <title>Alfred</title>
    <link rel="manifest" href="manifest.php">
    <link rel="icon" type="image/x-icon" href="favicon.ico">
    <link rel="apple-touch-icon" href="../plugin_info/alfred_icon.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@5.15.4/css/all.min.css">
    <style>
        *, *::before, *::after { box-sizing: border-box; }

        html, body {
            margin: 0;
            padding: 0;
            height: 100%;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #fff;
            overscroll-behavior: none;
        }

        #alfred-app {
            display: flex;
            height: 100vh;
            height: 100dvh;
            overflow: hidden;
            background: #fff;
            position: relative;
            padding-top: env(safe-area-inset-top, 0px);
        }

        /* ---- Sidebar ---- */
        #alfred-sidebar {
            width: 240px;
            flex-shrink: 0;
            background: #f5f5f5;
            border-right: 1px solid #ddd;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: width 0.2s ease;
        }

        #alfred-sidebar-header {
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }

        #alfred-new-chat {
            width: 100%;
            text-align: left;
            padding: 8px 12px;
            background: transparent;
            border: 1px solid #ccc;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        #alfred-new-chat:hover { background: #e8e8e8; }

        #alfred-conversations-label {
            padding: 10px 12px 4px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #aaa;
            font-weight: 600;
        }

        #alfred-conversations {
            flex: 1;
            overflow-y: auto;
            padding: 2px 0 6px;
        }

        .alfred-sessions-empty {
            padding: 12px 14px;
            font-size: 12px;
            color: #bbb;
            font-style: italic;
        }

        .alfred-session-item {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            cursor: pointer;
            border-radius: 4px;
            margin: 2px 6px;
            font-size: 13px;
            color: #333;
            gap: 4px;
        }

        .alfred-session-title {
            flex: 1;
            min-width: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .alfred-session-delete {
            flex-shrink: 0;
            width: 22px;
            height: 22px;
            background: transparent;
            border: none;
            color: #ccc;
            cursor: pointer;
            border-radius: 4px;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 11px;
            padding: 0;
            line-height: 1;
            transition: color 0.15s;
        }

        .alfred-session-item:hover .alfred-session-delete { display: flex; }
        .alfred-session-delete:hover { color: #dc3545; }
        .alfred-session-item:hover { background: #e8e8e8; }
        .alfred-session-item.active { background: #d6e4f0; font-weight: 500; }

        /* Sidebar toggle (mobile) */
        #alfred-sidebar-toggle {
            display: none;
            position: absolute;
            top: calc(10px + env(safe-area-inset-top, 0px));
            left: 10px;
            z-index: 10;
            background: rgba(255,255,255,0.9);
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 6px 10px;
            cursor: pointer;
            font-size: 14px;
        }

        /* ---- Install banner ---- */
        #alfred-install-banner {
            display: none;
            align-items: center;
            gap: 10px;
            padding: 8px 14px;
            background: #337ab7;
            color: #fff;
            font-size: 13px;
            flex-shrink: 0;
        }
        #alfred-install-banner span { flex: 1; }
        #alfred-install-btn {
            padding: 4px 12px;
            background: #fff;
            color: #337ab7;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }
        #alfred-install-dismiss {
            background: transparent;
            border: none;
            color: rgba(255,255,255,0.7);
            cursor: pointer;
            font-size: 14px;
            padding: 0 2px;
            line-height: 1;
        }

        /* ---- Main ---- */
        #alfred-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* ---- Messages ---- */
        #alfred-messages {
            flex: 1;
            overflow-y: auto;
            padding: 20px 24px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            -webkit-overflow-scrolling: touch;
        }

        #alfred-welcome {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            color: #666;
            padding: 40px 20px;
        }

        .alfred-welcome-icon {
            font-size: 48px;
            color: #337ab7;
            margin-bottom: 16px;
        }

        #alfred-welcome h2 { font-size: 22px; font-weight: 500; color: #333; margin: 0 0 8px; }
        #alfred-welcome p  { font-size: 14px; margin: 0; max-width: 360px; }
        #alfred-welcome .alfred-warning { color: #c09000; }

        /* Bubbles */
        .alfred-msg {
            display: flex;
            gap: 10px;
            max-width: 85%;
        }

        .alfred-msg.user {
            align-self: flex-end;
            flex-direction: row-reverse;
        }

        .alfred-msg.assistant {
            align-self: flex-start;
            align-items: flex-start;
            gap: 0;
        }

        .alfred-msg-bubble {
            padding: 10px 14px;
            border-radius: 12px;
            font-size: 14px;
            line-height: 1.5;
            word-wrap: break-word;
        }

        .alfred-msg-bubble pre {
            white-space: pre-wrap;
            word-break: break-word;
            background: rgba(0,0,0,0.06);
            border-radius: 6px;
            padding: 8px 10px;
            margin: 6px 0;
            font-size: 12px;
        }

        .alfred-msg-bubble code {
            background: rgba(0,0,0,0.06);
            border-radius: 3px;
            padding: 1px 4px;
            font-size: 12px;
        }

        .alfred-msg.user .alfred-msg-bubble {
            background: #337ab7;
            color: #fff;
            border-bottom-right-radius: 2px;
        }

        .alfred-msg.user .alfred-msg-bubble code,
        .alfred-msg.user .alfred-msg-bubble pre {
            background: rgba(255,255,255,0.15);
        }

        .alfred-msg.assistant .alfred-msg-bubble {
            background: #f0f0f0;
            color: #333;
            border-bottom-left-radius: 2px;
        }

        /* Per-message TTS actions */
        .alfred-msg-actions {
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
            gap: 4px;
            margin-left: -12px;
            padding-top: 14px;
            z-index: 1;
            opacity: 0;
            transition: opacity 0.15s;
        }

        .alfred-msg.assistant:hover .alfred-msg-actions { opacity: 1; }

        .alfred-tts-btn {
            width: 24px;
            height: 24px;
            border-radius: 6px;
            background: #fff;
            color: #aaa;
            border: 1px solid rgba(0,0,0,0.08);
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            padding: 0;
            line-height: 1;
            transition: all 0.15s;
        }

        .alfred-tts-btn:hover { border-color: #337ab7; color: #337ab7; }
        .alfred-tts-btn.hidden { display: none; }

        /* Tool calls */
        .alfred-tool-call {
            align-self: flex-start;
            font-size: 12px;
            color: #888;
            background: #fafafa;
            border: 1px solid #e8e8e8;
            border-radius: 6px;
            padding: 6px 10px;
            max-width: 80%;
        }

        .alfred-tool-call-header {
            display: flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            user-select: none;
            list-style: none;
        }

        .alfred-tool-call-header::marker,
        .alfred-tool-call-header::-webkit-details-marker { display: none; }

        .alfred-tool-call i { color: #337ab7; }

        .alfred-tool-call-header code {
            background: transparent;
            color: inherit;
            padding: 0;
            font-size: inherit;
            font-family: inherit;
        }

        .alfred-tool-details { font-size: 11px; }

        .alfred-tool-details.alfred-tool-no-content > summary {
            pointer-events: none;
            cursor: default;
        }

        .alfred-tool-section { margin-top: 4px; }

        .alfred-tool-label {
            display: block;
            font-weight: bold;
            color: #aaa;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }

        .alfred-tool-pre {
            background: #f0f0f0;
            border: 1px solid #e0e0e0;
            border-radius: 3px;
            padding: 4px 6px;
            margin: 0;
            white-space: pre-wrap;
            word-break: break-all;
            color: #555;
            max-height: 200px;
            overflow-y: auto;
        }

        /* Typing indicator */
        .alfred-typing {
            align-self: flex-start;
            display: flex;
            gap: 4px;
            padding: 10px 14px;
            background: #f0f0f0;
            border-radius: 12px;
            border-bottom-left-radius: 2px;
        }

        .alfred-typing span {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #999;
            animation: alfred-bounce 1.2s infinite ease-in-out;
        }

        .alfred-typing span:nth-child(2) { animation-delay: 0.2s; }
        .alfred-typing span:nth-child(3) { animation-delay: 0.4s; }

        @keyframes alfred-bounce {
            0%, 60%, 100% { transform: translateY(0); }
            30%            { transform: translateY(-6px); }
        }

        /* Debug */
        .alfred-debug-prompt { margin: 8px 12px; font-size: 11px; opacity: 0.6; }
        .alfred-debug-prompt:hover { opacity: 1; }
        .alfred-debug-prompt details summary { cursor: pointer; color: #888; user-select: none; padding: 2px 0; }
        .alfred-debug-prompt details pre {
            background: #f5f5f5;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 8px;
            margin: 4px 0 0;
            white-space: pre-wrap;
            word-break: break-word;
            max-height: 300px;
            overflow-y: auto;
            font-size: 11px;
            line-height: 1.5;
        }

        /* Iteration limit */
        .alfred-limit-reached {
            align-self: flex-start;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
            font-size: 12px;
            color: #888;
            padding: 4px 0;
        }

        .alfred-limit-btn {
            padding: 3px 10px;
            font-size: 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #fff;
            color: #337ab7;
            cursor: pointer;
            transition: all 0.15s;
        }

        .alfred-limit-btn:hover { background: #337ab7; border-color: #337ab7; color: #fff; }

        /* ---- Input bar ---- */
        #alfred-input-bar {
            display: flex;
            align-items: flex-end;
            gap: 8px;
            padding: 12px 16px;
            padding-bottom: calc(12px + env(safe-area-inset-bottom, 0px));
            border-top: 1px solid #e8e8e8;
            background: #fff;
        }

        #alfred-input {
            flex: 1;
            resize: none;
            border: 1px solid #ccc;
            border-radius: 20px;
            padding: 10px 16px;
            font-size: 14px;
            line-height: 1.4;
            max-height: 120px;
            overflow-y: auto;
            outline: none;
            transition: border-color 0.15s;
            font-family: inherit;
        }

        #alfred-input:focus { border-color: #337ab7; }
        #alfred-input:disabled { background: #fafafa; color: #aaa; }

        #alfred-send {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #337ab7;
            color: #fff;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 13px;
            transition: background 0.15s;
        }

        #alfred-send:hover:not(:disabled) { background: #286090; }
        #alfred-send:disabled { background: #ccc; cursor: not-allowed; }

        #alfred-mic {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #6c757d;
            color: #fff;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 13px;
            transition: background 0.15s;
        }

        #alfred-mic:hover:not(:disabled) { background: #5a6268; }
        #alfred-mic:disabled { background: #ccc; cursor: not-allowed; }

        #alfred-mic.listening {
            background: #dc3545;
            animation: alfred-mic-pulse 1.2s ease-in-out infinite;
        }

        #alfred-tts-wrap {
            position: relative;
            display: flex;
            align-items: center;
            gap: 4px;
            flex-shrink: 0;
        }

        #alfred-tts,
        #alfred-mic-autosend {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: transparent;
            color: #aaa;
            border: 1px solid #ccc;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 13px;
            padding: 0;
            transition: all 0.15s;
        }

        #alfred-tts-settings {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: transparent;
            color: #ccc;
            border: 1px solid #e0e0e0;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 13px;
            padding: 0;
            transition: all 0.15s;
        }

        #alfred-tts-settings:hover:not(:disabled) { border-color: #337ab7; color: #337ab7; }
        #alfred-tts-settings:disabled { opacity: 0.4; cursor: not-allowed; }
        #alfred-tts:hover:not(:disabled), #alfred-mic-autosend:hover:not(:disabled) { border-color: #337ab7; color: #337ab7; }
        #alfred-tts.active, #alfred-mic-autosend.active { background: #337ab7; border-color: #337ab7; color: #fff; }
        #alfred-tts:disabled, #alfred-mic-autosend:disabled { opacity: 0.4; cursor: not-allowed; }

        /* TTS popover */
        #alfred-tts-popover {
            position: absolute;
            bottom: calc(100% + 8px);
            left: 0;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            padding: 12px 14px;
            width: 240px;
            z-index: 100;
            display: none;
        }

        #alfred-tts-popover.open { display: block; }

        #alfred-tts-popover .alfred-tts-label {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #888;
            margin-bottom: 4px;
            margin-top: 8px;
        }

        #alfred-tts-popover .alfred-tts-label:first-child { margin-top: 0; }

        #alfred-tts-voice {
            width: 100%;
            font-size: 13px;
            padding: 4px 6px;
            border: 1px solid #ccc;
            border-radius: 4px;
            color: #333;
        }

        .alfred-tts-rate-row { display: flex; align-items: center; gap: 8px; }
        .alfred-tts-rate-row input[type="range"] { flex: 1; }

        .alfred-tts-rate-val {
            font-size: 12px;
            color: #333;
            min-width: 30px;
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        @keyframes alfred-mic-pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(220,53,69,0.5); }
            50%       { box-shadow: 0 0 0 7px rgba(220,53,69,0); }
        }

        /* ============================================================
           Mobile (< 768px)
           ============================================================ */
        @media (max-width: 767px) {
            #alfred-sidebar {
                position: absolute;
                top: 0;
                left: -240px;
                height: 100%;
                z-index: 20;
                box-shadow: 2px 0 8px rgba(0,0,0,0.15);
                transition: left 0.25s ease;
                padding-top: env(safe-area-inset-top, 0px);
            }

            #alfred-sidebar.open { left: 0; }
            #alfred-sidebar-toggle { display: block; }
            .alfred-msg { max-width: 92%; }
            #alfred-messages { padding: 50px 12px 12px; }
        }

        /* ---- Login screen ---- */
        #alfred-login {
            display: none; /* shown by JS when no user_hash available */
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: fixed;
            inset: 0;
            padding: 40px 24px;
            padding-top: calc(40px + env(safe-area-inset-top, 0px));
            padding-bottom: calc(40px + env(safe-area-inset-bottom, 0px));
            text-align: center;
            background: #fff;
            z-index: 999;
        }

        #alfred-login .alfred-welcome-icon { font-size: 48px; color: #337ab7; margin-bottom: 20px; }
        #alfred-login h2 { font-size: 22px; font-weight: 500; color: #333; margin: 0 0 8px; }
        #alfred-login p  { font-size: 14px; color: #666; margin: 0 0 28px; }

        #alfred-login .login-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 28px;
            background: #337ab7;
            color: #fff;
            border: none;
            border-radius: 24px;
            font-size: 15px;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s;
        }

        #alfred-login .login-btn:hover { background: #286090; }
    </style>
</head>
<body>

<div id="alfred-login">
    <div class="alfred-welcome-icon"><i class="fas fa-robot"></i></div>
    <h2>Alfred</h2>
    <p>Sign in to Jeedom to continue. You will be redirected back here automatically.</p>
    <a href="/index.php?v=d&p=plugin&id=alfred" class="login-btn">
        <i class="fas fa-sign-in-alt"></i> Sign in to Jeedom
    </a>
</div>

<div id="alfred-app">

    <div id="alfred-sidebar">
        <div id="alfred-sidebar-header">
            <button id="alfred-new-chat">
                <i class="fas fa-plus"></i> New conversation
            </button>
        </div>
        <div id="alfred-conversations-label">Conversations</div>
        <div id="alfred-conversations"></div>
    </div>

    <button id="alfred-sidebar-toggle" title="Menu">
        <i class="fas fa-bars"></i>
    </button>

    <div id="alfred-main">

        <div id="alfred-install-banner">
            <span><i class="fas fa-download"></i> Install Alfred as an app for the best experience</span>
            <button id="alfred-install-btn">Install</button>
            <button id="alfred-install-dismiss">✕</button>
        </div>

        <div id="alfred-messages"></div>

        <div id="alfred-input-bar">
            <textarea id="alfred-input" placeholder="Type a message…" rows="1"></textarea>
            <div id="alfred-tts-wrap">
                <button id="alfred-tts" title="Text-to-speech"><i class="fas fa-volume-up"></i></button>
                <button id="alfred-tts-settings" title="TTS settings"><i class="fas fa-sliders-h"></i></button>
            </div>
            <button id="alfred-mic-autosend" title="Auto-send: OFF"><i class="fas fa-bolt"></i></button>
            <button id="alfred-mic" title="Voice input"><i class="fas fa-microphone"></i></button>
            <button id="alfred-send" title="Send"><i class="fas fa-paper-plane"></i></button>
        </div>

    </div>
</div>

<script>
var alfred_config = {
    isConfigured: <?php echo $_isConfigured ? 'true' : 'false'; ?>,
    userHash:     "<?php echo htmlspecialchars($_userHash, ENT_QUOTES); ?>",
    isAdmin:      <?php echo $_isAdmin ? 'true' : 'false'; ?>,
    basePath:     '/plugins/alfred'
};
// PHP session unavailable when accessed outside Jeedom router — fall back to localStorage
if (!alfred_config.userHash) {
    alfred_config.userHash     = localStorage.getItem('alfred_user_hash') || '';
    alfred_config.isConfigured = localStorage.getItem('alfred_is_configured') === '1';
    alfred_config.isAdmin      = localStorage.getItem('alfred_is_admin') === '1';
}
// Persist whenever we have fresh data from the server
if ("<?php echo htmlspecialchars($_userHash, ENT_QUOTES); ?>") {
    localStorage.setItem('alfred_user_hash',     alfred_config.userHash);
    localStorage.setItem('alfred_is_configured', alfred_config.isConfigured ? '1' : '0');
    localStorage.setItem('alfred_is_admin',      alfred_config.isAdmin ? '1' : '0');
}
</script>
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script>
'use strict';

$(function () {

    // =========================================================================
    // State
    // =========================================================================

    var currentSessionId  = null;
    var isStreaming        = false;
    var currentSource     = null;
    var knownMsgCount     = 0;

    var recognition    = null;
    var isListening    = false;
    var micAutoSend    = localStorage.getItem('alfred_mic_autosend') === '1';
    var micInitialText = '';
    var micCommitted   = '';

    var ttsEnabled    = localStorage.getItem('alfred_tts') === '1';
    var ttsVoice      = null;
    var ttsRate       = parseFloat(localStorage.getItem('alfred_tts_rate') || '1');
    var ttsCurrentMsg = null;
    var ttsCurrentUtt = null;

    // =========================================================================
    // Sidebar toggle
    // =========================================================================

    $('#alfred-sidebar-toggle').on('click', function (e) {
        e.stopPropagation();
        $('#alfred-sidebar').toggleClass('open');
    });

    $('#alfred-main').on('click', function () {
        if ($(window).width() < 768) $('#alfred-sidebar').removeClass('open');
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
    // Session list
    // =========================================================================

    function loadSessions(callback) {
        $.ajax({
            type:     'POST',
            url:      alfred_config.basePath + '/core/ajax/alfred.ajax.php',
            data:     { action: 'getSessions' },
            dataType: 'json',
            success:  function (resp) {
                if (resp.state === 'ok') renderSessionList(resp.result);
                if (callback) callback();
            },
            error: function () { if (callback) callback(); }
        });
    }

    function renderSessionList(sessions) {
        var $container = $('#alfred-conversations').empty();
        if (!sessions || sessions.length === 0) {
            $container.append('<div class="alfred-sessions-empty">No conversations yet</div>');
            return;
        }
        sessions.forEach(function (s) {
            var $title = $('<span class="alfred-session-title">').text(s.title || s.session_id.substr(0, 8) + '…');
            var $del   = $('<button class="alfred-session-delete" title="Delete">')
                .html('<i class="fas fa-trash"></i>')
                .on('click', function (e) { e.stopPropagation(); deleteSession(s.session_id); });
            var $item  = $('<div class="alfred-session-item">')
                .attr('data-session-id', s.session_id)
                .append($title, $del)
                .on('click', function () { loadSession(s.session_id); $('#alfred-sidebar').removeClass('open'); });
            if (s.session_id === currentSessionId) $item.addClass('active');
            $container.append($item);
        });
    }

    function deleteSession(sessionId) {
        $.ajax({
            type:     'POST',
            url:      alfred_config.basePath + '/core/ajax/alfred.ajax.php',
            data:     { action: 'deleteSession', session_id: sessionId },
            dataType: 'json',
            success:  function (resp) {
                if (resp.state !== 'ok') return;
                if (sessionId === currentSessionId) { localStorage.removeItem('alfred_last_session'); startNewSession(); }
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
            type:     'POST',
            url:      alfred_config.basePath + '/core/ajax/alfred.ajax.php',
            data:     { action: 'getMessages', session_id: sessionId },
            dataType: 'json',
            success:  function (resp) {
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
                if (msg.tool_calls) msg.tool_calls.forEach(function (tc) { toolInputMap[tc.id] = tc.input; });
                if (msg.content !== '') appendBubble('assistant', msg.content);
            } else if (msg.role === 'user') {
                if (msg.content.indexOf('[SCHEDULED]') === 0) {
                    appendScheduledBubble(msg.content.replace('[SCHEDULED]', '').trim());
                } else {
                    appendBubble('user', msg.content);
                }
            } else if (msg.role === 'tool') {
                var input  = toolInputMap[msg.tool_call_id];
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
        if (ttsCurrentMsg) { setMsgTtsState(ttsCurrentMsg, 'idle'); ttsCurrentMsg = null; ttsCurrentUtt = null; }
        speechSynthesis.cancel();
        var text = $('#alfred-input').val().trim();
        if (!text) return;
        if (!currentSessionId) currentSessionId = generateUUID();
        $('#alfred-input').val('').css('height', 'auto');
        sendMessage(currentSessionId, text);
    });

    // =========================================================================
    // TTS
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
                if (ttsCurrentMsg) { setMsgTtsState(ttsCurrentMsg, 'idle'); ttsCurrentMsg = null; ttsCurrentUtt = null; }
                speechSynthesis.cancel();
            }
        });

        var rateDisplay = isNaN(ttsRate) ? '1.0' : ttsRate.toFixed(1);
        var $popover = $('<div id="alfred-tts-popover">')
            .append('<span class="alfred-tts-label">Voice</span>')
            .append('<select id="alfred-tts-voice"></select>')
            .append('<span class="alfred-tts-label">Speed</span>')
            .append(
                $('<div class="alfred-tts-rate-row">')
                    .append('<input type="range" id="alfred-tts-rate" min="0.5" max="2" step="0.1" value="' + (isNaN(ttsRate) ? 1 : ttsRate) + '">')
                    .append('<span class="alfred-tts-rate-val" id="alfred-tts-rate-val">' + rateDisplay + 'x</span>')
            );
        $('#alfred-tts-wrap').append($popover);

        function populateVoices() {
            var voices = speechSynthesis.getVoices();
            if (!voices.length) return;
            speechSynthesis.onvoiceschanged = null;
            var $select  = $('#alfred-tts-voice').empty();
            var saved    = localStorage.getItem('alfred_tts_voice') || '';
            var lang     = (navigator.language || 'fr-FR').split('-')[0];
            var selected = false;
            for (var i = 0; i < voices.length; i++) {
                var v    = voices[i];
                var $opt = $('<option>').val(v.name).text(v.name + ' (' + v.lang + ')');
                if (v.name === saved) { $opt.prop('selected', true); ttsVoice = v; selected = true; }
                $select.append($opt);
            }
            if (!selected) {
                for (var j = 0; j < voices.length; j++) {
                    if (voices[j].lang.indexOf(lang) === 0) { $select.find('option').eq(j).prop('selected', true); ttsVoice = voices[j]; break; }
                }
            }
        }

        populateVoices();
        if (typeof speechSynthesis.onvoiceschanged !== 'undefined') speechSynthesis.onvoiceschanged = populateVoices;

        $('#alfred-tts-voice').on('change', function () {
            var name = $(this).val();
            localStorage.setItem('alfred_tts_voice', name);
            var voices = speechSynthesis.getVoices();
            ttsVoice = null;
            for (var i = 0; i < voices.length; i++) { if (voices[i].name === name) { ttsVoice = voices[i]; break; } }
        });

        $('#alfred-tts-rate').on('input', function () {
            ttsRate = parseFloat(this.value);
            localStorage.setItem('alfred_tts_rate', String(ttsRate));
            $('#alfred-tts-rate-val').text(ttsRate.toFixed(1) + 'x');
        });

        $('#alfred-tts-settings').on('click', function (e) { e.stopPropagation(); $popover.toggleClass('open'); });
        $(document).on('click.tts-popover', function (e) {
            if (!$(e.target).closest('#alfred-tts-wrap').length) $popover.removeClass('open');
        });
    }());

    function cleanTtsText(text) {
        return text
            .replace(/```[\s\S]*?```/g, '').replace(/`[^`]*`/g, '')
            .replace(/\*\*([^*]+)\*\*/g, '$1').replace(/\*([^*]+)\*/g, '$1')
            .replace(/#+\s*/g, '').replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
            .replace(/[_~>|]/g, '').replace(/\n+/g, ' ').trim();
    }

    function buildMsgUtterance(text, $msgEl) {
        var utt = new SpeechSynthesisUtterance(text);
        if (ttsVoice) { utt.voice = ttsVoice; utt.lang = ttsVoice.lang; }
        else          { utt.lang = navigator.language || 'fr-FR'; }
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
        if (ttsCurrentMsg && ttsCurrentMsg[0] !== $msg[0]) setMsgTtsState(ttsCurrentMsg, 'idle');
        speechSynthesis.cancel();
        var clean = cleanTtsText($msg.data('tts-text') || '');
        if (!clean) return;
        var utt = buildMsgUtterance(clean, $msg);
        ttsCurrentMsg = $msg; ttsCurrentUtt = utt;
        setMsgTtsState($msg, 'playing');
        speechSynthesis.speak(utt);
    }

    function pauseMsg($msg)  { if (!ttsCurrentMsg || ttsCurrentMsg[0] !== $msg[0]) return; speechSynthesis.pause();  setMsgTtsState($msg, 'paused');  }
    function resumeMsg($msg) { if (!ttsCurrentMsg || ttsCurrentMsg[0] !== $msg[0]) return; speechSynthesis.resume(); setMsgTtsState($msg, 'playing'); }
    function stopMsg($msg)   {
        if (!ttsCurrentMsg || ttsCurrentMsg[0] !== $msg[0]) return;
        speechSynthesis.cancel(); setMsgTtsState($msg, 'idle'); ttsCurrentMsg = null; ttsCurrentUtt = null;
    }

    function speak(text, $msgEl) {
        if (!ttsEnabled || !window.speechSynthesis || !text) return;
        if (ttsCurrentMsg) setMsgTtsState(ttsCurrentMsg, 'idle');
        speechSynthesis.cancel();
        var clean = cleanTtsText(text);
        if (!clean) return;
        if ($msgEl) {
            var utt = buildMsgUtterance(clean, $msgEl);
            ttsCurrentMsg = $msgEl; ttsCurrentUtt = utt;
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
        r.lang = navigator.language || 'fr-FR';
        r.continuous = false;
        r.interimResults = true;

        r.onresult = function (e) {
            var committed = '', interim = '';
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
            if (!isListening) return;
            if (micAutoSend) {
                isListening = false;
                if (micCommitted.trim()) $('#alfred-send').trigger('click');
            } else {
                micInitialText = $('#alfred-input').val();
                micCommitted   = '';
                try { recognition.start(); } catch (ex) {}
                setMicListening(true);
            }
        };

        r.onerror = function () { isListening = false; setMicListening(false); };
        return r;
    }

    function toggleMic() {
        if (isListening) { isListening = false; recognition.stop(); setMicListening(false); }
        else {
            micInitialText = $('#alfred-input').val(); micCommitted = '';
            recognition = buildRecognition();
            if (!recognition) return;
            recognition.start(); isListening = true; setMicListening(true);
        }
    }

    function setMicListening(active) {
        var $btn = $('#alfred-mic');
        $btn.toggleClass('listening', active);
        $btn.find('i').attr('class', active ? 'fas fa-stop' : 'fas fa-microphone');
        $btn.attr('title', active ? 'Stop recording' : 'Voice input');
    }

    (function () {
        if (!(window.SpeechRecognition || window.webkitSpeechRecognition)) { $('#alfred-mic, #alfred-mic-autosend').hide(); return; }
        $('#alfred-mic-autosend').toggleClass('active', micAutoSend).attr('title', micAutoSend ? 'Auto-send: ON' : 'Auto-send: OFF');
        $('#alfred-mic').on('click', function () { if (!isStreaming) toggleMic(); });
        $('#alfred-mic-autosend').on('click', function () {
            micAutoSend = !micAutoSend;
            localStorage.setItem('alfred_mic_autosend', micAutoSend ? '1' : '0');
            $(this).toggleClass('active', micAutoSend).attr('title', micAutoSend ? 'Auto-send: ON' : 'Auto-send: OFF');
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
        showTyping(); setInputEnabled(false); isStreaming = true;
        if (currentSource) { currentSource.close(); currentSource = null; }
        openStream(sessionId, '', extraIterations);
    }

    function openStream(sessionId, message, extraIterations) {
        var url = alfred_config.basePath + '/api/chat.php'
            + '?session_id='       + encodeURIComponent(sessionId)
            + '&message='          + encodeURIComponent(message)
            + '&extra_iterations=' + extraIterations
            + '&user_hash='        + encodeURIComponent(alfred_config.userHash)
            + '&_='                + Date.now();

        var source           = new EventSource(url);
        currentSource        = source;
        var $assistantBubble = null;
        var assistantText    = '';

        source.addEventListener('tool_call', function (e) {
            var d = JSON.parse(e.data); hideTyping(); appendToolCall(d.name, 'running', d.input);
        });

        source.addEventListener('tool_result', function (e) {
            var d = JSON.parse(e.data); updateToolCall(d.name, 'done', d.result);
        });

        source.addEventListener('debug', function (e) {
            if (!alfred_config.isAdmin) return;
            var d = JSON.parse(e.data);
            $('#alfred-messages').append(
                $('<div class="alfred-debug-prompt">').append(
                    $('<details>').append($('<summary>').text('🔍 System prompt')).append($('<pre>').text(d.system_prompt))
                )
            );
            scrollToBottom();
        });

        source.addEventListener('delta', function (e) {
            var d = JSON.parse(e.data); hideTyping();
            if (!$assistantBubble) $assistantBubble = appendBubble('assistant', '');
            assistantText += d.text;
            $assistantBubble.find('.alfred-msg-bubble').html(markdownToHtml(assistantText));
            scrollToBottom();
        });

        source.addEventListener('done', function (e) {
            var d         = JSON.parse(e.data);
            var finalText = d.text || assistantText;
            hideTyping();
            if (!$assistantBubble && finalText) $assistantBubble = appendBubble('assistant', finalText);
            if ($assistantBubble) $assistantBubble.data('tts-text', finalText);
            if (d.limit_reached) { appendLimitReached(sessionId); } else { speak(finalText, $assistantBubble); }
            source.close(); currentSource = null; isStreaming = false; setInputEnabled(true);
            loadSessions();
            $('.alfred-session-item').removeClass('active');
            $('[data-session-id="' + sessionId + '"]').addClass('active');
        });

        source.addEventListener('error', function (e) {
            hideTyping();
            var technical = null;
            try { technical = JSON.parse(e.data).message; } catch (_) {}
            var display = alfred_config.isAdmin && technical ? technical : 'An error occurred.';
            var $bubble = appendBubble('assistant', '⚠️ ' + display);
            if (alfred_config.isAdmin && technical && technical !== display) {
                $bubble.find('.alfred-msg-bubble').append(
                    $('<details style="margin-top:6px;font-size:11px;opacity:0.7">')
                        .append($('<summary>').text('Details'))
                        .append($('<pre style="white-space:pre-wrap;margin:4px 0 0">').text(technical))
                );
            }
            source.close(); currentSource = null; isStreaming = false; setInputEnabled(true);
        });

        source.onerror = function () {
            if (source.readyState === EventSource.CLOSED) {
                hideTyping(); isStreaming = false; currentSource = null; setInputEnabled(true);
            }
        };
    }

    function appendLimitReached(sessionId) {
        var $el = $('<div class="alfred-limit-reached">');
        $('<span>').text('Maximum iterations reached').appendTo($el);
        [5, 10, 20].forEach(function (n) {
            $('<button class="alfred-limit-btn">').text('+' + n)
                .on('click', function () { $el.remove(); sendContinue(sessionId, n); })
                .appendTo($el);
        });
        $('#alfred-messages').append($el);
        scrollToBottom();
    }

    // =========================================================================
    // DOM helpers
    // =========================================================================

    function appendScheduledBubble(instruction) {
        var $summary = $('<summary class="alfred-tool-call-header">')
            .append($('<i>').addClass('fas fa-clock')).append($('<span>').text(instruction));
        $('#alfred-messages').append(
            $('<div class="alfred-tool-call">').append(
                $('<details class="alfred-tool-details alfred-tool-no-content">').append($summary)
            )
        );
        scrollToBottom();
    }

    function appendBubble(role, text) {
        var $bubble = $('<div class="alfred-msg-bubble">').html(markdownToHtml(text));
        var $msg    = $('<div class="alfred-msg ' + role + '">').append($bubble);
        if (role === 'assistant' && window.speechSynthesis) {
            $msg.data('tts-text', text);
            var $actions   = $('<div class="alfred-msg-actions">');
            var $btnPlay   = $('<button class="alfred-tts-btn alfred-tts-play"          title="Play"><i class="fas fa-volume-up"></i></button>');
            var $btnPause  = $('<button class="alfred-tts-btn alfred-tts-pause  hidden" title="Pause"><i class="fas fa-pause"></i></button>');
            var $btnResume = $('<button class="alfred-tts-btn alfred-tts-resume hidden" title="Resume"><i class="fas fa-play"></i></button>');
            var $btnStop   = $('<button class="alfred-tts-btn alfred-tts-stop   hidden" title="Stop"><i class="fas fa-stop"></i></button>');
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
        var icon     = status === 'running' ? 'fa-spinner fa-spin' : 'fa-check text-success';
        var $summary = $('<summary class="alfred-tool-call-header">')
            .append($('<i>').addClass('fas ' + icon)).append($('<code>').text(name));
        var $details  = $('<details class="alfred-tool-details">').append($summary);
        var hasInput  = input && Object.keys(input).length > 0;
        var hasResult = result !== undefined && result !== null;
        if (!hasInput && !hasResult) $details.addClass('alfred-tool-no-content');
        if (hasInput) {
            $details.append($('<div class="alfred-tool-section">')
                .append($('<span class="alfred-tool-label">').text('Parameters'))
                .append($('<pre class="alfred-tool-pre">').text(JSON.stringify(input, null, 2))));
        }
        if (hasResult) {
            var rstr = typeof result === 'string' ? result : JSON.stringify(result, null, 2);
            $details.append($('<div class="alfred-tool-section">')
                .append($('<span class="alfred-tool-label">').text('Result'))
                .append($('<pre class="alfred-tool-pre">').text(rstr)));
        }
        var $el = $('<div class="alfred-tool-call">').attr('data-tool', name).append($details);
        _toolCallMap[name] = $el;
        $('#alfred-messages').append($el);
        scrollToBottom();
    }

    function updateToolCall(name, status, result) {
        var $el = _toolCallMap[name];
        if (!$el) return;
        $el.find('i').attr('class', 'fas ' + (status === 'done' ? 'fa-check text-success' : 'fa-times text-danger'));
        if (result !== undefined && result !== null) {
            var rstr     = typeof result === 'string' ? result : JSON.stringify(result, null, 2);
            var $details = $el.find('.alfred-tool-details').removeClass('alfred-tool-no-content');
            $details.append($('<div class="alfred-tool-section">')
                .append($('<span class="alfred-tool-label">').text('Result'))
                .append($('<pre class="alfred-tool-pre">').text(rstr)));
        }
    }

    function showTyping() {
        if ($('#alfred-typing-indicator').length) return;
        $('#alfred-messages').append(
            '<div class="alfred-typing" id="alfred-typing-indicator"><span></span><span></span><span></span></div>'
        );
        scrollToBottom();
    }

    function hideTyping() { $('#alfred-typing-indicator').remove(); }

    function renderWelcome() {
        var body = alfred_config.isConfigured
            ? '<p>Ask me anything about your home automation system.</p>'
            : '<p class="alfred-warning"><i class="fas fa-exclamation-triangle"></i> Configure Alfred in plugin settings to get started.</p>';
        $('#alfred-messages').empty().append(
            '<div id="alfred-welcome"><div class="alfred-welcome-icon"><i class="fas fa-robot"></i></div>' +
            '<h2>Hello, I\'m Alfred.</h2>' + body + '</div>'
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
    // Markdown → HTML
    // =========================================================================

    function markdownToHtml(text) {
        if (!text) return '';
        var s = escHtml(text);
        s = s.replace(/```[\w]*\n?([\s\S]*?)```/g, '<pre><code>$1</code></pre>');
        s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
        s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        s = s.replace(/^### (.+)$/gm, '<h5>$1</h5>');
        s = s.replace(/^## (.+)$/gm,  '<h4>$1</h4>');
        s = s.replace(/^# (.+)$/gm,   '<h3>$1</h3>');
        s = s.replace(/^[-*] (.+)$/gm, '<li>$1</li>');
        s = s.replace(/(<li>.*<\/li>)/s, '<ul>$1</ul>');
        s = s.replace(/\n/g, '<br>');
        return s;
    }

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // =========================================================================
    // UUID
    // =========================================================================

    function generateUUID() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        });
    }

    // =========================================================================
    // Background poll (picks up scheduled messages)
    // =========================================================================

    setInterval(function () {
        if (isStreaming || !currentSessionId) return;
        $.ajax({
            type:     'POST',
            url:      alfred_config.basePath + '/core/ajax/alfred.ajax.php',
            data:     { action: 'getMessages', session_id: currentSessionId },
            dataType: 'json',
            success:  function (resp) {
                if (resp.state !== 'ok' || !resp.result) return;
                if (resp.result.length > knownMsgCount) { renderHistory(resp.result); loadSessions(); }
            }
        });
    }, 10000);

    // =========================================================================
    // Boot
    // =========================================================================

    if (!alfred_config.userHash) {
        localStorage.setItem('alfred_return_to_chat', '1');
        document.getElementById('alfred-login').style.display = 'flex';
        return;
    }

    loadSessions(function () {
        var lastId = localStorage.getItem('alfred_last_session');
        if (lastId && $('[data-session-id="' + lastId + '"]').length) {
            loadSession(lastId);
        } else {
            startNewSession();
            setInputEnabled(!!alfred_config.isConfigured);
        }
    });
});
</script>
<script>
// PWA install prompt — fires when Chrome considers the site a valid installable PWA
var _deferredInstallPrompt = null;
window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    _deferredInstallPrompt = e;
    var banner = document.getElementById('alfred-install-banner');
    if (banner) banner.style.display = 'flex';
});
document.getElementById('alfred-install-btn').addEventListener('click', function () {
    if (!_deferredInstallPrompt) return;
    _deferredInstallPrompt.prompt();
    _deferredInstallPrompt.userChoice.then(function () {
        _deferredInstallPrompt = null;
        var banner = document.getElementById('alfred-install-banner');
        if (banner) banner.style.display = 'none';
    });
});
document.getElementById('alfred-install-dismiss').addEventListener('click', function () {
    var banner = document.getElementById('alfred-install-banner');
    if (banner) banner.style.display = 'none';
});
window.addEventListener('appinstalled', function () {
    _deferredInstallPrompt = null;
    var banner = document.getElementById('alfred-install-banner');
    if (banner) banner.style.display = 'none';
});
</script>
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('./sw.js');
    });
}
</script>
</body>
</html>
