-- 도메인별 팝업 설정
CREATE TABLE IF NOT EXISTS plugin_popup_configs (
    config_id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL COMMENT '도메인 ID',
    popup_skin VARCHAR(50) NOT NULL DEFAULT 'basic' COMMENT '팝업 스킨',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_domain (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 팝업 아이템
CREATE TABLE IF NOT EXISTS popups (
    popup_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL DEFAULT 1 COMMENT '도메인 ID',
    title VARCHAR(200) NOT NULL DEFAULT '' COMMENT '관리용 제목',
    html_content TEXT DEFAULT NULL COMMENT 'HTML 콘텐츠 (에디터)',
    link_url VARCHAR(500) DEFAULT NULL COMMENT '클릭 링크',
    link_target VARCHAR(10) NOT NULL DEFAULT '_self' COMMENT '링크 타겟',
    position VARCHAR(20) NOT NULL DEFAULT 'center' COMMENT '표시 위치',
    width INT NOT NULL DEFAULT 500 COMMENT '팝업 너비 (px)',
    height INT NOT NULL DEFAULT 0 COMMENT '팝업 높이 (px, 0=자동)',
    display_device ENUM('all','pc','mo') NOT NULL DEFAULT 'all' COMMENT '표시 디바이스',
    start_date DATE DEFAULT NULL COMMENT '시작일',
    end_date DATE DEFAULT NULL COMMENT '종료일',
    hide_duration INT NOT NULL DEFAULT 24 COMMENT '안 보기 유지 시간 (hours, 0=매번 표시)',
    sort_order INT NOT NULL DEFAULT 0 COMMENT '표시 순서',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '활성화',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_domain_active (domain_id, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
