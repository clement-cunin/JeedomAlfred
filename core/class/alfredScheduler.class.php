<?php

/**
 * Scheduler for deferred agent re-invocations.
 *
 * Two strategies depending on delay:
 *   background  delay < 15 min  — spawns a background PHP process that sleeps then
 *                                  calls the agent (second-level precision)
 *   cron        delay >= 15 min — stored in DB, picked up by alfred::cron() at
 *                                  run_at time (minute-level precision, acceptable)
 *
 * Usage:
 *   alfredScheduler::schedule($sessionId, 300, 'Turn off the living room light');
 */
class alfredScheduler
{
    /** Delays below this threshold use a background process instead of the cron table. */
    const BACKGROUND_THRESHOLD = 900; // 15 minutes in seconds

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Schedule a deferred re-invocation of the agent.
     *
     * @param string $sessionId     Session to re-invoke on
     * @param int    $delaySeconds  Delay before execution
     * @param string $instruction   What to ask the agent when it wakes up
     * @return array Confirmation with status and human-readable message
     */
    public static function schedule(string $sessionId, int $delaySeconds, string $instruction): array
    {
        $runAt = (new DateTime())->modify("+{$delaySeconds} seconds");

        if ($delaySeconds < self::BACKGROUND_THRESHOLD) {
            $id = self::persist($sessionId, $instruction, $runAt, 'background');
            self::spawnBackground($id, $delaySeconds);
            $strategy = 'background';
        } else {
            self::persist($sessionId, $instruction, $runAt, 'cron');
            $strategy = 'cron';
        }

        return [
            'status'    => 'scheduled',
            'strategy'  => $strategy,
            'run_at'    => $runAt->format('Y-m-d H:i:s'),
            'message'   => self::confirmationMessage($delaySeconds, $instruction),
        ];
    }

    /**
     * Process all pending cron-strategy schedules whose run_at has been reached.
     * Called by alfred::cron() every minute.
     */
    public static function processPending(): void
    {
        $rows = DB::Prepare(
            'SELECT * FROM alfred_schedule
             WHERE strategy = :strategy AND status = :status AND run_at <= NOW()',
            [':strategy' => 'cron', ':status' => 'pending'],
            DB::FETCH_TYPE_ALL
        ) ?: [];

        foreach ($rows as $row) {
            self::execute($row);
        }
    }

    // -------------------------------------------------------------------------
    // Status helpers (called from wakeup.php and processPending)
    // -------------------------------------------------------------------------

    public static function markRunning(int $id): void
    {
        DB::Prepare(
            'UPDATE alfred_schedule SET status = :status WHERE id = :id',
            [':status' => 'running', ':id' => $id],
            DB::FETCH_TYPE_ROW
        );
    }

    public static function markDone(int $id): void
    {
        DB::Prepare(
            'UPDATE alfred_schedule SET status = :status WHERE id = :id',
            [':status' => 'done', ':id' => $id],
            DB::FETCH_TYPE_ROW
        );
    }

    public static function markError(int $id, string $message): void
    {
        DB::Prepare(
            'UPDATE alfred_schedule SET status = :status, error_msg = :msg WHERE id = :id',
            [':status' => 'error', ':msg' => $message, ':id' => $id],
            DB::FETCH_TYPE_ROW
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Persist a schedule record and return its ID.
     */
    private static function persist(
        string $sessionId,
        string $instruction,
        DateTime $runAt,
        string $strategy
    ): int {
        DB::Prepare(
            'INSERT INTO alfred_schedule (session_id, instruction, run_at, strategy)
             VALUES (:session_id, :instruction, :run_at, :strategy)',
            [
                ':session_id'  => $sessionId,
                ':instruction' => $instruction,
                ':run_at'      => $runAt->format('Y-m-d H:i:s'),
                ':strategy'    => $strategy,
            ],
            DB::FETCH_TYPE_ROW
        );
        return (int)DB::getLastInsertId();
    }

    /**
     * Spawn a background PHP process that sleeps for $delaySeconds then runs wakeup.php.
     */
    private static function spawnBackground(int $scheduleId, int $delaySeconds): void
    {
        $php = PHP_BINARY !== '' ? PHP_BINARY : trim((string)shell_exec('which php 2>/dev/null'));
        if ($php === '') {
            log::add('alfred_cron', 'error', "schedule #{$scheduleId}: cannot find PHP binary, background spawn aborted");
            return;
        }
        // __DIR__ = core/class/  →  dirname x2 = plugin root
        $script = dirname(__DIR__, 2) . '/api/wakeup.php';
        // dirname x4: core/class → core → plugin root → plugins → jeedom root
        $jeedomRoot = dirname(__DIR__, 4);
        $logDir  = is_dir($jeedomRoot . '/log') ? $jeedomRoot . '/log' : sys_get_temp_dir();
        $logFile = $logDir . '/alfred_cron';

        $cmd = escapeshellarg($php)
             . ' ' . escapeshellarg($script)
             . ' --schedule_id=' . (int)$scheduleId
             . ' --delay=' . (int)$delaySeconds;

        exec('nohup ' . $cmd . ' >> ' . escapeshellarg($logFile) . ' 2>&1 &');
        log::add('alfred_cron', 'info', "schedule #{$scheduleId} spawned (php={$php})");
    }

    /**
     * Execute a schedule row inline (used for cron strategy).
     */
    private static function execute(array $row): void
    {
        $id = (int)$row['id'];
        log::add('alfred_cron', 'info', "=== schedule #{$id} starting: {$row['instruction']} ===");
        self::markRunning($id);
        try {
            list($userLogin, $userProfil) = self::resolveUser($row['session_id']);
            log::add('alfred_cron', 'info', "schedule #{$id}: user={$userLogin}, session={$row['session_id']}");
            $agent = new alfredAgent(null, null, null, $userLogin, $userProfil);
            $agent->run($row['session_id'], '[SCHEDULED] ' . $row['instruction']);
            self::markDone($id);
            log::add('alfred_cron', 'info', "=== schedule #{$id} done ===");
        } catch (Exception $e) {
            self::markError($id, $e->getMessage());
            log::add('alfred_cron', 'error', "schedule #{$id} failed: " . $e->getMessage());
        }
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

    /**
     * Build a human-readable confirmation string for the given delay.
     */
    private static function confirmationMessage(int $delaySeconds, string $instruction): string
    {
        $h = (int)($delaySeconds / 3600);
        $m = (int)(($delaySeconds % 3600) / 60);
        $s = $delaySeconds % 60;

        $parts = [];
        if ($h > 0) $parts[] = "{$h}h";
        if ($m > 0) $parts[] = "{$m}min";
        if ($s > 0) $parts[] = "{$s}s";
        $human = implode(' ', $parts) ?: '0s';

        return "Scheduled: '{$instruction}' will be executed in {$human}.";
    }
}
