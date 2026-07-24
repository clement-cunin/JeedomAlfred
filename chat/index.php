<?php
/**
 * Alfred — Standalone PWA chat page
 * No Jeedom chrome. Installable via Chrome / Safari "Add to Home Screen".
 */
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
include_file('core', 'authentification', 'php');

// Accessed as a standalone entry point (bookmark / installed PWA), not through
// Jeedom's router, so the "remember me" cookie needs authentification.class.php
// (loaded above) to hydrate $_SESSION['user'] before isConnect() is checked.
// Fallback: JS reads user_hash from localStorage (saved by the desktop page).
$_userHash       = '';
$_isConfigured   = false;
$_isAdmin        = false;
$_vapidPublicKey = '';
if (isConnect()) {
    require_once dirname(__FILE__) . '/../core/class/alfred.class.php';
    require_once dirname(__FILE__) . '/../core/class/alfredLLM.class.php';
    $_userHash     = $_SESSION['user']->getHash();
    // alfred::getApiKey() only looks at the *first* provider in the chain and
    // assumes an api_key credential — wrong for ollama (which uses base_url),
    // so a chain with ollama first (even mid-chain, with a working mistral
    // fallback behind it) would report "not configured" and disable the UI.
    $_isConfigured = alfredLLM::hasConfiguredProvider();
    $_isAdmin      = $_SESSION['user']->getProfils() === 'admin';
}
// VAPID keys are generated lazily on first subscribe request (api/push.php).
// No need to inject the public key into the page — the JS fetches it from the API.
?><!DOCTYPE html>
<html lang="en">
<head>
    <script>
        // Applied before first paint to avoid a flash of the default theme.
        (function () {
            var theme = localStorage.getItem('alfred_theme') || 'default';
            if (theme !== 'default') document.documentElement.setAttribute('data-alfred-theme', theme);
            if (localStorage.getItem('alfred_input_size') === 'large') {
                document.documentElement.setAttribute('data-alfred-input-size', 'large');
            }
        }());
    </script>
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

        .alfred-date-group-label {
            padding: 8px 12px 3px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #aaa;
            font-weight: 600;
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

        .alfred-session-rename {
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

        .alfred-session-item:hover .alfred-session-rename { display: flex; }
        .alfred-session-rename:hover { color: #337ab7; }

        .alfred-session-title-input {
            flex: 1;
            min-width: 0;
            font-size: 13px;
            border: none;
            border-bottom: 1px solid #337ab7;
            outline: none;
            background: transparent;
            color: #333;
            padding: 0 0 1px;
            font-family: inherit;
        }

        /* Push notification banner (main area, always visible) */
        #alfred-notif-bar {
            display: none;
            align-items: center;
            gap: 10px;
            padding: 7px 14px;
            background: #eef3fc;
            border-bottom: 1px solid #c5d0e8;
            font-size: 13px;
            color: #2c4a8a;
            flex-shrink: 0;
        }

        #alfred-notif-bar.push-active {
            background: #f0fdf4;
            border-bottom-color: #a7d7a2;
            color: #166534;
        }

        #alfred-notif-bar i { font-size: 14px; flex-shrink: 0; }

        #alfred-notif-bar span { flex: 1; }

        #alfred-push-btn {
            background: #2c4a8a;
            color: #fff;
            border: none;
            border-radius: 4px;
            padding: 4px 10px;
            font-size: 12px;
            cursor: pointer;
            white-space: nowrap;
            transition: background 0.12s;
        }

        #alfred-push-btn:hover { background: #1e3466; }

        #alfred-notif-bar.push-active #alfred-push-btn {
            background: #166534;
        }

        #alfred-notif-bar.push-active #alfred-push-btn:hover {
            background: #0f4a25;
        }

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

        .alfred-msg.scenario {
            align-self: flex-start;
        }

        .alfred-msg.scenario .alfred-msg-bubble {
            background: #f0f0f0;
            color: #555;
            font-style: italic;
            border-left: 3px solid #aaa;
            border-radius: 4px 12px 12px 4px;
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

        .alfred-msg-bubble h3,
        .alfred-msg-bubble h4,
        .alfred-msg-bubble h5,
        .alfred-msg-bubble h6 {
            margin: 8px 0 3px;
            line-height: 1.3;
            font-size: 14px;
            font-weight: 600;
        }
        .alfred-msg-bubble h3 { font-size: 16px; }
        .alfred-msg-bubble h4 { font-size: 15px; }
        .alfred-msg-bubble ul,
        .alfred-msg-bubble ol {
            margin: 4px 0;
            padding-left: 20px;
        }
        .alfred-msg-bubble li { margin: 2px 0; }
        .alfred-msg-bubble a { color: #337ab7; }
        .alfred-msg.user .alfred-msg-bubble a { color: #d4e9ff; }

        .alfred-md-table-wrap {
            overflow-x: auto;
            margin: 6px 0;
        }
        .alfred-md-table {
            border-collapse: collapse;
            font-size: 13px;
            white-space: nowrap;
        }
        .alfred-md-table th,
        .alfred-md-table td {
            border: 1px solid rgba(0,0,0,0.18);
            padding: 5px 10px;
            text-align: left;
        }
        .alfred-md-table th {
            background: rgba(0,0,0,0.08);
            font-weight: 600;
        }
        .alfred-md-table tr:nth-child(even) td {
            background: rgba(0,0,0,0.03);
        }
        .alfred-msg.user .alfred-md-table th,
        .alfred-msg.user .alfred-md-table td {
            border-color: rgba(255,255,255,0.3);
        }
        .alfred-msg.user .alfred-md-table th {
            background: rgba(255,255,255,0.15);
        }
        .alfred-msg.user .alfred-md-table tr:nth-child(even) td {
            background: rgba(255,255,255,0.07);
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

        .alfred-msg-bubble.alfred-msg-error {
            background: #fdf3f3;
            border: 1px solid #e8b4b4;
            color: #7a2020;
        }

        .alfred-retry-btn {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin-top: 8px;
            padding: 4px 10px;
            font-size: 12px;
            border: 1px solid #c9a0a0;
            border-radius: 4px;
            background: #fff;
            color: #7a2020;
            cursor: pointer;
            transition: all 0.15s;
        }

        .alfred-retry-btn:hover { background: #7a2020; border-color: #7a2020; color: #fff; }

        .alfred-msg-body {
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .alfred-model-label {
            font-size: 11px;
            color: #aaa;
            padding: 2px 4px;
            margin-top: 3px;
            line-height: 1.2;
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

        .alfred-msg.assistant:hover .alfred-msg-actions,
        .alfred-msg.tts-active .alfred-msg-actions { opacity: 1; }

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

        .alfred-tool-call.alfred-tool-call-error { border-color: #d9534f66; }
        .alfred-tool-call.alfred-tool-call-error i { color: #d9534f; }
        .alfred-tool-call.alfred-tool-call-error .alfred-tool-label { color: #d9534f; }

        .alfred-tool-call-header code {
            background: transparent;
            color: inherit;
            padding: 0;
            font-size: inherit;
            font-family: inherit;
            flex-shrink: 0;
        }

        .alfred-tool-call-params {
            color: #aaa;
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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

        /* ---- Attachment bar ---- */
        #alfred-attachment-bar {
            display: none;
            flex-wrap: wrap;
            gap: 6px;
            padding: 8px 16px 0;
            background: #fff;
        }

        .alfred-attachment-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 10px;
            background: #fafafa;
            border: 1px solid #e8e8e8;
            border-radius: 6px;
            font-size: 12px;
            color: #888;
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
            max-width: 220px;
        }

        .alfred-attachment-badge:hover { background: #f0f4fa; border-color: #c5d3e8; color: #337ab7; }
        .alfred-attachment-badge i { color: #337ab7; font-size: 12px; flex-shrink: 0; }
        .alfred-attachment-badge span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; min-width: 0; }

        .alfred-attachment-remove {
            flex-shrink: 0;
            background: none;
            border: none;
            color: #ccc;
            cursor: pointer;
            padding: 0;
            font-size: 10px;
            line-height: 1;
            display: flex;
            align-items: center;
            opacity: 0;
            transition: opacity 0.15s, color 0.15s;
        }

        .alfred-attachment-badge:hover .alfred-attachment-remove { opacity: 1; }
        .alfred-attachment-remove:hover { color: #dc3545 !important; }

        .alfred-file-menu {
            position: fixed;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            z-index: 9999;
            min-width: 160px;
            padding: 4px 0;
            font-size: 13px;
        }
        .alfred-file-menu-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 9px 14px;
            cursor: pointer;
            color: #333;
            transition: background 0.12s;
            white-space: nowrap;
        }
        .alfred-file-menu-item:hover { background: #f0f4fa; color: #337ab7; }
        .alfred-file-menu-item i { width: 14px; text-align: center; color: #337ab7; }
        .alfred-file-menu-separator { height: 1px; background: #eee; margin: 4px 0; }

        #alfred-attach {
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

        #alfred-attach:hover:not(:disabled) { border-color: #337ab7; color: #337ab7; }
        #alfred-attach:disabled { opacity: 0.4; cursor: not-allowed; }
        #alfred-attach.has-file { background: #337ab7; border-color: #337ab7; color: #fff; }

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

        /* Right-side action icons, wrapped onto 2 rows to save horizontal space */
        #alfred-input-actions {
            display: flex;
            flex-wrap: wrap;
            align-content: flex-start;
            justify-content: flex-end;
            gap: 6px;
            width: 80px;
            flex-shrink: 0;
        }

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

        /* ---- Share destination bottom sheet ---- */
        #alfred-share-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 2000;
        }
        #alfred-share-sheet {
            display: none;
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: #fff;
            border-radius: 16px 16px 0 0;
            padding: 24px 20px calc(16px + env(safe-area-inset-bottom));
            z-index: 2001;
            box-shadow: 0 -4px 24px rgba(0,0,0,0.15);
        }
        #alfred-share-sheet h3 {
            margin: 0 0 14px;
            font-size: 16px;
            font-weight: 600;
            color: #333;
        }
        #alfred-share-file-info {
            display: flex;
            align-items: center;
            gap: 10px;
            background: #f4f7fb;
            border-radius: 8px;
            padding: 10px 14px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #555;
            overflow: hidden;
        }
        #alfred-share-file-info i { color: #337ab7; font-size: 18px; flex-shrink: 0; }
        #alfred-share-file-info span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .alfred-share-btn {
            display: block;
            width: 100%;
            padding: 13px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            border: none;
            text-align: center;
            margin-bottom: 10px;
            transition: opacity 0.15s;
        }
        .alfred-share-btn:last-child { margin-bottom: 0; }
        .alfred-share-btn:disabled { opacity: 0.4; cursor: not-allowed; }
        #alfred-share-btn-current { background: #fff; color: #337ab7; border: 1.5px solid #337ab7; }
        #alfred-share-btn-new     { background: #337ab7; color: #fff; }
        #alfred-share-btn-cancel  { background: transparent; color: #999; font-size: 14px; font-weight: 400; padding: 10px; }

        /* ---- Sidebar footer ---- */
        #alfred-sidebar-footer {
            border-top: 1px solid #ddd;
            padding: 8px 10px;
            flex-shrink: 0;
        }

        #alfred-settings-btn {
            width: 100%;
            text-align: left;
            padding: 8px 12px;
            background: transparent;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            color: #555;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        #alfred-settings-btn:hover { background: #e8e8e8; }

        /* ---- Settings sheet ---- */
        #alfred-settings-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.4);
            z-index: 3000;
        }

        #alfred-settings-sheet {
            display: none;
            position: fixed;
            bottom: 0; left: 0; right: 0;
            background: #fff;
            border-radius: 16px 16px 0 0;
            padding: 0 0 calc(16px + env(safe-area-inset-bottom));
            z-index: 3001;
            box-shadow: 0 -4px 24px rgba(0,0,0,0.15);
            max-height: 80vh;
            overflow-y: auto;
        }

        #alfred-settings-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px 12px;
            border-bottom: 1px solid #eee;
            position: sticky;
            top: 0;
            background: #fff;
            z-index: 1;
        }

        #alfred-settings-header h3 { margin: 0; font-size: 16px; font-weight: 600; color: #333; }

        #alfred-settings-close {
            background: transparent;
            border: none;
            font-size: 20px;
            color: #aaa;
            cursor: pointer;
            padding: 0 4px;
            line-height: 1;
        }

        #alfred-settings-close:hover { color: #333; }

        .alfred-settings-section {
            padding: 12px 20px 8px;
            border-bottom: 1px solid #f0f0f0;
        }

        .alfred-settings-section:last-child { border-bottom: none; }

        .alfred-settings-section-title {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #aaa;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .alfred-settings-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 6px 0;
        }

        .alfred-settings-row > label,
        .alfred-settings-row > span { font-size: 14px; color: #333; }

        .alfred-settings-row select {
            font-size: 13px;
            padding: 4px 6px;
            border: 1px solid #ccc;
            border-radius: 4px;
            color: #333;
            max-width: 180px;
        }

        .alfred-settings-rate-val {
            font-size: 12px;
            color: #333;
            min-width: 34px;
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        #alfred-settings-push-status {
            font-size: 12px;
            color: #166534;
            font-weight: 500;
        }

        /* Toggle switch */
        .alfred-toggle {
            position: relative;
            width: 40px;
            height: 22px;
            flex-shrink: 0;
            display: inline-block;
        }

        .alfred-toggle input { opacity: 0; width: 0; height: 0; position: absolute; }

        .alfred-toggle-track {
            position: absolute;
            inset: 0;
            border-radius: 11px;
            background: #ccc;
            cursor: pointer;
            transition: background 0.2s;
        }

        .alfred-toggle input:checked + .alfred-toggle-track { background: #337ab7; }

        .alfred-toggle-track::before {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            background: #fff;
            top: 2px;
            left: 2px;
            transition: transform 0.2s;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }

        .alfred-toggle input:checked + .alfred-toggle-track::before { transform: translateX(18px); }

        /* Dismiss button in push banner */
        #alfred-push-dismiss {
            background: transparent;
            border: none;
            color: rgba(44,74,138,0.5);
            cursor: pointer;
            font-size: 14px;
            padding: 0 2px;
            line-height: 1;
            flex-shrink: 0;
        }

        #alfred-push-dismiss:hover { color: #2c4a8a; }

        /* ---- Larger input area (persistent, opt-in via Settings) ---- */
        #alfred-input { max-height: 120px; }
        html[data-alfred-input-size="large"] #alfred-input { max-height: 240px; }

        /* ---- Themes ---- */
        html[data-alfred-theme="pastel"] body,
        html[data-alfred-theme="pastel"] #alfred-app { background: #fbf3f8; }
        html[data-alfred-theme="pastel"] #alfred-sidebar { background: #f3e9f7; border-right-color: #e3d3ea; }
        html[data-alfred-theme="pastel"] #alfred-sidebar-header { border-bottom-color: #e3d3ea; }
        html[data-alfred-theme="pastel"] #alfred-new-chat { color: #5b4a63; border-color: #d9c0e2; }
        html[data-alfred-theme="pastel"] #alfred-new-chat:hover { background: #ecd9f2; }
        html[data-alfred-theme="pastel"] .alfred-session-item { color: #5b4a63; }
        html[data-alfred-theme="pastel"] .alfred-session-item:hover { background: #ecd9f2; }
        html[data-alfred-theme="pastel"] .alfred-session-item.active { background: #e0c7ec; }
        html[data-alfred-theme="pastel"] #alfred-welcome h2 { color: #5b4a63; }
        html[data-alfred-theme="pastel"] .alfred-msg.assistant .alfred-msg-bubble { background: #eaf6ee; color: #3d5c46; }
        html[data-alfred-theme="pastel"] #alfred-input-bar { background: #fbf3f8; border-top-color: #e3d3ea; }
        html[data-alfred-theme="pastel"] #alfred-input { background: #fff; border-color: #d9c0e2; color: #5b4a63; }
        html[data-alfred-theme="pastel"] #alfred-settings-sheet,
        html[data-alfred-theme="pastel"] #alfred-settings-header { background: #fbf3f8; }

        html[data-alfred-theme="dark"] body,
        html[data-alfred-theme="dark"] #alfred-app { background: #16171c; }
        html[data-alfred-theme="dark"] #alfred-sidebar { background: #1c1d24; border-right-color: #303138; }
        html[data-alfred-theme="dark"] #alfred-sidebar-header { border-bottom-color: #303138; }
        html[data-alfred-theme="dark"] #alfred-conversations-label { color: #777; }
        html[data-alfred-theme="dark"] #alfred-new-chat { color: #ddd; border-color: #40414a; }
        html[data-alfred-theme="dark"] #alfred-new-chat:hover { background: #2a2b33; }
        html[data-alfred-theme="dark"] .alfred-session-item { color: #ddd; }
        html[data-alfred-theme="dark"] .alfred-session-item:hover { background: #2a2b33; }
        html[data-alfred-theme="dark"] .alfred-session-item.active { background: #33475f; }
        html[data-alfred-theme="dark"] .alfred-session-title-input { color: #ddd; }
        html[data-alfred-theme="dark"] #alfred-welcome { color: #999; }
        html[data-alfred-theme="dark"] #alfred-welcome h2 { color: #eee; }
        html[data-alfred-theme="dark"] .alfred-msg.assistant .alfred-msg-bubble { background: #2a2b33; color: #e4e4e4; }
        html[data-alfred-theme="dark"] .alfred-msg.scenario .alfred-msg-bubble { background: #2a2b33; color: #bbb; }
        html[data-alfred-theme="dark"] .alfred-msg-bubble.alfred-msg-error { background: #3a2323; border-color: #7a3a3a; color: #f0b4b4; }
        html[data-alfred-theme="dark"] #alfred-input-bar { background: #16171c; border-top-color: #303138; }
        html[data-alfred-theme="dark"] #alfred-input { background: #1c1d24; border-color: #40414a; color: #eee; }
        html[data-alfred-theme="dark"] #alfred-input:disabled { background: #1a1b20; color: #666; }
        html[data-alfred-theme="dark"] #alfred-settings-sheet,
        html[data-alfred-theme="dark"] #alfred-settings-header { background: #1c1d24; }
        html[data-alfred-theme="dark"] #alfred-settings-header h3 { color: #eee; }
        html[data-alfred-theme="dark"] .alfred-settings-section { border-bottom-color: #303138; }
        html[data-alfred-theme="dark"] .alfred-settings-row > label,
        html[data-alfred-theme="dark"] .alfred-settings-row > span { color: #ddd; }
        html[data-alfred-theme="dark"] .alfred-settings-row select { background: #1c1d24; border-color: #40414a; color: #ddd; }
        html[data-alfred-theme="dark"] #alfred-sidebar-toggle { background: rgba(28,29,36,0.9); color: #ddd; }
    </style>
</head>
<body>

<div id="alfred-login">
    <div class="alfred-welcome-icon"><i class="fas fa-robot"></i></div>
    <h2>Alfred</h2>
    <p>Sign in to Jeedom to continue. You will be redirected back here automatically.</p>
    <a href="/index.php?v=d&m=alfred&p=alfred" class="login-btn">
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
        <div id="alfred-sidebar-footer">
            <button id="alfred-settings-btn">
                <i class="fas fa-cog"></i> Settings
            </button>
        </div>
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

        <div id="alfred-notif-bar">
            <i id="alfred-push-icon" class="fas fa-bell-slash"></i>
            <span id="alfred-push-label">Activer les notifications push pour recevoir des messages Alfred</span>
            <button id="alfred-push-btn">Activer</button>
            <button id="alfred-push-dismiss" title="Dismiss">&#x2715;</button>
        </div>

        <div id="alfred-messages"></div>

        <input type="file" id="alfred-file-input" accept="image/*,.pdf" style="display:none">
        <div id="alfred-attachment-bar"></div>

        <div id="alfred-input-bar">
            <button id="alfred-attach" title="Attach file"><i class="fas fa-paperclip"></i></button>
            <textarea id="alfred-input" placeholder="Type a message…" rows="2"></textarea>
            <div id="alfred-input-actions">
                <div id="alfred-tts-wrap">
                    <button id="alfred-tts" title="Text-to-speech"><i class="fas fa-volume-up"></i></button>
                </div>
                <button id="alfred-mic-autosend" title="Auto-send: OFF"><i class="fas fa-bolt"></i></button>
                <button id="alfred-mic" title="Voice input"><i class="fas fa-microphone"></i></button>
                <button id="alfred-send" title="Send"><i class="fas fa-paper-plane"></i></button>
            </div>
        </div>

    </div>
</div>

<!-- Share destination bottom sheet -->
<div id="alfred-share-overlay"></div>
<div id="alfred-share-sheet">
    <h3>Ajouter le fichier à...</h3>
    <div id="alfred-share-file-info">
        <i id="alfred-share-file-icon" class="fas fa-file"></i>
        <span id="alfred-share-file-name"></span>
    </div>
    <button class="alfred-share-btn" id="alfred-share-btn-current">Conversation actuelle</button>
    <button class="alfred-share-btn" id="alfred-share-btn-new">Nouvelle conversation</button>
    <button class="alfred-share-btn" id="alfred-share-btn-cancel">Annuler</button>
</div>

<!-- Settings sheet -->
<div id="alfred-settings-overlay"></div>
<div id="alfred-settings-sheet">
    <div id="alfred-settings-header">
        <h3>Settings</h3>
        <button id="alfred-settings-close" title="Close">&#x2715;</button>
    </div>
    <div class="alfred-settings-section" id="alfred-settings-push-section" style="display:none">
        <div class="alfred-settings-section-title">Notifications</div>
        <div class="alfred-settings-row">
            <span>Push notifications</span>
            <span id="alfred-settings-push-status"></span>
            <button id="alfred-settings-push-btn" class="alfred-limit-btn" style="flex-shrink:0"></button>
        </div>
    </div>
    <div class="alfred-settings-section" id="alfred-settings-tts-section" style="display:none">
        <div class="alfred-settings-section-title">Text-to-speech</div>
        <div class="alfred-settings-row">
            <label for="alfred-tts-voice">Voice</label>
            <select id="alfred-tts-voice"></select>
        </div>
        <div class="alfred-settings-row">
            <label for="alfred-tts-rate">Speed</label>
            <div style="display:flex;align-items:center;gap:8px">
                <input type="range" id="alfred-tts-rate" min="0.5" max="2" step="0.1" style="width:100px">
                <span class="alfred-settings-rate-val" id="alfred-tts-rate-val"></span>
            </div>
        </div>
    </div>
    <div class="alfred-settings-section">
        <div class="alfred-settings-section-title">Display</div>
        <div class="alfred-settings-row">
            <label for="alfred-settings-show-model">Show AI model name</label>
            <label class="alfred-toggle">
                <input type="checkbox" id="alfred-settings-show-model">
                <span class="alfred-toggle-track"></span>
            </label>
        </div>
        <div class="alfred-settings-row">
            <label for="alfred-settings-theme">Theme</label>
            <select id="alfred-settings-theme">
                <option value="default">Default</option>
                <option value="pastel">Pastel</option>
                <option value="dark">Dark</option>
            </select>
        </div>
        <div class="alfred-settings-row">
            <label for="alfred-settings-input-size">Larger input area</label>
            <label class="alfred-toggle">
                <input type="checkbox" id="alfred-settings-input-size">
                <span class="alfred-toggle-track"></span>
            </label>
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
// Set a cookie so share.php can authenticate even without a live PHP session.
// SameSite=None is required: the Web Share Target POST is a cross-origin navigation.
if (alfred_config.userHash) {
    var _cookieExp = new Date(Date.now() + 30 * 24 * 3600 * 1000).toUTCString();
    document.cookie = 'alfred_user_hash=' + encodeURIComponent(alfred_config.userHash)
        + '; expires=' + _cookieExp + '; path=/plugins/alfred/; Secure; SameSite=None';
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
    var pendingFiles      = [];

    var recognition    = null;
    var isListening    = false;
    var micAutoSend    = localStorage.getItem('alfred_mic_autosend') === '1';
    var micInitialText = '';
    var micCommitted   = '';

    var ttsEnabled    = localStorage.getItem('alfred_tts') === '1';
    var ttsVoice      = null;
    var ttsRate       = parseFloat(localStorage.getItem('alfred_tts_rate') || '1');
    var showModel     = localStorage.getItem('alfred_show_model') !== '0';
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
        pendingFiles     = [];
        renderAttachmentBar();
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

    function getDateGroupLabel(dateStr) {
        if (!dateStr) return '';
        var today = new Date();
        today.setHours(0, 0, 0, 0);
        var yesterday = new Date(today);
        yesterday.setDate(yesterday.getDate() - 1);
        var d = new Date(dateStr.replace(' ', 'T'));
        d.setHours(0, 0, 0, 0);
        if (d.getTime() === today.getTime()) return 'Aujourd\'hui';
        if (d.getTime() === yesterday.getTime()) return 'Hier';
        return d.toLocaleDateString(navigator.language, { day: 'numeric', month: 'long' });
    }

    function renderSessionList(sessions) {
        var $container = $('#alfred-conversations').empty();
        if (!sessions || sessions.length === 0) {
            $container.append('<div class="alfred-sessions-empty">No conversations yet</div>');
            return;
        }
        var currentGroup = null;
        sessions.forEach(function (s) {
            var label = getDateGroupLabel(s.updated_at);
            if (label !== currentGroup) {
                currentGroup = label;
                $container.append($('<div class="alfred-date-group-label">').text(label));
            }
            var $title  = $('<span class="alfred-session-title">').text(s.title || s.session_id.substr(0, 8) + '…');
            var $rename = $('<button type="button" class="alfred-session-rename" title="Rename">')
                .html('<i class="fas fa-pencil-alt"></i>');
            var $del    = $('<button type="button" class="alfred-session-delete" title="Delete">')
                .html('<i class="fas fa-trash"></i>')
                .on('click', function (e) { e.stopPropagation(); deleteSession(s.session_id); });
            var $item   = $('<div class="alfred-session-item">')
                .attr('data-session-id', s.session_id)
                .append($title, $rename, $del)
                .on('click', function () { loadSession(s.session_id); $('#alfred-sidebar').removeClass('open'); });
            $rename.on('click', function (e) {
                e.stopPropagation();
                startRenameSession($item, s.session_id, $title);
            });
            $title.on('dblclick', function (e) {
                e.stopPropagation();
                startRenameSession($item, s.session_id, $title);
            });
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

    function startRenameSession($item, sessionId, $titleSpan) {
        var currentTitle = $titleSpan.text();
        var committed = false;

        function commit(newTitle) {
            if (committed) return;
            committed = true;
            if (newTitle && newTitle !== currentTitle) {
                $titleSpan.text(newTitle);
                $.ajax({
                    type:     'POST',
                    url:      alfred_config.basePath + '/core/ajax/alfred.ajax.php',
                    data:     { action: 'renameSession', session_id: sessionId, title: newTitle },
                    dataType: 'json'
                });
            }
            $input.replaceWith($titleSpan);
        }

        var $input = $('<input class="alfred-session-title-input" type="text">')
            .val(currentTitle)
            .on('click', function (e) { e.stopPropagation(); })
            .on('keydown', function (e) {
                if (e.key === 'Enter') {
                    commit($(this).val().trim() || currentTitle);
                } else if (e.key === 'Escape') {
                    committed = true;
                    $input.replaceWith($titleSpan);
                }
            })
            .on('blur', function () {
                commit($(this).val().trim() || currentTitle);
            });

        $titleSpan.replaceWith($input);
        $input.focus().select();
    }

    function loadSession(sessionId) {
        currentSessionId = sessionId;
        pendingFiles     = [];
        renderAttachmentBar();
        localStorage.setItem('alfred_last_session', sessionId);
        $('.alfred-session-item').removeClass('active');
        $('[data-session-id="' + sessionId + '"]').addClass('active');

        $.ajax({
            type:     'POST',
            url:      alfred_config.basePath + '/core/ajax/alfred.ajax.php',
            data:     { action: 'getMessages', session_id: sessionId },
            dataType: 'json',
            success:  function (resp) {
                try {
                    if (resp.state !== 'ok') return;
                    renderHistory(resp.result);
                } finally {
                    // A server-side error state or a malformed message must never
                    // leave the input stuck disabled.
                    setInputEnabled(!!alfred_config.isConfigured);
                }
            },
            error: function () {
                setInputEnabled(!!alfred_config.isConfigured);
            }
        });
        $.ajax({
            type:     'POST',
            url:      alfred_config.basePath + '/core/ajax/alfred.ajax.php',
            data:     { action: 'getSessionFiles', session_id: sessionId },
            dataType: 'json',
            success:  function (resp) {
                if (resp.state !== 'ok' || !resp.result) return;
                pendingFiles = resp.result;
                renderAttachmentBar();
            }
        });
    }

    function renderHistory(messages) {
        knownMsgCount = messages.length;
        _toolCallMap  = {};
        $('#alfred-messages').empty();
        var toolInputMap = {};
        messages.forEach(function (msg, idx) {
          try {
            if (msg.role === 'assistant') {
                if (msg.tool_calls) msg.tool_calls.forEach(function (tc) { toolInputMap[tc.id] = tc.input; });
                if (msg.content !== '') {
                    if (msg.error) {
                        appendErrorBubble(msg.content, currentSessionId, idx === messages.length - 1);
                    } else {
                        var $b = appendBubble('assistant', msg.content);
                        if (msg.provider) {
                            $b.find('.alfred-msg-body').append(
                                $('<div class="alfred-model-label">').text(msg.provider + ' · ' + msg.model).toggle(showModel)
                            );
                        }
                    }
                }
            } else if (msg.role === 'user') {
                // [SCHEDULED] messages are shown via their pending task bubble — skip duplicate
                if (msg.content.indexOf('[SCHEDULED]') !== 0) {
                    if (msg.scenario_display !== undefined) {
                        appendBubble('scenario', msg.scenario_display);
                    } else {
                        appendBubble('user', msg.content);
                    }
                }
            } else if (msg.role === 'tool') {
                var input  = toolInputMap[msg.tool_call_id];
                var result;
                try { result = JSON.parse(msg.content); } catch (e) { result = msg.content; }
                appendToolCall(msg.name, isToolResultError(result) ? 'error' : 'done', input, result);
            } else if (msg.role === 'pending') {
                appendAsyncTask(msg);
            }
          } catch (err) {
              // One malformed message must not blank out / break the rest of the history.
              console.error('renderHistory: skipping malformed message', idx, err);
          }
        });
        scrollToBottom();
    }

    // =========================================================================
    // Input & send
    // =========================================================================

    $('#alfred-input').on('input', function () {
        var maxHeight = document.documentElement.getAttribute('data-alfred-input-size') === 'large' ? 240 : 120;
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, maxHeight) + 'px';
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
        $('#alfred-settings-tts-section').show();
        var $btn    = $('#alfred-tts');
        var rateVal = isNaN(ttsRate) ? 1 : ttsRate;
        $btn.toggleClass('active', ttsEnabled);
        $('#alfred-tts-rate').val(rateVal);
        $('#alfred-tts-rate-val').text(rateVal.toFixed(1) + 'x');

        $btn.on('click', function () {
            ttsEnabled = !ttsEnabled;
            localStorage.setItem('alfred_tts', ttsEnabled ? '1' : '0');
            $btn.toggleClass('active', ttsEnabled);
            if (!ttsEnabled) {
                if (ttsCurrentMsg) { setMsgTtsState(ttsCurrentMsg, 'idle'); ttsCurrentMsg = null; ttsCurrentUtt = null; }
                speechSynthesis.cancel();
            }
        });

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
        $msg.toggleClass('tts-active', state !== 'idle');
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
    // Settings panel
    // =========================================================================

    (function initSettings() {
        function openSettings() {
            $('#alfred-settings-overlay').show();
            $('#alfred-settings-sheet').show();
            if ($(window).width() < 768) $('#alfred-sidebar').removeClass('open');
        }

        function closeSettings() {
            $('#alfred-settings-overlay').hide();
            $('#alfred-settings-sheet').hide();
        }

        $('#alfred-settings-btn').on('click', openSettings);
        $('#alfred-settings-close').on('click', closeSettings);
        $('#alfred-settings-overlay').on('click', closeSettings);

        var $showModelToggle = $('#alfred-settings-show-model');
        $showModelToggle.prop('checked', showModel);
        $showModelToggle.on('change', function () {
            showModel = this.checked;
            localStorage.setItem('alfred_show_model', showModel ? '1' : '0');
            $('.alfred-model-label').toggle(showModel);
        });

        var $themeSelect = $('#alfred-settings-theme');
        $themeSelect.val(localStorage.getItem('alfred_theme') || 'default');
        $themeSelect.on('change', function () {
            var theme = this.value;
            localStorage.setItem('alfred_theme', theme);
            if (theme === 'default') {
                document.documentElement.removeAttribute('data-alfred-theme');
            } else {
                document.documentElement.setAttribute('data-alfred-theme', theme);
            }
        });

        var $inputSizeToggle = $('#alfred-settings-input-size');
        $inputSizeToggle.prop('checked', localStorage.getItem('alfred_input_size') === 'large');
        $inputSizeToggle.on('change', function () {
            if (this.checked) {
                localStorage.setItem('alfred_input_size', 'large');
                document.documentElement.setAttribute('data-alfred-input-size', 'large');
            } else {
                localStorage.setItem('alfred_input_size', 'normal');
                document.documentElement.removeAttribute('data-alfred-input-size');
            }
            $('#alfred-input').trigger('input');
        });
    }());

    // =========================================================================
    // File attachment
    // =========================================================================

    function mimeToIcon(mimeType) {
        if (mimeType === 'application/pdf')          return 'fa-file-pdf';
        if (mimeType.indexOf('image/') === 0)        return 'fa-file-image';
        return 'fa-file';
    }

    function fileUrl(fileId) {
        return alfred_config.basePath + '/api/file.php'
            + '?session_id=' + encodeURIComponent(currentSessionId)
            + '&file_id='    + encodeURIComponent(fileId)
            + '&user_hash='  + encodeURIComponent(alfred_config.userHash);
    }

    function showFileMenu(f, badgeEl) {
        $('#alfred-file-menu').remove();

        var url  = fileUrl(f.file_id);
        var $menu = $('<div id="alfred-file-menu" class="alfred-file-menu">');

        $('<div class="alfred-file-menu-item">')
            .append($('<i class="fas fa-external-link-alt">'))
            .append($('<span>').text('Ouvrir'))
            .on('click', function () { $menu.remove(); window.open(url, '_blank'); })
            .appendTo($menu);

        if (typeof navigator.share === 'function') {
            $('<div class="alfred-file-menu-item">')
                .append($('<i class="fas fa-share-alt">'))
                .append($('<span>').text('Partager'))
                .on('click', function () {
                    $menu.remove();
                    fetch(url)
                        .then(function (r) { return r.blob(); })
                        .then(function (blob) {
                            var file = new File([blob], f.filename, { type: f.mime_type });
                            if (navigator.canShare && navigator.canShare({ files: [file] })) {
                                return navigator.share({ files: [file], title: f.filename });
                            }
                        })
                        .catch(function () {});
                })
                .appendTo($menu);
        }

        $('<div class="alfred-file-menu-separator">').appendTo($menu);

        $('<div class="alfred-file-menu-item">')
            .append($('<i class="fas fa-download">'))
            .append($('<span>').text('Télécharger'))
            .on('click', function () {
                $menu.remove();
                var a = document.createElement('a');
                a.href = url; a.download = f.filename;
                document.body.appendChild(a);
                a.click();
                setTimeout(function () { document.body.removeChild(a); }, 100);
            })
            .appendTo($menu);

        var rect = badgeEl.getBoundingClientRect();
        $menu.css({ left: rect.left, top: rect.top - 4, transform: 'translateY(-100%)' });
        $('body').append($menu);

        setTimeout(function () {
            $(document).one('click.fileMenu', function () { $('#alfred-file-menu').remove(); });
        }, 0);
    }

    function renderAttachmentBar() {
        var $bar = $('#alfred-attachment-bar').empty();
        if (pendingFiles.length === 0) { $bar.hide(); $('#alfred-attach').removeClass('has-file'); return; }
        $bar.show();
        $('#alfred-attach').addClass('has-file');
        pendingFiles.forEach(function (f) {
            var $badge = $('<div class="alfred-attachment-badge">')
                .append($('<i>').addClass('fas ' + mimeToIcon(f.mime_type)))
                .append($('<span>').text(f.filename))
                .append(
                    $('<button type="button" class="alfred-attachment-remove" title="Remove">').html('&times;')
                        .on('click', function (e) {
                            e.stopPropagation();
                            pendingFiles = pendingFiles.filter(function (x) { return x.file_id !== f.file_id; });
                            renderAttachmentBar();
                        })
                )
                .on('click', function (e) { e.stopPropagation(); showFileMenu(f, this); });
            $bar.append($badge);
        });
    }

    function uploadFile(file) {
        var $attach = $('#alfred-attach');
        $attach.prop('disabled', true);
        var formData = new FormData();
        formData.append('file', file);
        formData.append('session_id', currentSessionId);
        formData.append('user_hash', alfred_config.userHash);

        $.ajax({
            type:        'POST',
            url:         alfred_config.basePath + '/api/upload.php',
            data:        formData,
            processData: false,
            contentType: false,
            success: function (resp) {
                if (resp.file_id) {
                    pendingFiles.push({ file_id: resp.file_id, filename: resp.filename, mime_type: resp.mime_type });
                    renderAttachmentBar();
                } else {
                    alert(resp.error || 'Upload failed');
                }
            },
            error: function (xhr) {
                var msg = 'Upload failed';
                try { msg = JSON.parse(xhr.responseText).error || msg; } catch (_) {}
                alert(msg);
            },
            complete: function () { $attach.prop('disabled', false); }
        });
    }

    $('#alfred-attach').on('click', function () {
        if (!isStreaming) $('#alfred-file-input').val('').trigger('click');
    });

    $('#alfred-file-input').on('change', function () {
        if (this.files && this.files[0]) {
            if (!currentSessionId) currentSessionId = generateUUID();
            uploadFile(this.files[0]);
        }
    });

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
            var d = JSON.parse(e.data); updateToolCall(d.name, isToolResultError(d.result) ? 'error' : 'done', d.result);
        });

        source.addEventListener('async_task', function (e) {
            var d = JSON.parse(e.data);
            appendAsyncTask({ display_text: d.display_text, async_status: d.async_status, task_id: d.task_id });
        });

        source.addEventListener('file_added', function (e) {
            var f = JSON.parse(e.data);
            if (!pendingFiles.some(function (pf) { return pf.file_id === f.file_id; })) {
                pendingFiles.push(f);
                renderAttachmentBar();
            }
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
            sessionStorage.removeItem('alfred_auth_retry');
            var d         = JSON.parse(e.data);
            var finalText = d.text || assistantText;
            hideTyping();
            if (!$assistantBubble && finalText) $assistantBubble = appendBubble('assistant', finalText);
            if ($assistantBubble) {
                $assistantBubble.data('tts-text', finalText);
                if (d.provider) {
                    $assistantBubble.find('.alfred-msg-body').append(
                        $('<div class="alfred-model-label">').text(d.provider + ' · ' + d.model).toggle(showModel)
                    );
                }
            }
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
            source.close(); currentSource = null; isStreaming = false;
            if (technical && technical.indexOf('401') !== -1 && handleAuthExpired()) {
                return;
            }
            var display = alfred_config.isAdmin && technical ? technical : 'An error occurred.';
            appendErrorBubble(display, sessionId);
            setInputEnabled(true);
        });

        source.onerror = function () {
            // chat.php is a one-shot stream: don't let the browser silently
            // retry (readyState CONNECTING) and leave the input disabled forever.
            source.close();
            hideTyping(); isStreaming = false; currentSource = null; setInputEnabled(true);
        };
    }

    // Jeedom rotates admin user hashes automatically every ~3 months
    // (core/class/user.class.php::regenerateHash()), which can make the
    // user_hash cached in localStorage stale and every chat.php call fail
    // with 401. Clear the stale cache and reload once so the page picks up a
    // fresh hash from the live PHP session, instead of looping on the error.
    // Returns true if a reload was triggered (caller should stop handling the error).
    function handleAuthExpired() {
        if (sessionStorage.getItem('alfred_auth_retry')) {
            return false;
        }
        sessionStorage.setItem('alfred_auth_retry', '1');
        localStorage.removeItem('alfred_user_hash');
        localStorage.removeItem('alfred_is_configured');
        localStorage.removeItem('alfred_is_admin');
        document.cookie = 'alfred_user_hash=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/plugins/alfred/; Secure; SameSite=None';
        location.reload();
        return true;
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
        var $inner  = role === 'assistant' ? $('<div class="alfred-msg-body">').append($bubble) : $bubble;
        var $msg    = $('<div class="alfred-msg ' + role + '">').append($inner);
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

    function appendErrorBubble(message, sessionId, showRetry) {
        var $bubble = $('<div class="alfred-msg-bubble alfred-msg-error">');
        $bubble.append(
            $('<div>').append($('<i class="fas fa-exclamation-triangle" style="margin-right:6px">'))
                      .append($('<span>').text(message))
        );
        if (showRetry !== false) {
            var $retry = $('<button class="alfred-retry-btn" title="Retry">')
                .append($('<i class="fas fa-redo">'));
            $retry.on('click', function () {
                $(this).closest('.alfred-msg').remove();
                sendContinue(sessionId, 1);
            });
            $bubble.append($retry);
        }
        var $msg = $('<div class="alfred-msg assistant">').append($bubble);
        $('#alfred-messages').append($msg);
        scrollToBottom();
        return $msg;
    }

    var _toolCallMap = {};

    // A tool result is an error when it's an object carrying a truthy `error` key
    // (e.g. {error: "..."} or {error: true, message: "..."}).
    function isToolResultError(result) {
        return !!(result && typeof result === 'object' && !Array.isArray(result) && result.error);
    }

    // Inline preview of scalar params only (string/number/boolean/null) — arrays and
    // nested objects are skipped so the collapsed header stays a single short line.
    function formatScalarParams(input) {
        if (!input || typeof input !== 'object') return '';
        var parts = [];
        Object.keys(input).forEach(function (key) {
            var val = input[key];
            if (val === null || typeof val === 'string' || typeof val === 'number' || typeof val === 'boolean') {
                var str = String(val);
                if (str.length > 40) str = str.slice(0, 40) + '…';
                parts.push(str);
            }
        });
        return parts.length ? '(' + parts.join(', ') + ')' : '';
    }

    function appendToolCall(name, status, input, result) {
        var isError = status === 'error';
        var icon    = status === 'running' ? 'fa-spinner fa-spin' : (isError ? 'fa-times text-danger' : 'fa-check text-success');
        var $summary = $('<summary class="alfred-tool-call-header">')
            .append($('<i>').addClass('fas ' + icon)).append($('<code>').text(name));
        var paramsPreview = formatScalarParams(input);
        if (paramsPreview) {
            $summary.append($('<span class="alfred-tool-call-params">').text(paramsPreview));
        }
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
            $details.append($('<div class="alfred-tool-section alfred-tool-result-section">')
                .append($('<span class="alfred-tool-label">').text(isError ? 'Error' : 'Result'))
                .append($('<pre class="alfred-tool-pre">').text(rstr)));
        }
        var $el = $('<div class="alfred-tool-call">').attr('data-tool', name)
            .toggleClass('alfred-tool-call-error', isError).append($details);
        _toolCallMap[name] = $el;
        $('#alfred-messages').append($el);
        scrollToBottom();
    }

    function updateToolCall(name, status, result) {
        var $el = _toolCallMap[name];
        if (!$el) return;
        var isError = status === 'error';
        $el.toggleClass('alfred-tool-call-error', isError);
        $el.find('i').attr('class', 'fas ' + (isError ? 'fa-times text-danger' : 'fa-check text-success'));
        if (result !== undefined && result !== null) {
            var $details = $el.find('.alfred-tool-details');
            // Guard: only append result section if none exists yet
            if ($details.find('.alfred-tool-result-section').length > 0) return;
            var rstr = typeof result === 'string' ? result : JSON.stringify(result, null, 2);
            $details.removeClass('alfred-tool-no-content');
            $details.append($('<div class="alfred-tool-section alfred-tool-result-section">')
                .append($('<span class="alfred-tool-label">').text(isError ? 'Error' : 'Result'))
                .append($('<pre class="alfred-tool-pre">').text(rstr)));
        }
    }

    function appendAsyncTask(msg) {
        var status  = msg.async_status || 'pending';
        var iconMap = {
            'pending': 'fa-spinner fa-spin',
            'running': 'fa-spinner fa-spin',
            'done':    'fa-check text-success',
            'error':   'fa-times text-danger'
        };
        var icon    = iconMap[status] || 'fa-spinner fa-spin';
        var label   = msg.display_text || 'Async task';

        var $summary = $('<summary class="alfred-tool-call-header">')
            .append($('<i>').addClass('fas ' + icon))
            .append($('<code>').text(label));
        var $details = $('<details class="alfred-tool-details">').append($summary);

        var hasResult   = msg.result   !== undefined && msg.result   !== null;
        var hasErrorMsg = msg.error_msg !== undefined && msg.error_msg !== null;

        if (!hasResult && !hasErrorMsg) {
            $details.addClass('alfred-tool-no-content');
        }
        if (hasResult) {
            var rstr = typeof msg.result === 'string' ? msg.result : JSON.stringify(msg.result, null, 2);
            $details.append($('<div class="alfred-tool-section">')
                .append($('<span class="alfred-tool-label">').text('Result'))
                .append($('<pre class="alfred-tool-pre">').text(rstr)));
        }
        if (hasErrorMsg) {
            $details.append($('<div class="alfred-tool-section">')
                .append($('<span class="alfred-tool-label">').text('Error'))
                .append($('<pre class="alfred-tool-pre">').text(msg.error_msg)));
        }

        $('#alfred-messages').append($('<div class="alfred-tool-call">').append($details));
        scrollToBottom();
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
        $('#alfred-input, #alfred-send, #alfred-mic, #alfred-mic-autosend, #alfred-attach').prop('disabled', !enabled);
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
        var lines = text.split('\n');
        var html  = '';
        var i     = 0;

        while (i < lines.length) {
            var line = lines[i];

            // Fenced code block
            if (/^```/.test(line)) {
                var code = [];
                i++;
                while (i < lines.length && !/^```/.test(lines[i])) {
                    code.push(escHtml(lines[i]));
                    i++;
                }
                if (i < lines.length) i++; // skip closing ```
                html += '<pre><code>' + code.join('\n') + '</code></pre>';
                continue;
            }

            // Heading (# through ######)
            var hm = line.match(/^(#{1,6})\s+(.+)$/);
            if (hm) {
                var lvl = Math.min(hm[1].length + 2, 6);
                html += '<h' + lvl + '>' + inlineMd(hm[2]) + '</h' + lvl + '>';
                i++;
                continue;
            }

            // Table (lines starting with |)
            if (/^\|/.test(line)) {
                var trows = [];
                while (i < lines.length && /^\|/.test(lines[i])) {
                    trows.push(lines[i]);
                    i++;
                }
                html += renderMdTable(trows);
                continue;
            }

            // Unordered list
            if (/^[-*]\s/.test(line)) {
                html += '<ul>';
                while (i < lines.length && /^[-*]\s/.test(lines[i])) {
                    html += '<li>' + inlineMd(lines[i].replace(/^[-*]\s/, '')) + '</li>';
                    i++;
                }
                html += '</ul>';
                continue;
            }

            // Ordered list
            if (/^\d+\.\s/.test(line)) {
                html += '<ol>';
                while (i < lines.length && /^\d+\.\s/.test(lines[i])) {
                    html += '<li>' + inlineMd(lines[i].replace(/^\d+\.\s+/, '')) + '</li>';
                    i++;
                }
                html += '</ol>';
                continue;
            }

            // Blank line → paragraph break
            if (line.trim() === '') {
                html += '<br>';
                i++;
                continue;
            }

            // Regular text line
            html += inlineMd(line) + '<br>';
            i++;
        }

        return html.replace(/<br>$/, '');
    }

    function inlineMd(text) {
        var s = escHtml(text);
        s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        s = s.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        s = s.replace(/`([^`]+)`/g, '<code>$1</code>');
        s = s.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function (_, label, href) {
            // Block javascript: URIs
            var raw = href.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>');
            if (/^javascript:/i.test(raw.trim())) return label;
            return '<a href="' + href + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
        });
        return s;
    }

    function renderMdTable(rows) {
        if (!rows.length) return '';
        var html   = '<div class="alfred-md-table-wrap"><table class="alfred-md-table"><tbody>';
        var isHead = true;
        for (var i = 0; i < rows.length; i++) {
            // Separator row (|---|---|)
            if (/^\|[-:| ]+\|?$/.test(rows[i])) { isHead = false; continue; }
            var parts = rows[i].split('|');
            if (parts[0].trim() === '') parts.shift();
            if (parts.length > 0 && parts[parts.length - 1].trim() === '') parts.pop();
            var tag = isHead ? 'th' : 'td';
            html += '<tr>';
            for (var j = 0; j < parts.length; j++) {
                html += '<' + tag + '>' + inlineMd(parts[j].trim()) + '</' + tag + '>';
            }
            html += '</tr>';
        }
        return html + '</tbody></table></div>';
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
                var hasActivePending = resp.result.some(function (m) {
                    return m.role === 'pending'
                        && m.async_status !== 'done'
                        && m.async_status !== 'error';
                });
                if (resp.result.length > knownMsgCount || hasActivePending) {
                    renderHistory(resp.result);
                    loadSessions();
                }
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

    // Parse Web Share Target params (?share_session=...&share_file_id=...&share_name=...)
    var _urlParams    = new URLSearchParams(window.location.search);
    var _shareSession = _urlParams.get('share_session');
    var _shareFileId  = _urlParams.get('share_file_id');
    var _shareName    = _urlParams.get('share_name');
    var _shareType    = _urlParams.get('share_type') || 'application/octet-stream';
    var _shareError   = _urlParams.get('share_error');

    if (_shareError) {
        var _errMsgs = {
            auth:     'Share failed: session expired. Open Alfred once to refresh auth.',
            nofile:   'Share failed: no file received (code ' + (_urlParams.get('code') || '?') + ').',
            badfile:  'Share failed: unsupported file type (' + (_urlParams.get('mime') || '?') + ').',
            savefail: 'Share failed: could not save the file on the server.'
        };
        var $toast = $('<div>').css({
            position: 'fixed', bottom: '80px', left: '50%', transform: 'translateX(-50%)',
            background: '#c0392b', color: '#fff', padding: '10px 18px', borderRadius: '8px',
            zIndex: 9999, fontSize: '14px', maxWidth: '90vw', textAlign: 'center'
        }).text(_errMsgs[_shareError] || 'Share failed: ' + _shareError);
        $('body').append($toast);
        setTimeout(function () { $toast.fadeOut(400, function () { $toast.remove(); }); }, 6000);
        history.replaceState(null, '', window.location.pathname);
    }

    loadSessions(function () {
        if (_shareSession && _shareFileId && _shareName) {
            history.replaceState(null, '', window.location.pathname);

            var _lastId            = localStorage.getItem('alfred_last_session');
            var _hasCurrentSession = !!(_lastId && $('[data-session-id="' + _lastId + '"]').length);

            function _activateSession(sessId) {
                currentSessionId = sessId;
                localStorage.setItem('alfred_last_session', sessId);
                if ($('[data-session-id="' + sessId + '"]').length) {
                    loadSession(sessId);
                } else {
                    renderWelcome();
                    setInputEnabled(!!alfred_config.isConfigured);
                }
                pendingFiles.push({ file_id: _shareFileId, filename: _shareName, mime_type: _shareType });
                renderAttachmentBar();
            }

            function _transferAndActivate(targetId) {
                $.ajax({
                    url:         alfred_config.basePath + '/api/share_accept.php',
                    method:      'POST',
                    contentType: 'application/json',
                    data:        JSON.stringify({
                        share_session:  _shareSession,
                        file_id:        _shareFileId,
                        target_session: targetId,
                        user_hash:      alfred_config.userHash
                    }),
                    success: function () { _activateSession(targetId); },
                    error:   function () { _activateSession(_shareSession); }
                });
            }

            if (!_hasCurrentSession) {
                // No conversation with messages: no need to ask.
                // If there's an existing empty session (e.g. from a previous share with files
                // but no message sent), transfer the file there. Otherwise use the share session.
                if (_lastId && _lastId !== _shareSession) {
                    _transferAndActivate(_lastId);
                } else {
                    _activateSession(_shareSession);
                }
            } else {
                // A real conversation exists: ask where to put the file.
                document.getElementById('alfred-share-file-icon').className   = 'fas ' + mimeToIcon(_shareType);
                document.getElementById('alfred-share-file-name').textContent = _shareName;

                document.getElementById('alfred-share-overlay').style.display = 'block';
                document.getElementById('alfred-share-sheet').style.display   = 'block';

                function _closeSheet() {
                    document.getElementById('alfred-share-overlay').style.display = 'none';
                    document.getElementById('alfred-share-sheet').style.display   = 'none';
                }

                document.getElementById('alfred-share-btn-new').onclick = function () {
                    _closeSheet();
                    _activateSession(_shareSession);
                };

                document.getElementById('alfred-share-btn-current').onclick = function () {
                    var btn = this;
                    btn.disabled    = true;
                    btn.textContent = 'Transfert…';
                    $.ajax({
                        url:         alfred_config.basePath + '/api/share_accept.php',
                        method:      'POST',
                        contentType: 'application/json',
                        data:        JSON.stringify({
                            share_session:  _shareSession,
                            file_id:        _shareFileId,
                            target_session: _lastId,
                            user_hash:      alfred_config.userHash
                        }),
                        success: function () {
                            _closeSheet();
                            _activateSession(_lastId);
                        },
                        error: function () {
                            btn.disabled    = false;
                            btn.textContent = 'Conversation actuelle';
                            alert('Erreur lors du transfert du fichier.');
                        }
                    });
                };

                document.getElementById('alfred-share-btn-cancel').onclick = function () {
                    _closeSheet();
                    loadSession(_lastId);
                };

                document.getElementById('alfred-share-overlay').onclick = function () {
                    document.getElementById('alfred-share-btn-cancel').onclick();
                };
            }

        } else {
            var urlSession = new URLSearchParams(window.location.search).get('session');
            if (urlSession) {
                loadSession(urlSession);
            } else {
                var lastId = localStorage.getItem('alfred_last_session');
                if (lastId && $('[data-session-id="' + lastId + '"]').length) {
                    loadSession(lastId);
                } else {
                    startNewSession();
                    setInputEnabled(!!alfred_config.isConfigured);
                }
            }
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
<script>
// ── Push notifications ────────────────────────────────────────────────────────
(function () {
    if (!('PushManager' in window) || !('serviceWorker' in navigator)) return;

    var pushApiUrl           = alfred_config.basePath + '/api/push.php';
    var $bar                 = document.getElementById('alfred-notif-bar');
    var $btn                 = document.getElementById('alfred-push-btn');
    var $icon                = document.getElementById('alfred-push-icon');
    var $label               = document.getElementById('alfred-push-label');
    var $dismiss             = document.getElementById('alfred-push-dismiss');
    var $settingsPushBtn     = document.getElementById('alfred-settings-push-btn');
    var $settingsPushStatus  = document.getElementById('alfred-settings-push-status');
    var $settingsPushSection = document.getElementById('alfred-settings-push-section');

    var _vapidKey = null;

    function isActive()    { return !!localStorage.getItem('alfred_push_token'); }
    function isDismissed() { return localStorage.getItem('alfred_push_dismissed') === '1'; }

    function updateUI(active) {
        if ($settingsPushBtn)    $settingsPushBtn.textContent    = active ? 'Disable' : 'Enable';
        if ($settingsPushStatus) $settingsPushStatus.textContent = active ? 'Active' : '';
        if (active) {
            localStorage.setItem('alfred_push_dismissed', '1');
            $bar.style.display = 'none';
        } else {
            $icon.className    = 'fas fa-bell-slash';
            $label.textContent = 'Activer les notifications push pour recevoir des messages Alfred';
            $btn.textContent   = 'Activer';
            $bar.style.display = isDismissed() ? 'none' : 'flex';
        }
    }

    function sendTokenToSW(token) {
        navigator.serviceWorker.ready.then(function (reg) {
            if (reg.active) reg.active.postMessage({ type: 'ALFRED_PUSH_TOKEN', token: token });
        });
    }

    function urlB64ToUint8(b64) {
        var pad = '='.repeat((4 - b64.length % 4) % 4);
        var raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
        var arr = new Uint8Array(raw.length);
        for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
        return arr;
    }

    function doSubscribe() {
        if (!_vapidKey) { alert('Clé VAPID non disponible — vérifier la configuration du plugin Alfred.'); return; }
        navigator.serviceWorker.ready
            .then(function (reg) {
                return reg.pushManager.subscribe({
                    userVisibleOnly:      true,
                    applicationServerKey: urlB64ToUint8(_vapidKey),
                });
            })
            .then(function (subscription) {
                var j = subscription.toJSON();
                return fetch(pushApiUrl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({
                        action:    'subscribe',
                        endpoint:  j.endpoint,
                        p256dh:    j.keys.p256dh,
                        auth:      j.keys.auth,
                        user_hash: alfred_config.userHash,
                    }),
                });
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) { alert('Erreur activation notifications : ' + (data.error || 'unknown')); return; }
                localStorage.setItem('alfred_push_token', data.fetch_token);
                localStorage.setItem('alfred_phone_id',   data.eqLogic_id);
                sendTokenToSW(data.fetch_token);
                updateUI(true);
            })
            .catch(function (err) { console.error('Push subscribe error:', err); alert('Impossible d\'activer les notifications.\n' + err.message); });
    }

    function doUnsubscribe() {
        navigator.serviceWorker.ready
            .then(function (reg) { return reg.pushManager.getSubscription(); })
            .then(function (sub)  { return sub ? sub.unsubscribe() : null; })
            .then(function () {
                localStorage.removeItem('alfred_push_token');
                localStorage.removeItem('alfred_phone_id');
                updateUI(false);
            });
    }

    function handleToggle() { if (isActive()) doUnsubscribe(); else doSubscribe(); }

    if ($btn)             $btn.addEventListener('click', handleToggle);
    if ($settingsPushBtn) $settingsPushBtn.addEventListener('click', handleToggle);
    if ($dismiss) {
        $dismiss.addEventListener('click', function () {
            localStorage.setItem('alfred_push_dismissed', '1');
            $bar.style.display = 'none';
        });
    }

    navigator.serviceWorker.addEventListener('controllerchange', function () {
        var t = localStorage.getItem('alfred_push_token');
        if (t) sendTokenToSW(t);
    });

    fetch(pushApiUrl + '?action=vapid_public')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.public_key) return;
            _vapidKey = data.public_key;
            var storedToken = localStorage.getItem('alfred_push_token');
            if (storedToken) sendTokenToSW(storedToken);
            updateUI(!!storedToken);
            if ($settingsPushSection) $settingsPushSection.style.display = '';
        })
        .catch(function (err) { console.warn('Alfred push: could not fetch VAPID key —', err.message); });
})();
</script>
</body>
</html>
