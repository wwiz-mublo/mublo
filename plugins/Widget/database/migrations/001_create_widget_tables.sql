-- Widget 플러그인 테이블

-- 1. 위젯 아이템
CREATE TABLE IF NOT EXISTS plugin_widget_items (
    item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL DEFAULT 1 COMMENT '도메인 ID',
    position ENUM('left','right','mobile') NOT NULL DEFAULT 'right' COMMENT '표시 위치',
    item_type ENUM('link','tel') NOT NULL DEFAULT 'link' COMMENT '아이템 타입',
    title VARCHAR(100) NOT NULL DEFAULT '' COMMENT '관리용 제목',
    icon_image VARCHAR(500) DEFAULT NULL COMMENT '아이콘 이미지 URL',
    link_url VARCHAR(500) DEFAULT NULL COMMENT '링크 URL 또는 전화번호',
    link_target VARCHAR(10) NOT NULL DEFAULT '_blank' COMMENT '링크 타겟',
    sort_order INT NOT NULL DEFAULT 0 COMMENT '표시 순서',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '활성화',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_domain_position (domain_id, position, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 위젯 설정 (도메인별 on/off + 포지션별 스킨)
CREATE TABLE IF NOT EXISTS plugin_widget_configs (
    config_id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL COMMENT '도메인 ID',
    left_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'PC 좌측 위젯 사용',
    right_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'PC 우측 위젯 사용',
    mobile_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT '모바일 위젯 사용',
    left_skin VARCHAR(50) NOT NULL DEFAULT 'basic' COMMENT '좌측 스킨',
    right_skin VARCHAR(50) NOT NULL DEFAULT 'basic' COMMENT '우측 스킨',
    mobile_skin VARCHAR(50) NOT NULL DEFAULT 'basic' COMMENT '모바일 스킨',
    left_width SMALLINT UNSIGNED NOT NULL DEFAULT 50 COMMENT 'PC 좌측 위젯 아이콘 크기(px)',
    right_width SMALLINT UNSIGNED NOT NULL DEFAULT 50 COMMENT 'PC 우측 위젯 아이콘 크기(px)',
    mobile_width SMALLINT UNSIGNED NOT NULL DEFAULT 40 COMMENT '모바일 위젯 아이콘 크기(px)',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_domain (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
