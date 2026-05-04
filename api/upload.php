<?php
/**
 * Alfred — File upload endpoint
 *
 * POST multipart/form-data:
 *   file       : the uploaded file
 *   session_id : current conversation UUID
 *   user_hash  : auth token (when no PHP session)
 *
 * Returns JSON: {file_id, filename, mime_type, size}
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ---- Auth ----
$connectedUser = null;
if (isConnect()) {
    $connectedUser = $_SESSION['user'] ?? null;
} else {
    $hash          = trim($_POST['user_hash'] ?? init('user_hash'));
    $connectedUser = $hash !== '' ? user::byHash($hash) : null;
    if (!$connectedUser) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

// ---- Input validation ----
$sessionId = trim($_POST['session_id'] ?? '');
if ($sessionId === '' || !preg_match('/^[a-f0-9\-]{8,36}$/i', $sessionId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid session_id']);
    exit;
}

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $code = empty($_FILES['file']) ? 'No file provided' : 'Upload error: ' . $_FILES['file']['error'];
    http_response_code(400);
    echo json_encode(['error' => $code]);
    exit;
}

$file     = $_FILES['file'];
$maxSize  = 20 * 1024 * 1024;
$allowed  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
$allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];

if ($file['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'File too large (max 20MB)']);
    exit;
}

$mimeType = mime_content_type($file['tmp_name']);
if (!in_array($mimeType, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported file type']);
    exit;
}

// ---- Store file ----
$safeSession = preg_replace('/[^a-zA-Z0-9\-]/', '', $sessionId);
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'alfred' . DIRECTORY_SEPARATOR . $safeSession;
if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
    http_response_code(500);
    echo json_encode(['error' => 'Cannot create upload directory']);
    exit;
}

$fileId  = bin2hex(random_bytes(8));
$origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$safeExt = in_array($origExt, $allowedExt, true) ? $origExt : '';
$filename = $fileId . ($safeExt !== '' ? '.' . $safeExt : '');
$filePath = $dir . DIRECTORY_SEPARATOR . $filename;

if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save file']);
    exit;
}

$meta = [
    'file_id'       => $fileId,
    'original_name' => $file['name'],
    'mime_type'     => $mimeType,
    'size'          => $file['size'],
    'filename'      => $filename,
];
file_put_contents($dir . DIRECTORY_SEPARATOR . $fileId . '.json', json_encode($meta));

echo json_encode([
    'file_id'   => $fileId,
    'filename'  => $file['name'],
    'mime_type' => $mimeType,
    'size'      => $file['size'],
]);
