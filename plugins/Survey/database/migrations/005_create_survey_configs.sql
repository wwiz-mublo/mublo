-- 설문 플러그인 설정 (도메인별 프론트 스킨)
CREATE TABLE IF NOT EXISTS plugin_survey_configs (
    config_id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL COMMENT '도메인 ID',
    skin_name VARCHAR(50) NOT NULL DEFAULT 'basic' COMMENT '프론트 스킨',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_domain (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
