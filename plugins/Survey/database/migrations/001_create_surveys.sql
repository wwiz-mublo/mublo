CREATE TABLE IF NOT EXISTS `surveys` (
    `survey_id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id`       INT UNSIGNED NOT NULL,
    `title`           VARCHAR(200) NOT NULL,
    `description`     TEXT NULL,
    `status`          ENUM('draft','active','closed') NOT NULL DEFAULT 'draft',
    `allow_anonymous` TINYINT(1) NOT NULL DEFAULT 1,
    `allow_duplicate` TINYINT(1) NOT NULL DEFAULT 0,
    `response_limit`  INT UNSIGNED NOT NULL DEFAULT 0,
    `start_at`        DATETIME NULL,
    `end_at`          DATETIME NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`survey_id`),
    KEY `idx_surveys_domain_status` (`domain_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
