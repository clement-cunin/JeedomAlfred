<?php
/**
 * Alfred async task wakeup script — called by the background scheduler after a delay.
 *
 * CLI only. Usage:
 *   php wakeup.php --task_id=N --delay=N
 *
 * Sleeps for --delay seconds, then loads the task from alfred_async_task and
 * executes it. Marks the task running before execution, then done or error after.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.' . PHP_EOL);
}

// ---- Parse arguments ----
$opts   = getopt('', ['task_id:', 'delay:']);
$taskId = (int)($opts['task_id'] ?? 0);
$delay  = (int)($opts['delay']   ?? 0);

if ($taskId <= 0) {
    fwrite(STDERR, "wakeup.php: --task_id is required and must be a positive integer\n");
    exit(1);
}

// ---- Sleep (background strategy: precise to the second) ----
if ($delay > 0) {
    sleep($delay);
}

// ---- Bootstrap Jeedom ----
require_once dirname(__FILE__) . '/../../../core/php/core.inc.php';

// ---- Load Alfred classes ----
require_once __DIR__ . '/../core/class/alfred.class.php';
require_once __DIR__ . '/../core/class/alfredLLM.class.php';
require_once __DIR__ . '/../core/class/alfredMCP.class.php';
require_once __DIR__ . '/../core/class/alfredMCPRegistry.class.php';
require_once __DIR__ . '/../core/class/alfredConversation.class.php';
require_once __DIR__ . '/../core/class/alfredAsyncTask.class.php';
require_once __DIR__ . '/../core/class/alfredMemory.class.php';
require_once __DIR__ . '/../core/class/alfredAgent.class.php';

// ---- Load task ----
$task = alfredAsyncTask::getTask($taskId);

if ($task === null) {
    log::add('alfred_cron', 'error', "wakeup: task #{$taskId} not found");
    exit(1);
}

if ($task['status'] !== 'pending') {
    // Already executed or cancelled — exit silently to prevent duplicates
    exit(0);
}

$payload     = json_decode($task['payload'] ?? '{}', true);
$instruction = $payload['instruction'] ?? '';

log::add('alfred_cron', 'info', "=== wakeup task #{$taskId} starting: {$instruction} ===");

// ---- Resolve user from session ----
$userLogin  = alfredConversation::getUserLogin($task['session_id']);
$userProfil = null;
if ($userLogin !== null) {
    try {
        $u = user::byLogin($userLogin);
        $userProfil = ($u && $u->getProfils() === 'admin') ? 'admin' : 'user';
    } catch (Exception $ignored) {}
}
log::add('alfred_cron', 'info', "wakeup task #{$taskId}: user={$userLogin}, session={$task['session_id']}");

// ---- Run ----
alfredAsyncTask::markRunning($taskId);

try {
    $agent = new alfredAgent(null, null, null, $userLogin, $userProfil);
    $agent->run($task['session_id'], '[SCHEDULED] ' . $instruction);
    alfredAsyncTask::resolve($taskId);
    log::add('alfred_cron', 'info', "=== wakeup task #{$taskId} done ===");
} catch (Exception $e) {
    alfredAsyncTask::fail($taskId, $e->getMessage());
    log::add('alfred_cron', 'error', "wakeup task #{$taskId} failed: " . $e->getMessage());
    exit(1);
}
