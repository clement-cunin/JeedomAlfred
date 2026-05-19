<?php

class alfredMigration
{
    const MIGRATIONS = [
        1 => 'migration_001_baseline',
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

        // Transition: old system used individual versions 1–6; new system squashes them into new v1
        if ($current >= 1 && $current <= 6) {
            log::add('alfred', 'info', "Legacy schema v{$current} — transitioning to new migration system at v1");
            config::save('schemaVersion', 1, 'alfred');
            $current = 1;
        }

        // Reset if any required table is missing (fresh install or schema corruption)
        $required = ['alfred_message', 'alfred_conversation', 'alfred_memory', 'alfred_schedule', 'alfred_llm_call'];
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
                'INSERT INTO `alfred_migration_log` (version, hash, filename) VALUES (:v, :h, :f)',
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
}
