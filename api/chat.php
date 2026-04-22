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
if (!isConnect()) {
    $hash = trim(init('user_hash'));
    if ($hash === '' || !user::byHash($hash)) {
        sse_event('error', ['message' => '401 - Unauthorized']);
        exit;
    }
}

// ---- Input ----
$raw       = file_get_contents('php://input');
$input     = json_decode($raw, true) ?? [];
$sessionId = trim($input['session_id'] ?? init('session_id'));
$message   = trim($input['message']   ?? init('message'));

if ($sessionId === '' || $message === '') {
    sse_event('error', ['message' => 'Missing session_id or message']);
    exit;
}

// ---- Load classes ----
require_once __DIR__ . '/../core/class/alfred.class.php';
require_once __DIR__ . '/../core/class/alfredLLM.class.php';
require_once __DIR__ . '/../core/class/alfredMCP.class.php';
require_once __DIR__ . '/../core/class/alfredMCPRegistry.class.php';
require_once __DIR__ . '/../core/class/alfredConversation.class.php';
require_once __DIR__ . '/../core/class/alfredAgent.class.php';

// ---- Run agent ----
try {
    $agent = new alfredAgent(
        null,
        null,
        function (string $type, array $data) {
            sse_event($type, $data);
        }
    );
    $agent->run($sessionId, $message);
} catch (Exception $e) {
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
