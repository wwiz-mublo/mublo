-- ============================================================
-- FAQ Plugin - FAQ 테이블 생성
-- ============================================================

-- FAQ 카테고리
CREATE TABLE IF NOT EXISTS `faq_categories` (
    `category_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `category_name` VARCHAR(100) NOT NULL COMMENT '카테고리명',
    `category_slug` VARCHAR(100) NOT NULL COMMENT 'URL용 슬러그',
    `sort_order`    INT NOT NULL DEFAULT 0,
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`category_id`),
    UNIQUE KEY `uk_domain_slug` (`domain_id`, `category_slug`),
    KEY `idx_domain_active_order` (`domain_id`, `is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='FAQ 카테고리';

-- FAQ 항목
CREATE TABLE IF NOT EXISTS `faq_items` (
    `faq_id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `category_id`   INT UNSIGNED NOT NULL,
    `question`      VARCHAR(500) NOT NULL COMMENT '질문',
    `answer`        TEXT NOT NULL COMMENT '답변',
    `sort_order`    INT NOT NULL DEFAULT 0,
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`faq_id`),
    KEY `idx_domain_category` (`domain_id`, `category_id`, `is_active`, `sort_order`),
    FOREIGN KEY (`category_id`) REFERENCES `faq_categories`(`category_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='FAQ 항목';
