<?php

/**
 * Unified async task management for Alfred.
 *
 * Handles any deferred or background operation that needs a pending UI indicator
 * while it executes, then resolves (success/error) and optionally resumes the LLM.
 *
 * Two strategies for schedule-type tasks:
 *   background  delay < 15 min  — spawns a background PHP process
 *   cron        delay >= 15 min — picked up by alfred::cron() at run_at time
 *
 * === Public API for third-party plugins ===
 *
 * schedule() is a stable public contract callable from outside JeedomAlfred.
 * Minimal bootstrap:
 *
 *   require_once '/var/www/html/core/php/core.inc.php';
 *   require_once '/var/www/html/plugins/alfred/core/class/alfredAsyncTask.class.php';
 *   alfredAsyncTask::schedule($sessionId, $delaySeconds, $instruction);
 */
class alfredAsyncTask
{
    const BACKGROUND_THRESHOLD = 900; // 15 minutes

    // -------------------------------------------------------------------------
    // Public API — schedule
    // -------------------------------------------------------------------------

    /**
     * Schedule a deferred re-invocation of the agent.
     *
     * @api Stable public contract — callable from third-party plugins.
     *
     * @param string $sessionId
     * @param int    $delaySeconds
     * @param string $instruction   What to ask the agent when it wakes up
     * @return array{status: string, strategy: string, run_at: string, message: string}
     */
    public static function schedule(string $sessionId, int $delaySeconds, string $instruction): array
    {
        $runAt    = (new DateTime())->modify("+{$delaySeconds} seconds");
        $strategy = $delaySeconds < self::BACKGROUND_THRESHOLD ? 'background' : 'cron';

        $displayText = self::humanDelay($delaySeconds) . " : '{$instruction}'";
        $payload     = [
            'instruction' => $instruction,
            'run_at'      => $runAt->format('Y-m-d H:i:s'),
            'strategy'    => $strategy,
        ];

        $taskId = self::create($sessionId, 'schedule', $displayText, $payload);

        if ($strategy === 'background') {
            self::spawnBackground($taskId, $delaySeconds);
        }

        return [
            'status'   => 'scheduled',
            'strategy' => $strategy,
            'run_at'   => $runAt->format('Y-m-d H:i:s'),
            'message'  => "Scheduled in " . self::humanDelay($delaySeconds) . ": '{$instruction}'",
        ];
    }

    // -------------------------------------------------------------------------
    // Generic task creation (Phase 2 entry point for external plugins)
    // -------------------------------------------------------------------------

    /**
     * Create an async task and its linked pending UI message.
     * Returns the new task ID.
     */
    public static function create(string $sessionId, string $type, string $displayText, array $payload = []): int
    {
        DB::Prepare(
            'INSERT INTO alfred_async_task (session_id, type, display_text, payload)
             VALUES (:session_id, :type, :display_text, :payload)',
            [
                ':session_id'   => $sessionId,
                ':type'         => $type,
                ':display_text' => $displayText,
                ':payload'      => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ],
            DB::FETCH_TYPE_ROW
        );
        $taskId = (int) DB::getLastInsertId();

        $messageId = alfredConversation::createPendingMessage($sessionId, $displayText, $taskId);

        DB::Prepare(
            'UPDATE alfred_async_task SET message_id = :message_id WHERE id = :id',
            [':message_id' => $messageId, ':id' => $taskId],
            DB::FETCH_TYPE_ROW
        );

        return $taskId;
    }

    // -------------------------------------------------------------------------
    // Status transitions
    // -------------------------------------------------------------------------

    public static function markRunning(int $taskId): void
    {
        DB::Prepare(
            'UPDATE alfred_async_task SET status = \'running\', updated_at = NOW() WHERE id = :id',
            [':id' => $taskId],
            DB::FETCH_TYPE_ROW
        );
        $task = self::getTask($taskId);
        if ($task && $task['message_id']) {
            alfredConversation::updatePendingMessage((int) $task['message_id'], 'running');
        }
    }

    public static function resolve(int $taskId, array $result = []): void
    {
        DB::Prepare(
            'UPDATE alfred_async_task SET status = \'done\', result = :result, updated_at = NOW() WHERE id = :id',
            [':result' => json_encode($result, JSON_UNESCAPED_UNICODE), ':id' => $taskId],
            DB::FETCH_TYPE_ROW
        );
        $task = self::getTask($taskId);
        if ($task && $task['message_id']) {
            alfredConversation::updatePendingMessage((int) $task['message_id'], 'done', ['result' => $result]);
        }
    }

    public static function fail(int $taskId, string $errorMsg): void
    {
        DB::Prepare(
            'UPDATE alfred_async_task SET status = \'error\', error_msg = :msg, updated_at = NOW() WHERE id = :id',
            [':msg' => $errorMsg, ':id' => $taskId],
            DB::FETCH_TYPE_ROW
        );
        $task = self::getTask($taskId);
        if ($task && $task['message_id']) {
            alfredConversation::updatePendingMessage((int) $task['message_id'], 'error', ['error_msg' => $errorMsg]);
        }
    }

    // -------------------------------------------------------------------------
    // Cron processing (called from alfred::cron every minute)
    // -------------------------------------------------------------------------

    public static function processPending(): void
    {
        $rows = DB::Prepare(
            "SELECT * FROM alfred_async_task
             WHERE type = 'schedule' AND status = 'pending'
               AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.strategy')) = 'cron'
               AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.run_at')) <= NOW()",
            [],
            DB::FETCH_TYPE_ALL
        ) ?: [];

        foreach ($rows as $row) {
            self::executeSchedule($row);
        }
    }

    // -------------------------------------------------------------------------
    // Task query
    // -------------------------------------------------------------------------

    public static function getTask(int $taskId): ?array
    {
        $row = DB::Prepare(
            'SELECT * FROM alfred_async_task WHERE id = :id LIMIT 1',
            [':id' => $taskId],
            DB::FETCH_TYPE_ROW
        );
        return $row ?: null;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private static function executeSchedule(array $row): void
    {
        $taskId      = (int) $row['id'];
        $payload     = json_decode($row['payload'] ?? '{}', true);
        $instruction = $payload['instruction'] ?? '';

        log::add('alfred_cron', 'info', "=== async task #{$taskId} starting: {$instruction} ===");
        self::markRunning($taskId);

        try {
            list($userLogin, $userProfil) = self::resolveUser($row['session_id']);
            $agent = new alfredAgent(null, null, null, $userLogin, $userProfil);
            $agent->run($row['session_id'], '[SCHEDULED] ' . $instruction);
            self::resolve($taskId);
            log::add('alfred_cron', 'info', "=== async task #{$taskId} done ===");
        } catch (Exception $e) {
            self::fail($taskId, $e->getMessage());
            log::add('alfred_cron', 'error', "async task #{$taskId} failed: " . $e->getMessage());
        }
    }

    private static function spawnBackground(int $taskId, int $delaySeconds): void
    {
        $php = PHP_BINARY !== '' ? PHP_BINARY : trim((string) shell_exec('which php 2>/dev/null'));
        if ($php === '') {
            log::add('alfred_cron', 'error', "task #{$taskId}: cannot find PHP binary, spawn aborted");
            return;
        }
        $script     = dirname(__DIR__, 2) . '/api/wakeup.php';
        $jeedomRoot = dirname(__DIR__, 4);
        $logDir     = is_dir($jeedomRoot . '/log') ? $jeedomRoot . '/log' : sys_get_temp_dir();
        $logFile    = $logDir . '/alfred_cron';

        $cmd = escapeshellarg($php)
             . ' ' . escapeshellarg($script)
             . ' --task_id=' . $taskId
             . ' --delay=' . $delaySeconds;

        exec('nohup ' . $cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &');
        log::add('alfred_cron', 'info', "task #{$taskId} spawned (php={$php})");
    }

    private static function resolveUser(string $sessionId): array
    {
        $userLogin = alfredConversation::getUserLogin($sessionId);
        if ($userLogin === null) {
            return [null, null];
        }
        $userProfil = 'user';
        try {
            $u = user::byLogin($userLogin);
            if ($u && $u->getProfils() === 'admin') {
                $userProfil = 'admin';
            }
        } catch (Exception $ignored) {}
        return [$userLogin, $userProfil];
    }

    private static function humanDelay(int $delaySeconds): string
    {
        $h = (int) ($delaySeconds / 3600);
        $m = (int) (($delaySeconds % 3600) / 60);
        $s = $delaySeconds % 60;

        $parts = [];
        if ($h > 0) $parts[] = "{$h}h";
        if ($m > 0) $parts[] = "{$m}min";
        if ($s > 0) $parts[] = "{$s}s";
        return implode(' ', $parts) ?: '0s';
    }
}
