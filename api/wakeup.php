<?php
/**
 * Alfred wakeup script — called by the background scheduler after a delay.
 *
 * CLI only. Usage:
 *   php wakeup.php --schedule_id=N --delay=N
 *
 * The script sleeps for --delay seconds (0 means no sleep), then loads the
 * schedule row from the DB and runs the agent with a synthetic user message:
 *   "[SCHEDULED] {instruction}"
 *
 * The schedule row is updated to 'running' before the agent starts and to
 * 'done' or 'error' afterward, preventing duplicate executions.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script must be run from the command line.' . PHP_EOL);
}

// ---- Parse arguments ----
$opts       = getopt('', ['schedule_id:', 'delay:']);
$scheduleId = (int)($opts['schedule_id'] ?? 0);
$delay      = (int)($opts['delay']       ?? 0);

if ($scheduleId <= 0) {
    fwrite(STDERR, "wakeup.php: --schedule_id is required and must be a positive integer\n");
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
require_once __DIR__ . '/../core/class/alfredScheduler.class.php';
require_once __DIR__ . '/../core/class/alfredMemory.class.php';
require_once __DIR__ . '/../core/class/alfredAgent.class.php';

// ---- Load schedule row ----
$row = DB::Prepare(
    'SELECT * FROM alfred_schedule WHERE id = :id LIMIT 1',
    [':id' => $scheduleId],
    DB::FETCH_TYPE_ROW
) ?: null;

if ($row === null) {
    log::add('alfred_cron', 'error', "schedule #{$scheduleId}: row not found");
    exit(1);
}

if ($row['status'] !== 'pending') {
    // Already executed or cancelled — exit silently to prevent duplicates
    exit(0);
}

log::add('alfred_cron', 'info', "=== schedule #{$scheduleId} starting: {$row['instruction']} ===");

// ---- Resolve user from session ----
$userLogin  = alfredConversation::getUserLogin($row['session_id']);
$userProfil = null;
if ($userLogin !== null) {
    try {
        $u = user::byLogin($userLogin);
        $userProfil = ($u && $u->getProfils() === 'admin') ? 'admin' : 'user';
    } catch (Exception $ignored) {}
}
log::add('alfred_cron', 'info', "schedule #{$scheduleId}: user={$userLogin}, session={$row['session_id']}");

// ---- Run agent ----
alfredScheduler::markRunning($scheduleId);

try {
    $agent = new alfredAgent(null, null, null, $userLogin, $userProfil);
    $agent->run($row['session_id'], '[SCHEDULED] ' . $row['instruction']);
    alfredScheduler::markDone($scheduleId);
    log::add('alfred_cron', 'info', "=== schedule #{$scheduleId} done ===");
} catch (Exception $e) {
    alfredScheduler::markError($scheduleId, $e->getMessage());
    log::add('alfred_cron', 'error', "schedule #{$scheduleId} failed: " . $e->getMessage());
    exit(1);
}
