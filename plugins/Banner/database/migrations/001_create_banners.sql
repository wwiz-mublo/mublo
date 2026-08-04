-- ============================================================
-- Banner Plugin - 배너 테이블 생성
-- ============================================================

CREATE TABLE IF NOT EXISTS `banners` (
    `banner_id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `title`         VARCHAR(200) NOT NULL COMMENT '배너 제목 (alt 텍스트)',
    `pc_image_url`  VARCHAR(500) NOT NULL COMMENT 'PC 이미지 경로',
    `mo_image_url`  VARCHAR(500) DEFAULT NULL COMMENT '모바일 이미지 경로 (NULL이면 PC 사용)',
    `link_url`      VARCHAR(500) DEFAULT NULL COMMENT '클릭 시 이동 URL',
    `link_target`   VARCHAR(10) NOT NULL DEFAULT '_self' COMMENT '_self | _blank',
    `sort_order`    INT NOT NULL DEFAULT 0 COMMENT '정렬 순서',
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `start_date`    DATE DEFAULT NULL COMMENT '노출 시작일 (NULL=즉시)',
    `end_date`      DATE DEFAULT NULL COMMENT '노출 종료일 (NULL=무기한)',
    `extras`        JSON DEFAULT NULL COMMENT '확장 데이터 (패키지용 JSON)',
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`banner_id`),
    KEY `idx_domain_active_order` (`domain_id`, `is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='배너';
