-- ====================================
-- Shop Package - 상품정보 템플릿
-- ====================================

CREATE TABLE IF NOT EXISTS shop_product_info_templates (
    template_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '템플릿 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    category_code VARCHAR(20) NULL DEFAULT NULL COMMENT '카테고리 코드 (NULL=전체)',

    -- 탭 설정
    tab_id VARCHAR(25) NOT NULL DEFAULT '' COMMENT '탭 식별자',
    tab_name VARCHAR(50) NOT NULL DEFAULT '' COMMENT '탭 표시명',

    -- 내용
    subject VARCHAR(255) NOT NULL COMMENT '제목',
    content TEXT NULL COMMENT 'HTML 콘텐츠',

    -- 관리
    status ENUM('Y','N') NOT NULL DEFAULT 'Y' COMMENT '사용 상태',
    sort_order SMALLINT NOT NULL DEFAULT 0 COMMENT '정렬순서',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_domain (domain_id),
    INDEX idx_sort_order (domain_id, sort_order),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품정보 템플릿';
