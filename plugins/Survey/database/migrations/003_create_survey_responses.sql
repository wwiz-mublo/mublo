CREATE TABLE IF NOT EXISTS `survey_responses` (
    `response_id`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `survey_id`    INT UNSIGNED NOT NULL,
    `member_id`    INT UNSIGNED NULL DEFAULT NULL,
    `ip_address`   VARCHAR(45) NOT NULL DEFAULT '',
    `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`response_id`),
    KEY `idx_survey_responses_survey`    (`survey_id`),
    KEY `idx_survey_responses_member`    (`survey_id`, `member_id`),
    KEY `idx_survey_responses_ip`        (`survey_id`, `ip_address`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
