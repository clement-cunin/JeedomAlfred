<?php

class alfredMigration
{
    const MIGRATIONS = [
        1 => 'migration_001_initial_schema',
        2 => 'migration_002_memory_label',
        3 => 'migration_003_repair_schema',
        4 => 'migration_004_conversation_user_login',
        5 => 'migration_005_llm_call_tracking',
        6 => 'migration_006_tool_router',
    ];

    public static function runPending()
    {
        // Reset version if any required table is missing (all migrations are idempotent)
        $required = ['alfred_message', 'alfred_conversation', 'alfred_memory', 'alfred_schedule', 'alfred_llm_call', 'alfred_tool_category'];
        foreach ($required as $table) {
            $row = DB::Prepare(
                "SELECT COUNT(*) as cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :tbl",
                [':tbl' => $table],
                DB::FETCH_TYPE_ROW
            );
            if (!isset($row['cnt']) || (int)$row['cnt'] === 0) {
                log::add('alfred', 'info', "Missing table '{$table}', resetting schema version to force migrations");
                config::save('schemaVersion', 0, 'alfred');
                break;
            }
        }

        $current = (int) config::byKey('schemaVersion', 'alfred', 0);
        $target = max(array_keys(self::MIGRATIONS));

        for ($v = $current + 1; $v <= $target; $v++) {
            $method = self::MIGRATIONS[$v];
            log::add('alfred', 'info', "Running migration {$v}: {$method}");
            self::$method();
            config::save('schemaVersion', $v, 'alfred');
            log::add('alfred', 'info', "Migration {$v} complete");
        }
    }

    public static function getVersion()
    {
        return (int) config::byKey('schemaVersion', 'alfred', 0);
    }

    public static function getTargetVersion()
    {
        return max(array_keys(self::MIGRATIONS));
    }

    private static function migration_001_initial_schema()
    {
        DB::Prepare('CREATE TABLE IF NOT EXISTS `alfred_conversation` (
            `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `session_id` VARCHAR(36)   NOT NULL,
            `title`      VARCHAR(255)  DEFAULT NULL,
            `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY (`session_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', [], DB::FETCH_TYPE_ROW);

        DB::Prepare('CREATE TABLE IF NOT EXISTS `alfred_message` (
            `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `session_id` VARCHAR(36)   NOT NULL,
            `role`       ENUM(\'user\',\'assistant\',\'tool\') NOT NULL,
            `content`    LONGTEXT      NOT NULL,
            `metadata`   JSON          DEFAULT NULL,
            `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY (`session_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', [], DB::FETCH_TYPE_ROW);

        DB::Prepare('CREATE TABLE IF NOT EXISTS `alfred_memory` (
            `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
            `scope`      VARCHAR(100)  NOT NULL,
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
    }

    private static function migration_002_memory_label()
    {
        // Use information_schema check to avoid ADD COLUMN IF NOT EXISTS (MySQL compat)
        $row = DB::Prepare(
            "SELECT COUNT(*) as cnt FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'alfred_memory' AND column_name = 'label'",
            [], DB::FETCH_TYPE_ROW
        );
        if (!isset($row['cnt']) || (int)$row['cnt'] === 0) {
            DB::Prepare(
                "ALTER TABLE `alfred_memory` ADD COLUMN `label` VARCHAR(100) NOT NULL DEFAULT ''",
                [], DB::FETCH_TYPE_ROW
            );
        }
    }

    private static function migration_005_llm_call_tracking()
    {
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

    private static function migration_004_conversation_user_login()
    {
        $row = DB::Prepare(
            "SELECT COUNT(*) as cnt FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'alfred_conversation' AND column_name = 'user_login'",
            [], DB::FETCH_TYPE_ROW
        );
        if (!isset($row['cnt']) || (int)$row['cnt'] === 0) {
            DB::Prepare(
                "ALTER TABLE `alfred_conversation` ADD COLUMN `user_login` VARCHAR(100) DEFAULT NULL",
                [], DB::FETCH_TYPE_ROW
            );
        }
    }

    private static function migration_006_tool_router()
    {
        DB::Prepare('CREATE TABLE IF NOT EXISTS `alfred_tool_category` (
            `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `category`   VARCHAR(100) NOT NULL,
            `keywords`   TEXT         NOT NULL DEFAULT \'\',
            `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_category` (`category`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4', [], DB::FETCH_TYPE_ROW);

        $cols = [
            'router_strategy'     => "CHAR(1)            NOT NULL DEFAULT 'A'",
            'tools_total_count'   => 'SMALLINT UNSIGNED   NOT NULL DEFAULT 0',
            'tools_offered_count' => 'SMALLINT UNSIGNED   NOT NULL DEFAULT 0',
            'router_categories'   => "VARCHAR(255)        NOT NULL DEFAULT ''",
        ];
        foreach ($cols as $col => $def) {
            $row = DB::Prepare(
                "SELECT COUNT(*) as cnt FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = 'alfred_llm_call' AND column_name = :col",
                [':col' => $col], DB::FETCH_TYPE_ROW
            );
            if (!isset($row['cnt']) || (int)$row['cnt'] === 0) {
                DB::Prepare("ALTER TABLE `alfred_llm_call` ADD COLUMN `{$col}` {$def}", [], DB::FETCH_TYPE_ROW);
            }
        }
    }

    private static function migration_003_repair_schema()
    {
        // Create alfred_schedule if it was missing from early installations
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

        // Add updated_at to alfred_conversation if missing (use information_schema check for MySQL compat)
        $row = DB::Prepare(
            "SELECT COUNT(*) as cnt FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'alfred_conversation' AND column_name = 'updated_at'",
            [], DB::FETCH_TYPE_ROW
        );
        if (!isset($row['cnt']) || (int)$row['cnt'] === 0) {
            DB::Prepare(
                'ALTER TABLE `alfred_conversation` ADD COLUMN `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
                [], DB::FETCH_TYPE_ROW
            );
            log::add('alfred', 'info', 'Added missing updated_at column to alfred_conversation');
        }
    }
}
