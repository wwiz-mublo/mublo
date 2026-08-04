-- ============================================================
-- 007: Admin Dashboard Layout (사용자별 대시보드 위젯 배치)
-- ============================================================

CREATE TABLE IF NOT EXISTS admin_dashboard_layout (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id   INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    widget_id   VARCHAR(100) NOT NULL,
    `row`       SMALLINT UNSIGNED NULL     COMMENT 'NULL = AUTO 모드',
    col         SMALLINT UNSIGNED NULL     COMMENT 'NULL = AUTO 모드',
    slot_size   TINYINT UNSIGNED NULL      COMMENT 'NULL = 위젯 기본값 사용. MANUAL 모드에서만 의미',
    hidden      TINYINT(1) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_user_widget (domain_id, user_id, widget_id),
    KEY idx_user (domain_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
