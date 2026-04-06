<?php
/**
 * Alfred — Standalone PWA chat page
 * Mobile-first, no Jeedom chrome. Installable via browser "Add to home screen".
 *
 * Full implementation: Phase 6
 */
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

if (!isConnect()) {
    // Redirect to Jeedom login, then back here
    header('Location: ' . network::getNetworkAccess('external', 'proto:ip:port:comp') . '/index.php?v=d&p=connection&redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Alfred">
    <meta name="theme-color" content="#337ab7">
    <title>Alfred</title>
    <link rel="manifest" href="manifest.json">
    <link rel="apple-touch-icon" href="../plugin_info/alfred_icon.png">
    <style>
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100vh;
            color: #555;
            text-align: center;
        }
        .icon { font-size: 56px; margin-bottom: 16px; }
        h1 { font-size: 24px; margin: 0 0 8px; color: #337ab7; }
        p { font-size: 14px; margin: 0; }
    </style>
</head>
<body>
    <div>
        <div class="icon">🤖</div>
        <h1>Alfred</h1>
        <p>Standalone PWA — coming in Phase 6.</p>
    </div>
</body>
</html>
