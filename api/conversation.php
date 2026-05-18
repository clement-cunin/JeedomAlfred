<?php
/**
 * Alfred — Conversation API
 *
 * RESTful API for managing Alfred conversations.
 *
 * Endpoints:
 *   GET  /api/conversation?session_id=<uuid>         → retrieve conversation + messages
 *   POST /api/conversation                             → create a new conversation
 *   POST /api/conversation/<session_id>/message       → add a message
 *   GET  /api/conversation/<session_id>/messages      → list all messages
 *   DELETE /api/conversation/<session_id>             → delete conversation
 *   GET  /api/conversations                           → list conversations
 *
 * Auth: Jeedom session or user_hash parameter
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ---- Auth ----
$connectedUser = null;
if (isConnect()) {
    $connectedUser = $_SESSION['user'] ?? null;
} else {
    $hash          = trim(init('user_hash'));
    $connectedUser = $hash !== '' ? user::byHash($hash) : null;
    if (!$connectedUser) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }
}

$userLogin  = ($connectedUser !== null) ? $connectedUser->getLogin() : null;

if (!$userLogin) {
    http_response_code(401);
    echo json_encode(['error' => 'Unable to identify user']);
    exit;
}

// ---- Load classes ----
require_once __DIR__ . '/../core/class/alfredConversation.class.php';

// ---- Parse request ----
$method = $_SERVER['REQUEST_METHOD'];
$path   = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
$parts  = explode('/', $path);

// Remove base path (api/conversation)
$apiIdx = array_search('conversation', $parts);
if ($apiIdx === false) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

$pathSegments = array_slice($parts, $apiIdx + 1);

try {
    // Route requests
    if (count($pathSegments) === 0) {
        // /api/conversation or /api/conversations
        if ($method === 'GET') {
            handleListConversations($userLogin);
        } elseif ($method === 'POST') {
            handleCreateConversation($userLogin);
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
        }
    } elseif (count($pathSegments) === 1 && $method === 'GET') {
        // /api/conversation/<session_id>
        handleGetConversation($pathSegments[0], $userLogin);
    } elseif (count($pathSegments) === 2 && $pathSegments[1] === 'message' && $method === 'POST') {
        // /api/conversation/<session_id>/message
        handleAddMessage($pathSegments[0], $userLogin);
    } elseif (count($pathSegments) === 2 && $pathSegments[1] === 'messages' && $method === 'GET') {
        // /api/conversation/<session_id>/messages
        handleListMessages($pathSegments[0], $userLogin);
    } elseif (count($pathSegments) === 1 && $method === 'DELETE') {
        // /api/conversation/<session_id>
        handleDeleteConversation($pathSegments[0], $userLogin);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// ---- Handlers ----

/**
 * GET /api/conversations
 * List conversations for the current user.
 */
function handleListConversations(string $userLogin): void
{
    $limit = max(1, (int)init('limit', 50));
    $conversations = alfredConversation::listSessions($limit, $userLogin);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data'    => $conversations,
        'count'   => count($conversations),
    ]);
}

/**
 * POST /api/conversation
 * Create a new conversation.
 *
 * Body: { "title": "..." }
 */
function handleCreateConversation(string $userLogin): void
{
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $title = trim($input['title'] ?? '');

    // Generate UUID v4
    $bytes    = random_bytes(16);
    $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
    $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
    $parts     = str_split(bin2hex($bytes), 4);
    $sessionId = $parts[0] . $parts[1] . '-' . $parts[2] . '-' . $parts[3] . '-' . $parts[4] . '-' . $parts[5] . $parts[6] . $parts[7];

    alfredConversation::createSession($sessionId, $title, $userLogin);
    $session = alfredConversation::getSession($sessionId);

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'data'    => $session,
    ]);
}

/**
 * GET /api/conversation/<session_id>
 * Retrieve a conversation with its messages.
 */
function handleGetConversation(string $sessionId, string $userLogin): void
{
    $session = alfredConversation::getSession($sessionId);

    if (!$session) {
        http_response_code(404);
        echo json_encode(['error' => 'Conversation not found']);
        return;
    }

    if (!alfredConversation::sessionBelongsTo($sessionId, $userLogin)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }

    $messages = alfredConversation::getDisplayMessages($sessionId);

    http_response_code(200);
    echo json_encode([
        'success'  => true,
        'session'  => $session,
        'messages' => $messages,
    ]);
}

/**
 * POST /api/conversation/<session_id>/message
 * Add a message to a conversation.
 *
 * Body: { "content": "...", "role": "user" }
 * Note: only "user" role is accepted via API; assistant messages are created by the agent.
 */
function handleAddMessage(string $sessionId, string $userLogin): void
{
    $session = alfredConversation::getSession($sessionId);

    if (!$session) {
        http_response_code(404);
        echo json_encode(['error' => 'Conversation not found']);
        return;
    }

    if (!alfredConversation::sessionBelongsTo($sessionId, $userLogin)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }

    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $content = $input['content'] ?? '';
    $role    = trim($input['role'] ?? 'user');

    if (!$content) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing content']);
        return;
    }

    if ($role !== 'user') {
        http_response_code(400);
        echo json_encode(['error' => 'Only user role is accepted via API']);
        return;
    }

    $messageId = alfredConversation::addMessage($sessionId, $role, $content);

    http_response_code(201);
    echo json_encode([
        'success'    => true,
        'message_id' => $messageId,
        'content'    => $content,
        'role'       => $role,
    ]);
}

/**
 * GET /api/conversation/<session_id>/messages
 * List all messages in a conversation.
 */
function handleListMessages(string $sessionId, string $userLogin): void
{
    $session = alfredConversation::getSession($sessionId);

    if (!$session) {
        http_response_code(404);
        echo json_encode(['error' => 'Conversation not found']);
        return;
    }

    if (!alfredConversation::sessionBelongsTo($sessionId, $userLogin)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }

    $messages = alfredConversation::getDisplayMessages($sessionId);

    http_response_code(200);
    echo json_encode([
        'success'  => true,
        'messages' => $messages,
        'count'    => count($messages),
    ]);
}

/**
 * DELETE /api/conversation/<session_id>
 * Delete a conversation.
 */
function handleDeleteConversation(string $sessionId, string $userLogin): void
{
    $session = alfredConversation::getSession($sessionId);

    if (!$session) {
        http_response_code(404);
        echo json_encode(['error' => 'Conversation not found']);
        return;
    }

    if (!alfredConversation::sessionBelongsTo($sessionId, $userLogin)) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied']);
        return;
    }

    alfredConversation::deleteSession($sessionId);

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Conversation deleted',
    ]);
}
