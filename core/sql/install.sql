CREATE TABLE IF NOT EXISTS `alfred_conversation` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `session_id` VARCHAR(36)   NOT NULL,
  `title`      VARCHAR(255)  DEFAULT NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `alfred_message` (
  `id`         INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `session_id` VARCHAR(36)   NOT NULL,
  `role`       ENUM('user','assistant','tool') NOT NULL,
  `content`    LONGTEXT      NOT NULL,
  `metadata`   JSON          DEFAULT NULL,
  `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY (`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `alfred_schedule` (
  `id`          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `session_id`  VARCHAR(36)   NOT NULL,
  `instruction` TEXT          NOT NULL,
  `run_at`      DATETIME      NOT NULL,
  `strategy`    ENUM('background','cron') NOT NULL,
  `status`      ENUM('pending','running','done','error') NOT NULL DEFAULT 'pending',
  `error_msg`   TEXT          DEFAULT NULL,
  `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY (`status`, `run_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;