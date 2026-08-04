CREATE TABLE IF NOT EXISTS `survey_answers` (
    `answer_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `response_id` INT UNSIGNED NOT NULL,
    `question_id` INT UNSIGNED NOT NULL,
    `answer_text` TEXT NULL,
    `answer_opts` JSON NULL,
    PRIMARY KEY (`answer_id`),
    KEY `idx_survey_answers_response`  (`response_id`),
    KEY `idx_survey_answers_question`  (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
