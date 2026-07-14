-- ====================================
-- BoardReport - 게시글 신고
-- ====================================

CREATE TABLE IF NOT EXISTS board_reports (
    report_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    article_id BIGINT UNSIGNED NOT NULL,
    board_id INT UNSIGNED NOT NULL,
    article_title VARCHAR(255) NOT NULL DEFAULT '' COMMENT '신고 시점 스냅샷',
    reason VARCHAR(50) NOT NULL COMMENT 'spam|abuse|adult|privacy|etc',
    detail TEXT NULL,
    reporter_id INT UNSIGNED NULL,
    reporter_ip VARCHAR(45) NULL,
    status ENUM('pending', 'resolved', 'dismissed') NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_domain_status (domain_id, status),
    INDEX idx_article (article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS board_report_blinds (
    blind_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    article_id BIGINT UNSIGNED NOT NULL,
    reason VARCHAR(255) NOT NULL DEFAULT '신고 누적으로 블라인드 처리된 게시글입니다.',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_domain_article (domain_id, article_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
