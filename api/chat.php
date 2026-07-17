<?php
/**
 * Alfred — SSE Chat endpoint
 *
 * GET  ?session_id=<uuid>              → stream a new user message (body in POST)
 * POST { session_id, message }         → start an agent turn, stream events
 *
 * Events streamed:
 *   event: tool_call    data: {"name":"...","input":{}}
 *   event: tool_result  data: {"name":"...","result":...}
 *   event: delta        data: {"text":"..."}
 *   event: done         data: {"text":"...","iterations":N}
 *   event: error        data: {"message":"..."}
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

// SSE headers — must be sent before any output
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');   // disable nginx/Apache buffering
header('Access-Control-Allow-Origin: *');

ob_implicit_flush(true);
if (ob_get_level()) ob_end_flush();

set_time_limit(0);

// ---- Auth ----
// Three accepted auth methods (in priority order), same as api/conversation.php:
// 1. Active Jeedom browser session
// 2. user_hash query parameter
// 3. jeedom::apiAccess() fallback — the user_hash cached client-side (localStorage)
//    can go stale after Jeedom's automatic admin hash rotation
//    (core/class/user.class.php::regenerateHash(), every ~3 months), which makes
//    user::byHash() fail even though the account is otherwise valid.
$userLogin  = null;
$userProfil = 'user';
if (isConnect()) {
    $connectedUser = $_SESSION['user'] ?? null;
    if ($connectedUser) {
        $userLogin  = $connectedUser->getLogin();
        $userProfil = $connectedUser->getProfils() === 'admin' ? 'admin' : 'user';
    }
} else {
    $hash          = trim(init('user_hash'));
    $connectedUser = $hash !== '' ? user::byHash($hash) : null;
    if ($connectedUser) {
        $userLogin  = $connectedUser->getLogin();
        $userProfil = $connectedUser->getProfils() === 'admin' ? 'admin' : 'user';
    } elseif ($hash !== '' && jeedom::apiAccess($hash, 'core')) {
        global $_USER_GLOBAL;
        if (is_object($_USER_GLOBAL)) {
            $userLogin  = $_USER_GLOBAL->getLogin();
            $userProfil = $_USER_GLOBAL->getProfils() === 'admin' ? 'admin' : 'user';
        } else {
            // Core API key — use a synthetic admin identity
            $userLogin  = 'api';
            $userProfil = 'admin';
        }
    }
    if ($userLogin === null) {
        sse_event('error', ['message' => '401 - Unauthorized']);
        exit;
    }
}

// ---- Input ----
$raw       = file_get_contents('php://input');
$input     = json_decode($raw, true) ?? [];
$sessionId       = trim($input['session_id']       ?? init('session_id'));
$message         = trim($input['message']          ?? init('message'));
$extraIterations = max(0, (int)($input['extra_iterations'] ?? (int)init('extra_iterations')));

if ($sessionId === '' || ($message === '' && $extraIterations === 0)) {
    sse_event('error', ['message' => 'Missing session_id or message']);
    exit;
}

// ---- Load classes ----
require_once __DIR__ . '/../core/class/alfred.class.php';
require_once __DIR__ . '/../core/class/alfredLLM.class.php';
require_once __DIR__ . '/../core/class/alfredMCP.class.php';
require_once __DIR__ . '/../core/class/alfredMCPRegistry.class.php';
require_once __DIR__ . '/../core/class/alfredConversation.class.php';
require_once __DIR__ . '/../core/class/alfredAsyncTask.class.php';
require_once __DIR__ . '/../core/class/alfredMemory.class.php';
require_once __DIR__ . '/../core/class/alfredAgent.class.php';

// ---- Run agent ----
try {
    $agent = new alfredAgent(
        null,
        null,
        function (string $type, array $data) {
            sse_event($type, $data);
        },
        $userLogin,
        $userProfil,
        $extraIterations
    );
    $agent->run($sessionId, $message);
} catch (Exception $e) {
    log::add('alfred', 'error', 'Agent error: ' . $e->getMessage());
    if ($sessionId !== '') {
        alfredConversation::addMessage($sessionId, 'assistant', $e->getMessage(), ['error' => true]);
    }
    sse_event('error', ['message' => $e->getMessage()]);
}

// ---- Helpers ----

function sse_event(string $type, array $data): void
{
    $line = 'event: ' . $type . "\n"
          . 'data: '  . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    echo $line;
    if (function_exists('fastcgi_finish_request')) {
        // not applicable in streaming, just flush
    }
    flush();
}
