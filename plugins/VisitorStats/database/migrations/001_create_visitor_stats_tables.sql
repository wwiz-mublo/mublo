-- VisitorStats 플러그인 테이블 (5개)

-- 1. 원본 로그 (세션당 일 1회, 30일 보관)
CREATE TABLE IF NOT EXISTS `plugin_visitor_logs` (
    `log_id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id`      INT UNSIGNED NOT NULL DEFAULT 1,
    `session_id`     VARCHAR(128) NOT NULL,
    `member_id`      INT UNSIGNED NULL DEFAULT NULL,
    `ip_address`     VARCHAR(45) NOT NULL DEFAULT '',
    `user_agent`     VARCHAR(500) NOT NULL DEFAULT '',
    `browser`        VARCHAR(30) NOT NULL DEFAULT 'other',
    `os`             VARCHAR(30) NOT NULL DEFAULT 'other',
    `device`         VARCHAR(10) NOT NULL DEFAULT 'pc',
    `referer_url`    VARCHAR(500) NULL DEFAULT NULL,
    `referer_domain` VARCHAR(200) NULL DEFAULT NULL,
    `referer_type`   VARCHAR(20) NOT NULL DEFAULT 'direct',
    `landing_url`    VARCHAR(500) NOT NULL DEFAULT '/',
    `campaign_key`   VARCHAR(100) NULL DEFAULT NULL,
    `is_new`         TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    `visit_date`     DATE NOT NULL,
    `visit_hour`     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`log_id`),
    UNIQUE KEY `uk_session_date` (`domain_id`, `session_id`, `visit_date`),
    KEY `idx_domain_date` (`domain_id`, `visit_date`),
    KEY `idx_domain_ip` (`domain_id`, `ip_address`, `visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 일별 집계
CREATE TABLE IF NOT EXISTS `plugin_visitor_daily` (
    `daily_id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id`         INT UNSIGNED NOT NULL DEFAULT 1,
    `visit_date`        DATE NOT NULL,
    `total_visitors`    INT UNSIGNED NOT NULL DEFAULT 0,
    `total_pageviews`   INT UNSIGNED NOT NULL DEFAULT 0,
    `new_visitors`      INT UNSIGNED NOT NULL DEFAULT 0,
    `return_visitors`   INT UNSIGNED NOT NULL DEFAULT 0,
    `member_visitors`   INT UNSIGNED NOT NULL DEFAULT 0,
    `guest_visitors`    INT UNSIGNED NOT NULL DEFAULT 0,
    `pc_visitors`       INT UNSIGNED NOT NULL DEFAULT 0,
    `mobile_visitors`   INT UNSIGNED NOT NULL DEFAULT 0,
    `tablet_visitors`   INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`daily_id`),
    UNIQUE KEY `uk_domain_date` (`domain_id`, `visit_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 시간대별 집계
CREATE TABLE IF NOT EXISTS `plugin_visitor_hourly` (
    `hourly_id`   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id`   INT UNSIGNED NOT NULL DEFAULT 1,
    `visit_date`  DATE NOT NULL,
    `visit_hour`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `visitors`    INT UNSIGNED NOT NULL DEFAULT 0,
    `pageviews`   INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`hourly_id`),
    UNIQUE KEY `uk_domain_date_hour` (`domain_id`, `visit_date`, `visit_hour`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. 페이지별 집계 (일별)
CREATE TABLE IF NOT EXISTS `plugin_visitor_pages` (
    `page_id`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id`  INT UNSIGNED NOT NULL DEFAULT 1,
    `visit_date` DATE NOT NULL,
    `page_url`   VARCHAR(500) NOT NULL DEFAULT '/',
    `pageviews`  INT UNSIGNED NOT NULL DEFAULT 0,
    `visitors`   INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`page_id`),
    UNIQUE KEY `uk_domain_date_url` (`domain_id`, `visit_date`, `page_url`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. 유입 경로별 집계 (일별)
CREATE TABLE IF NOT EXISTS `plugin_visitor_referrers` (
    `referrer_id`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id`      INT UNSIGNED NOT NULL DEFAULT 1,
    `visit_date`     DATE NOT NULL,
    `referer_type`   VARCHAR(20) NOT NULL DEFAULT 'direct',
    `referer_domain` VARCHAR(200) NOT NULL DEFAULT '',
    `visitors`       INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`referrer_id`),
    UNIQUE KEY `uk_domain_date_type_domain` (`domain_id`, `visit_date`, `referer_type`, `referer_domain`(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. 캠페인 키 설정
CREATE TABLE IF NOT EXISTS `plugin_visitor_campaign_keys` (
    `key_id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `campaign_key`  VARCHAR(100) NOT NULL,
    `group_name`    VARCHAR(100) NOT NULL DEFAULT '',
    `memo`          VARCHAR(500) NULL DEFAULT NULL,
    `is_active`     TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`key_id`),
    UNIQUE KEY `uk_domain_key` (`domain_id`, `campaign_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. 캠페인 키별 일별 집계
CREATE TABLE IF NOT EXISTS `plugin_visitor_campaigns` (
    `campaign_id`   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `visit_date`    DATE NOT NULL,
    `campaign_key`  VARCHAR(100) NOT NULL,
    `visitors`      INT UNSIGNED NOT NULL DEFAULT 0,
    `pageviews`     INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`campaign_id`),
    UNIQUE KEY `uk_domain_date_key` (`domain_id`, `visit_date`, `campaign_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
