<?php
/**
 * Alfred — SSE Chat endpoint
 *
 * Accepts POST with { session_id, message } and streams back events:
 *   event: tool_call  — agent is calling a JeedomMCP tool
 *   event: delta      — LLM text chunk
 *   event: done       — conversation turn complete
 *   event: error      — agent error
 *
 * Authentication: Jeedom session cookie (isConnect check).
 *
 * Implementation: Phase 5
 */

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

if (!isConnect()) {
    echo "event: error\ndata: " . json_encode(['message' => '401 - Unauthorized']) . "\n\n";
    flush();
    exit;
}

// --- Not yet implemented ---
echo "event: error\ndata: " . json_encode(['message' => 'Alfred chat endpoint not yet implemented (Phase 5).']) . "\n\n";
flush();
