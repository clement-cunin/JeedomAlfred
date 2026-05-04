<?php
/**
 * Alfred — Web Share Target endpoint
 *
 * Receives a file shared from the OS share sheet (PWA share_target).
 * Stores the file, generates a new session UUID, and redirects to the
 * chat UI with query params so the frontend can bootstrap the session.
 *
 * POST multipart/form-data:
 *   files : the shared file (as declared in manifest share_target)
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

$chatUrl = '/plugins/alfred/chat/index.php';

if (!isConnect()) {
    header('Location: ' . $chatUrl);
    exit;
}

$allowed    = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf'];
$allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
$maxSize    = 20 * 1024 * 1024;

if (empty($_FILES['files']) || $_FILES['files']['error'] !== UPLOAD_ERR_OK) {
    header('Location: ' . $chatUrl);
    exit;
}

$file     = $_FILES['files'];
$mimeType = mime_content_type($file['tmp_name']);

if ($file['size'] > $maxSize || !in_array($mimeType, $allowed, true)) {
    header('Location: ' . $chatUrl);
    exit;
}

// Generate a UUID v4 for the new session
$bytes    = random_bytes(16);
$bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
$bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
$parts     = str_split(bin2hex($bytes), 4);
$sessionId = $parts[0] . $parts[1] . '-' . $parts[2] . '-' . $parts[3] . '-' . $parts[4] . '-' . $parts[5] . $parts[6] . $parts[7];

$safeSession = preg_replace('/[^a-zA-Z0-9\-]/', '', $sessionId);
$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'alfred' . DIRECTORY_SEPARATOR . $safeSession;
if (!is_dir($dir)) {
    mkdir($dir, 0700, true);
}

$fileId  = bin2hex(random_bytes(8));
$origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$safeExt = in_array($origExt, $allowedExt, true) ? $origExt : '';
$filename = $fileId . ($safeExt !== '' ? '.' . $safeExt : '');

if (!move_uploaded_file($file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $filename)) {
    header('Location: ' . $chatUrl);
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

header('Location: ' . $chatUrl . '?' . http_build_query([
    'share_session' => $sessionId,
    'share_file_id' => $fileId,
    'share_name'    => $file['name'],
    'share_type'    => $mimeType,
]));
exit;
