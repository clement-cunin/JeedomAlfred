<?php

class alfredMigration
{
    const MIGRATIONS = [
        1 => 'migration_001_baseline',
        2 => 'migration_002_async_tasks',
        3 => 'migration_003_push_notifications',
        4 => 'migration_004_memory_expiry',
    ];

    private static function downDir(): string
    {
        return __DIR__ . '/../../var/migrations/';
    }

    private static function ensureLogTable(): void
    {
        DB::Prepare(
            'CREATE TABLE IF NOT EXISTS `alfred_migration_log` (
                `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `version`    INT UNSIGNED NOT NULL,
                `hash`       VARCHAR(32)  NOT NULL,
                `filename`   VARCHAR(100) NOT NULL,
                `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_version` (`version`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            [],
            DB::FETCH_TYPE_ROW
        );
    }

    public static function runPending(): void
    {
        self::ensureLogTable();

        $current = (int) config::byKey('schemaVersion', 'alfred', 0);
        $target  = max(array_keys(self::MIGRATIONS));

        // Transition: old system had individual versions; new system squashes them into new v1
        if ($current >= 1 && $current > $target) {
            log::add('alfred', 'info', "Legacy schema v{$current} — transitioning to new migration system at v1");
            config::save('schemaVersion', 1, 'alfred');
            $current = 1;
        }

        // Reset if any required table is missing (fresh install or schema corruption)
        $required = ['alfred_message', 'alfred_conversation', 'alfred_memory', 'alfred_async_task', 'alfred_llm_call'];
        foreach ($required as $table) {
            $row = DB::Prepare(
                "SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :tbl",
                [':tbl' => $table],
                DB::FETCH_TYPE_ROW
            );
            if (!isset($row['cnt']) || (int)$row['cnt'] === 0) {
                log::add('alfred', 'info', "Missing table '{$table}' — resetting schema version to force migrations");
                config::save('schemaVersion', 0, 'alfred');
                $current = 0;
                break;
            }
        }

        $target = max(array_keys(self::MIGRATIONS));

        for ($v = $current + 1; $v <= $target; $v++) {
            if (!isset(self::MIGRATIONS[$v])) {
                continue;
            }
            self::applyUp($v);
        }
    }

    /**
     * Roll back migrations from current version down to $targetVersion (exclusive).
     * Requires down files to be present on disk (saved by applyUp at deploy time).
     */
    public static function rollbackTo(int $targetVersion): void
    {
        $current = self::getVersion();
        if ($targetVersion >= $current) {
            log::add('alfred', 'warning', "rollbackTo({$targetVersion}) called but current version is {$current} — nothing to do");
            return;
        }

        for ($v = $current; $v > $targetVersion; $v--) {
            self::applyDown($v);
        }
    }

    public static function getVersion(): int
    {
        return (int) config::byKey('schemaVersion', 'alfred', 0);
    }

    public static function getTargetVersion(): int
    {
        return max(array_keys(self::MIGRATIONS));
    }

    private static function applyUp(int $v): void
    {
        $method = self::MIGRATIONS[$v];
        log::add('alfred', 'info', "Running migration {$v}: {$method}");
        self::$method();

        $downMethod = $method . '_down';
        if (method_exists(__CLASS__, $downMethod)) {
            $downSql = self::$downMethod();
            $hash     = md5($downSql);
            $filename = "V{$v}_{$hash}.sql";
            $dir      = self::downDir();
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($dir . $filename, $downSql);
            DB::Prepare(
                'INSERT IGNORE INTO `alfred_migration_log` (version, hash, filename) VALUES (:v, :h, :f)',
                [':v' => $v, ':h' => $hash, ':f' => $filename],
                DB::FETCH_TYPE_ROW
            );
            log::add('alfred', 'info', "Down file saved: {$filename}");
        }

        config::save('schemaVersion', $v, 'alfred');
        log::add('alfred', 'info', "Migration {$v} complete");
    }

    private static function applyDown(int $v): void
    {
        self::ensureLogTable();

        $row = DB::Prepare(
            'SELECT filename, hash FROM `alfred_migration_log` WHERE version = :v',
            [':v' => $v],
            DB::FETCH_TYPE_ROW
        );
        if (!$row) {
            throw new \RuntimeException("No rollback record for migration {$v} — manual intervention required");
        }

        $file = self::downDir() . $row['filename'];
        if (!file_exists($file)) {
            throw new \RuntimeException("Rollback file missing: {$file} — manual intervention required");
        }

        $sql = file_get_contents($file);
        if (md5($sql) !== $row['hash']) {
            throw new \RuntimeException("Hash mismatch for migration {$v} rollback file — file may be corrupted");
        }

        log::add('alfred', 'info', "Rolling back migration {$v}: {$row['filename']}");
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            DB::Prepare($stmt, [], DB::FETCH_TYPE_ROW);
        }

        DB::Prepare(
            'DELETE FROM `alfred_migration_log` WHERE version = :v',
            [':v' => $v],
            DB::FETCH_TYPE_ROW
        );
        config::save('schemaVersion', $v - 1, 'alfred');
        log::add('alfred', 'info', "Migration {$v} rolled back");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Migration 1 — Full baseline schema (squashes former migrations 1–6)
    // ─────────────────────────────────────────────────────────────────────────

    private static function migration_001_baseline(): void
    {
        DB::Prepare('CREATE TABLE IF NOT EXISTS `alfred_conversation` (
            `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `session_id` VARCHAR(36)   NOT NULL,
            `title`      VARCHAR(255)  DEFAULT NULL,
            `user_login` VARCHAR(100)  DEFAULT NULL,
            `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY (`session_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', [], DB::FETCH_TYPE_ROW);

        DB::Prepare('CREATE TABLE IF NOT EXISTS `alfred_message` (
            `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `session_id` VARCHAR(36)   NOT NULL,
            `role`       ENUM(\'user\',\'assistant\',\'tool\',\'error\') NOT NULL,
            `content`    LONGTEXT      NOT NULL,
            `metadata`   JSON          DEFAULT NULL,
            `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY (`session_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', [], DB::FETCH_TYPE_ROW);

        DB::Prepare('CREATE TABLE IF NOT EXISTS `alfred_memory` (
            `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `scope`      VARCHAR(100)  NOT NULL,
            `label`      VARCHAR(100)  NOT NULL DEFAULT \'\',
            `content`    TEXT          NOT NULL,
            `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY (`scope`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', [], DB::FETCH_TYPE_ROW);

        DB::Prepare('CREATE TABLE IF NOT EXISTS `alfred_schedule` (
            `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `session_id`  VARCHAR(36)   NOT NULL,
            `instruction` TEXT          NOT NULL,
            `run_at`      DATETIME      NOT NULL,
            `strategy`    ENUM(\'background\',\'cron\') NOT NULL,
            `status`      ENUM(\'pending\',\'running\',\'done\',\'error\') NOT NULL DEFAULT \'pending\',
            `error_msg`   TEXT          DEFAULT NULL,
            `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY (`status`, `run_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', [], DB::FETCH_TYPE_ROW);

        DB::Prepare('CREATE TABLE IF NOT EXISTS `alfred_llm_call` (
            `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
            `session_id`    VARCHAR(36)      NOT NULL,
            `message_id`    INT UNSIGNED     DEFAULT NULL,
            `iteration`     TINYINT UNSIGNED NOT NULL DEFAULT 1,
            `provider`      VARCHAR(50)      NOT NULL DEFAULT \'\',
            `model`         VARCHAR(100)     NOT NULL DEFAULT \'\',
            `input_tokens`  INT UNSIGNED     NOT NULL DEFAULT 0,
            `output_tokens` INT UNSIGNED     NOT NULL DEFAULT 0,
            `duration_ms`   INT UNSIGNED     NOT NULL DEFAULT 0,
            `system_chars`  INT UNSIGNED     NOT NULL DEFAULT 0,
            `history_chars` INT UNSIGNED     NOT NULL DEFAULT 0,
            `tools_chars`   INT UNSIGNED     NOT NULL DEFAULT 0,
            `new_res_chars` INT UNSIGNED     NOT NULL DEFAULT 0,
            `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_session` (`session_id`),
            KEY `idx_message` (`message_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', [], DB::FETCH_TYPE_ROW);
    }

    private static function migration_001_baseline_down(): string
    {
        return implode(";\n", [
            'DROP TABLE IF EXISTS `alfred_llm_call`',
            'DROP TABLE IF EXISTS `alfred_schedule`',
            'DROP TABLE IF EXISTS `alfred_memory`',
            'DROP TABLE IF EXISTS `alfred_message`',
            'DROP TABLE IF EXISTS `alfred_conversation`',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Migration 2 — Unified async task table (replaces alfred_schedule)
    //               + 'pending' role on alfred_message
    // ─────────────────────────────────────────────────────────────────────────

    private static function migration_002_async_tasks(): void
    {
        DB::Prepare(
            'ALTER TABLE `alfred_message`
             MODIFY COLUMN `role` ENUM(\'user\',\'assistant\',\'tool\',\'error\',\'pending\') NOT NULL',
            [],
            DB::FETCH_TYPE_ROW
        );

        DB::Prepare(
            'CREATE TABLE IF NOT EXISTS `alfred_async_task` (
                `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `session_id`   VARCHAR(36)   NOT NULL,
                `type`         VARCHAR(64)   NOT NULL DEFAULT \'schedule\',
                `status`       ENUM(\'pending\',\'running\',\'done\',\'error\') NOT NULL DEFAULT \'pending\',
                `display_text` VARCHAR(255)  DEFAULT NULL,
                `message_id`   INT UNSIGNED  DEFAULT NULL,
                `payload`      JSON          DEFAULT NULL,
                `result`       LONGTEXT      DEFAULT NULL,
                `error_msg`    TEXT          DEFAULT NULL,
                `created_at`   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at`   DATETIME      DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY (`session_id`),
                KEY (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            [],
            DB::FETCH_TYPE_ROW
        );

        // Migrate pending/running schedules — done/error are historical noise, skip them
        // Guard: alfred_schedule may not exist on fresh installs (only on upgrades from <=v1)
        $row = DB::Prepare(
            "SELECT COUNT(*) AS cnt FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'alfred_schedule'",
            [],
            DB::FETCH_TYPE_ROW
        );
        if (($row['cnt'] ?? 0) > 0) {
            DB::Prepare(
                'INSERT INTO `alfred_async_task`
                    (session_id, type, status, payload, error_msg, created_at)
                 SELECT
                    session_id,
                    \'schedule\',
                    status,
                    JSON_OBJECT(
                        \'instruction\', instruction,
                        \'run_at\',      DATE_FORMAT(run_at, \'%Y-%m-%d %H:%i:%s\'),
                        \'strategy\',    strategy
                    ),
                    error_msg,
                    created_at
                 FROM `alfred_schedule`
                 WHERE status IN (\'pending\', \'running\')',
                [],
                DB::FETCH_TYPE_ROW
            );
        }

        DB::Prepare('DROP TABLE IF EXISTS `alfred_schedule`', [], DB::FETCH_TYPE_ROW);
    }

    private static function migration_002_async_tasks_down(): string
    {
        return implode(";\n", [
            'DELETE FROM `alfred_message` WHERE `role` = \'pending\'',
            'ALTER TABLE `alfred_message`
             MODIFY COLUMN `role` ENUM(\'user\',\'assistant\',\'tool\',\'error\') NOT NULL',
            'CREATE TABLE IF NOT EXISTS `alfred_schedule` (
                `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `session_id`  VARCHAR(36)   NOT NULL,
                `instruction` TEXT          NOT NULL,
                `run_at`      DATETIME      NOT NULL,
                `strategy`    ENUM(\'background\',\'cron\') NOT NULL,
                `status`      ENUM(\'pending\',\'running\',\'done\',\'error\') NOT NULL DEFAULT \'pending\',
                `error_msg`   TEXT          DEFAULT NULL,
                `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY (`status`, `run_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            'INSERT INTO `alfred_schedule`
                (session_id, instruction, run_at, strategy, status, error_msg, created_at)
             SELECT
                session_id,
                JSON_UNQUOTE(JSON_EXTRACT(payload, \'$.instruction\')),
                JSON_UNQUOTE(JSON_EXTRACT(payload, \'$.run_at\')),
                JSON_UNQUOTE(JSON_EXTRACT(payload, \'$.strategy\')),
                status,
                error_msg,
                created_at
             FROM `alfred_async_task`
             WHERE type = \'schedule\'',
            'DROP TABLE IF EXISTS `alfred_async_task`',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Migration 3 — Web Push tables (subscriptions + notifications)
    // ─────────────────────────────────────────────────────────────────────────

    private static function migration_003_push_notifications(): void
    {
        DB::Prepare(
            'CREATE TABLE IF NOT EXISTS `alfred_push_subscription` (
                `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `eqLogic_id`  INT UNSIGNED  NOT NULL,
                `endpoint`    VARCHAR(500)  NOT NULL,
                `p256dh_key`  VARCHAR(255)  NOT NULL,
                `auth_key`    VARCHAR(255)  NOT NULL,
                `fetch_token` VARCHAR(64)   NOT NULL,
                `user_agent`  VARCHAR(255)  DEFAULT NULL,
                `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_endpoint` (`endpoint`(255)),
                KEY `idx_eqLogic` (`eqLogic_id`),
                KEY `idx_token` (`fetch_token`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            [],
            DB::FETCH_TYPE_ROW
        );

        DB::Prepare(
            'CREATE TABLE IF NOT EXISTS `alfred_push_notification` (
                `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
                `eqLogic_id`  INT UNSIGNED  NOT NULL,
                `session_id`  VARCHAR(36)   DEFAULT NULL,
                `title`       VARCHAR(255)  NOT NULL DEFAULT \'Alfred\',
                `body`        TEXT          DEFAULT NULL,
                `read_at`     DATETIME      DEFAULT NULL,
                `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_eqLogic_unread` (`eqLogic_id`, `read_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            [],
            DB::FETCH_TYPE_ROW
        );
    }

    private static function migration_003_push_notifications_down(): string
    {
        return implode(";\n", [
            'DROP TABLE IF EXISTS `alfred_push_notification`',
            'DROP TABLE IF EXISTS `alfred_push_subscription`',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Migration 4 — Add expires_at column to alfred_memory (for journal expiry)
    // ─────────────────────────────────────────────────────────────────────────

    private static function migration_004_memory_expiry(): void
    {
        $row = DB::Prepare(
            "SELECT COUNT(*) as cnt FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'alfred_memory' AND column_name = 'expires_at'",
            [],
            DB::FETCH_TYPE_ROW
        );
        if (!isset($row['cnt']) || (int)$row['cnt'] === 0) {
            DB::Prepare(
                'ALTER TABLE `alfred_memory` ADD COLUMN `expires_at` DATETIME DEFAULT NULL',
                [],
                DB::FETCH_TYPE_ROW
            );
        }
    }

    private static function migration_004_memory_expiry_down(): string
    {
        return 'ALTER TABLE `alfred_memory` DROP COLUMN `expires_at`';
    }
}
