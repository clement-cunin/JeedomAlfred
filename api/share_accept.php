<?php
/**
 * Alfred — Share accept endpoint
 *
 * Moves a file from a share-target session directory to a target session
 * directory so the agent can find it via listUploadedFiles(targetSession).
 *
 * POST application/json:
 *   share_session  : UUID of the session created by share.php
 *   file_id        : ID of the file to transfer
 *   target_session : UUID of the destination session
 *   user_hash      : auth token (when no PHP session)
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

header('Content-Type: application/json');

$connectedUser = null;
if (isConnect()) {
    $connectedUser = $_SESSION['user'] ?? null;
} else {
    $raw           = file_get_contents('php://input');
    $body          = json_decode($raw, true) ?? [];
    $hash          = trim($body['user_hash'] ?? init('user_hash'));
    $connectedUser = $hash !== '' ? user::byHash($hash) : null;
    // user_hash cached client-side can go stale after Jeedom's automatic admin
    // hash rotation — fall back to jeedom::apiAccess(), same as api/conversation.php.
    if (!$connectedUser && $hash !== '' && jeedom::apiAccess($hash, 'core')) {
        global $_USER_GLOBAL;
        $connectedUser = is_object($_USER_GLOBAL) ? $_USER_GLOBAL : true;
    }
}
if (!$connectedUser) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];

$shareSession  = preg_replace('/[^a-zA-Z0-9\-]/', '', trim($body['share_session']  ?? ''));
$fileId        = preg_replace('/[^a-zA-Z0-9]/',   '', trim($body['file_id']        ?? ''));
$targetSession = preg_replace('/[^a-zA-Z0-9\-]/', '', trim($body['target_session'] ?? ''));

if (!$shareSession || !$fileId || !$targetSession) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$base   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'alfred' . DIRECTORY_SEPARATOR;
$srcDir = $base . $shareSession;
$dstDir = $base . $targetSession;

$metaSrc = $srcDir . DIRECTORY_SEPARATOR . $fileId . '.json';
if (!file_exists($metaSrc)) {
    http_response_code(404);
    echo json_encode(['error' => 'File not found']);
    exit;
}

$meta    = json_decode(file_get_contents($metaSrc), true);
$fileSrc = $srcDir . DIRECTORY_SEPARATOR . $meta['filename'];

if (!is_dir($dstDir) && !mkdir($dstDir, 0700, true)) {
    http_response_code(500);
    echo json_encode(['error' => 'Cannot create target directory']);
    exit;
}

if (!rename($fileSrc, $dstDir . DIRECTORY_SEPARATOR . $meta['filename'])) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to move file']);
    exit;
}

rename($metaSrc, $dstDir . DIRECTORY_SEPARATOR . $fileId . '.json');

// Clean up empty source dir
if (count(glob($srcDir . DIRECTORY_SEPARATOR . '*')) === 0) {
    @rmdir($srcDir);
}

echo json_encode([
    'success'   => true,
    'file_id'   => $fileId,
    'filename'  => $meta['original_name'],
    'mime_type' => $meta['mime_type'],
]);
