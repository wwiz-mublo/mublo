-- ============================================================
-- Manual Plugin - 도메인별 플러그인 설정
-- ------------------------------------------------------------
-- 설정은 플러그인이 소유한다 — 코어 domain_configs 에 두지 않는다.
--   skin_name : 프론트 열람 화면(/manual) 스킨
-- ============================================================

CREATE TABLE IF NOT EXISTS `plugin_manual_configs` (
    `config_id`  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id`  INT UNSIGNED NOT NULL COMMENT '도메인 ID',
    `skin_name`  VARCHAR(50) NOT NULL DEFAULT 'basic' COMMENT '프론트 스킨',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`config_id`),
    UNIQUE KEY `uk_domain` (`domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='매뉴얼 플러그인 도메인 설정';
