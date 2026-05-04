<?php
/**
 * Alfred — File download / inline view endpoint
 *
 * GET ?session_id=<uuid>&file_id=<id>&user_hash=<hash>
 *
 * Serves the uploaded file inline so the browser (or OS share sheet) can
 * handle it natively. Authentication follows the same pattern as chat.php.
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

$connectedUser = null;
if (isConnect()) {
    $connectedUser = $_SESSION['user'] ?? null;
} else {
    $hash          = trim(init('user_hash'));
    $connectedUser = $hash !== '' ? user::byHash($hash) : null;
    if (!$connectedUser) {
        http_response_code(401);
        exit;
    }
}

$sessionId = trim(init('session_id'));
$fileId    = trim(init('file_id'));

if ($sessionId === '' || $fileId === '') {
    http_response_code(400);
    exit;
}

require_once __DIR__ . '/../core/class/alfredAgent.class.php';

$meta = alfredAgent::getFileMeta($sessionId, $fileId);
$path = $meta !== null ? alfredAgent::getFilePath($sessionId, $fileId) : null;

if ($path === null) {
    http_response_code(404);
    exit;
}

$filename = $meta['original_name'] ?? basename($path);
$mimeType = $meta['mime_type']     ?? 'application/octet-stream';

header('Content-Type: ' . $mimeType);
header('Content-Disposition: inline; filename="' . rawurlencode($filename) . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-cache');
header('Access-Control-Allow-Origin: *');
readfile($path);
