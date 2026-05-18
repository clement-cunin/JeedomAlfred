<?php
/**
 * Alfred — Conversation API
 *
 * RESTful API for managing Alfred conversations.
 *
 * Endpoints (all via conversation.php):
 *   GET    ?                                     → list conversations
 *   POST   ?                                     → create a new conversation   body: {title}
 *   GET    ?session_id=<uuid>                    → get conversation + messages
 *   POST   ?session_id=<uuid>&action=message     → add a message               body: {content}
 *   GET    ?session_id=<uuid>&action=messages    → list messages
 *   DELETE ?session_id=<uuid>                    → delete conversation
 *
 * Auth: Jeedom session, user_hash param, or X-API-Key header (Jeedom core API key)
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ---- Auth ----
// Three accepted auth methods (in priority order):
// 1. Active Jeedom browser session
// 2. user_hash query parameter
// 3. X-API-Key header (Jeedom core API key or user hash)
$connectedUser = null;
$userLogin     = null;

if (isConnect()) {
    $connectedUser = $_SESSION['user'] ?? null;
    $userLogin     = $connectedUser ? $connectedUser->getLogin() : null;
} else {
    // Try user_hash param first, then X-API-Key header
    $apiKey = trim($_SERVER['HTTP_X_API_KEY'] ?? init('user_hash'));
    if ($apiKey !== '') {
        // jeedom::apiAccess sets $_USER_GLOBAL if the key is a valid user hash
        if (jeedom::apiAccess($apiKey, 'core')) {
            global $_USER_GLOBAL;
            if (is_object($_USER_GLOBAL)) {
                $userLogin = $_USER_GLOBAL->getLogin();
            } else {
                // Core API key — use a synthetic admin identity
                $userLogin = 'api';
            }
        }
    }
}

if (!$userLogin) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ---- Load classes ----
require_once __DIR__ . '/../core/class/alfred.class.php';
require_once __DIR__ . '/../core/class/alfredLLM.class.php';
require_once __DIR__ . '/../core/class/alfredMCP.class.php';
require_once __DIR__ . '/../core/class/alfredMCPRegistry.class.php';
require_once __DIR__ . '/../core/class/alfredConversation.class.php';
require_once __DIR__ . '/../core/class/alfredScheduler.class.php';
require_once __DIR__ . '/../core/class/alfredMemory.class.php';
require_once __DIR__ . '/../core/class/alfredAgent.class.php';

set_time_limit(120);

// ---- Route request ----
$method    = $_SERVER['REQUEST_METHOD'];
$sessionId = trim(init('session_id'));
$action    = trim(init('action'));

try {
    if ($sessionId === '') {
        // No session_id — list or create
        if ($method === 'GET') {
            handleListConversations($userLogin);
        } elseif ($method === 'POST') {
            handleCreateConversation($userLogin);
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
        }
    } elseif ($action === 'message' && $method === 'POST') {
        handleAddMessage($sessionId, $userLogin);
    } elseif ($action === 'messages' && $method === 'GET') {
        handleListMessages($sessionId, $userLogin);
    } elseif ($method === 'GET') {
        handleGetConversation($sessionId, $userLogin);
    } elseif ($method === 'DELETE') {
        handleDeleteConversation($sessionId, $userLogin);
    } else {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
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
 * POST ?session_id=<uuid>&action=message
 * Send a user message and get Alfred's response.
 *
 * Body: { "content": "..." }
 */
function handleAddMessage(string $sessionId, string $userLogin): void
{
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $content = trim($input['content'] ?? '');

    if ($content === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing content']);
        return;
    }

    // 'api' login (core API key, no real user) acts as admin to bypass ownership check
    $userProfil = ($userLogin === 'api') ? 'admin' : 'user';

    $agent = new alfredAgent(
        null,
        null,
        null,    // no SSE — response captured via return value
        $userLogin,
        $userProfil
    );

    $reply = $agent->run($sessionId, $content);

    http_response_code(200);
    echo json_encode([
        'success'  => true,
        'message'  => $content,
        'reply'    => $reply,
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
