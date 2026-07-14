-- ============================================================
-- Manual Plugin - 매뉴얼 책/페이지 테이블 생성
-- ============================================================

-- 매뉴얼 책 (하나의 사용설명서 단위)
CREATE TABLE IF NOT EXISTS `manual_books` (
    `book_id`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id`   BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `title`       VARCHAR(100) NOT NULL COMMENT '책 제목',
    `slug`        VARCHAR(100) NOT NULL COMMENT 'URL용 슬러그',
    `description` TEXT NULL COMMENT '책 설명',
    `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`book_id`),
    UNIQUE KEY `uk_domain_slug` (`domain_id`, `slug`),
    KEY `idx_domain` (`domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='매뉴얼 책';

-- 매뉴얼 페이지 (parent_id 자기참조로 깊이 무제한 트리)
CREATE TABLE IF NOT EXISTS `manual_pages` (
    `page_id`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `book_id`    BIGINT UNSIGNED NOT NULL,
    `parent_id`  BIGINT UNSIGNED NULL,
    `title`      VARCHAR(200) NOT NULL COMMENT '페이지 제목',
    `slug`       VARCHAR(100) NOT NULL COMMENT 'URL용 슬러그',
    `content`    LONGTEXT NULL COMMENT '본문 (HTML)',
    `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `depth`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`page_id`),
    KEY `idx_book` (`book_id`),
    KEY `idx_parent` (`parent_id`),
    FOREIGN KEY (`book_id`) REFERENCES `manual_books`(`book_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='매뉴얼 페이지 (트리)';
