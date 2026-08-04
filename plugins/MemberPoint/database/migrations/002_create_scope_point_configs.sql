-- MemberPoint 스코프별(그룹/게시판) 포인트 설정 테이블
CREATE TABLE IF NOT EXISTS plugin_member_point_scope_configs (
    config_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id BIGINT UNSIGNED NOT NULL,
    scope_type VARCHAR(20) NOT NULL COMMENT 'group 또는 board',
    scope_id INT UNSIGNED NOT NULL,
    config_data JSON NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_domain_scope (domain_id, scope_type, scope_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
