<?php

class alfredMigration
{
    const MIGRATIONS = [
        1 => 'migration_001_initial_schema',
        2 => 'migration_002_memory_label',
    ];

    public static function runPending()
    {
        $current = (int) config::byKey('schemaVersion', 'alfred', 0);
        $target = max(array_keys(self::MIGRATIONS));

        for ($v = $current + 1; $v <= $target; $v++) {
            $method = self::MIGRATIONS[$v];
            self::$method();
            config::save('schemaVersion', $v, 'alfred');
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
        DB::Prepare('ALTER TABLE `alfred_memory` ADD COLUMN IF NOT EXISTS `label` VARCHAR(100) NOT NULL DEFAULT \'\'', [], DB::FETCH_TYPE_ROW);
    }
}
