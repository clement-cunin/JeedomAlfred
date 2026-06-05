<?php
/**
 * Alfred push conversation wakeup — CLI script for push_reflect async tasks.
 *
 * Called by alfredCmd when a scenario triggers "Démarrer une conversation":
 *   nohup php push_wakeup.php --task_id=N >> /log/alfred_cron 2>&1 &
 *
 * Payload fields (set by alfredCmd::execute):
 *   eqLogic_id   Phone equipment id
 *   session_id   Pre-generated UUID for the conversation
 *   name         Conversation title (empty = auto-title from visible msg)
 *   visible      Visible system message shown on the left in the chat
 *   prompt       Hidden LLM context (not displayed to the user)
 *   owner_login  Jeedom user login for the conversation
 *   sub          Push subscription row
 *
 * The visible message and prompt are stored together as a single user message
 * so the LLM sees the full context.  The metadata marks which part to display.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.' . PHP_EOL);
}

$opts   = getopt('', ['task_id:']);
$taskId = (int) ($opts['task_id'] ?? 0);

if ($taskId <= 0) {
    fwrite(STDERR, "push_wakeup.php: --task_id is required\n");
    exit(1);
}

require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';
require_once __DIR__ . '/../core/class/alfred.class.php';
require_once __DIR__ . '/../core/class/alfredLLM.class.php';
require_once __DIR__ . '/../core/class/alfredMCP.class.php';
require_once __DIR__ . '/../core/class/alfredMCPRegistry.class.php';
require_once __DIR__ . '/../core/class/alfredConversation.class.php';
require_once __DIR__ . '/../core/class/alfredAsyncTask.class.php';
require_once __DIR__ . '/../core/class/alfredMemory.class.php';
require_once __DIR__ . '/../core/class/alfredAgent.class.php';
require_once __DIR__ . '/../core/class/alfredPush.class.php';

$task = alfredAsyncTask::getTask($taskId);
if (!$task) {
    log::add('alfred_cron', 'error', "push_wakeup: task #{$taskId} not found");
    exit(1);
}
if ($task['status'] !== 'pending') {
    exit(0);
}

$payload    = json_decode($task['payload'] ?? '{}', true) ?: [];
$eqLogicId  = (int)   ($payload['eqLogic_id']  ?? 0);
$sessionId  = (string)($payload['session_id']  ?? '');
$name       = (string)($payload['name']        ?? '');
$visible    = (string)($payload['visible']     ?? '');
$prompt     = (string)($payload['prompt']      ?? '');
$ownerLogin = (string)($payload['owner_login'] ?? '') ?: null;
$sub        = $payload['sub'] ?? null;

if (!$eqLogicId || $visible === '' || !$sub) {
    alfredAsyncTask::fail($taskId, 'Invalid push_reflect payload');
    exit(1);
}
if ($sessionId === '') {
    $sessionId = alfred::generateSessionId();
}

alfredAsyncTask::markRunning($taskId);
log::add('alfred_cron', 'info', "=== push_conversation #{$taskId}: '{$visible}' ===");

try {
    // Create the conversation
    $convName = $name !== '' ? $name : alfredConversation::autoTitle($visible);
    alfredConversation::createSession($sessionId, $convName, $ownerLogin ?: null);

    // Build the message sent to the LLM: visible part + hidden prompt
    // The content stored is the full text; only the visible part is shown in the UI.
    $combined = $visible;
    if ($prompt !== '') {
        $combined .= "\n\n" . $prompt;
    }

    // Run the agent — this saves the user message and generates Alfred's response
    $finalText = '';
    $agent     = new alfredAgent(null, null, function (string $type, array $data) use (&$finalText) {
        if ($type === 'done') {
            $finalText = $data['text'] ?? '';
        }
    }, $ownerLogin ?: null);
    $agent->run($sessionId, $combined);

    // Post-hoc: mark the first user message as a scenario notification so the UI
    // displays only the visible part (not the hidden prompt) with the correct style.
    if ($prompt !== '') {
        DB::Prepare(
            'UPDATE `alfred_message`
             SET `metadata` = :meta
             WHERE `session_id` = :s AND `role` = \'user\'
             ORDER BY `id` ASC LIMIT 1',
            [
                ':meta' => json_encode(['type' => 'scenario', 'display' => $visible]),
                ':s'    => $sessionId,
            ],
            DB::FETCH_TYPE_ROW
        );
    } else {
        DB::Prepare(
            'UPDATE `alfred_message`
             SET `metadata` = :meta
             WHERE `session_id` = :s AND `role` = \'user\'
             ORDER BY `id` ASC LIMIT 1',
            [
                ':meta' => json_encode(['type' => 'scenario', 'display' => $visible]),
                ':s'    => $sessionId,
            ],
            DB::FETCH_TYPE_ROW
        );
    }

    // Save notification and send push
    $pushText = $finalText !== '' ? $finalText : $visible;
    alfredPush::saveNotification($eqLogicId, 'Alfred', $pushText, $sessionId);
    $sent = alfredPush::send($sub);

    alfredAsyncTask::resolve($taskId, ['session_id' => $sessionId, 'sent' => $sent]);
    log::add('alfred_cron', 'info', "=== push_conversation #{$taskId} done (sent={$sent}) ===");
} catch (Exception $e) {
    alfredAsyncTask::fail($taskId, $e->getMessage());
    log::add('alfred_cron', 'error', "push_conversation #{$taskId} failed: " . $e->getMessage());
    exit(1);
}
