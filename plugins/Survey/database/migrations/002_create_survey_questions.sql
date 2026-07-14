CREATE TABLE IF NOT EXISTS `survey_questions` (
    `question_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `survey_id`   INT UNSIGNED NOT NULL,
    `type`        ENUM('radio','checkbox','text','textarea','rating','select') NOT NULL,
    `title`       VARCHAR(500) NOT NULL,
    `description` VARCHAR(500) NULL,
    `options`     JSON NULL,
    `required`    TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`  INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`question_id`),
    KEY `idx_survey_questions_survey` (`survey_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
