-- ====================================
-- Shop Package - 기획전
-- ====================================

-- 1. shop_exhibitions (기획전 마스터)
CREATE TABLE IF NOT EXISTS shop_exhibitions (
    exhibition_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '기획전 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    -- 기본 정보
    title VARCHAR(100) NOT NULL COMMENT '기획전명',
    description TEXT NULL COMMENT '설명',
    slug VARCHAR(100) NULL COMMENT 'URL 슬러그',
    banner_image VARCHAR(255) NULL COMMENT '배너 이미지',
    banner_mobile_image VARCHAR(255) NULL COMMENT '모바일 배너 이미지',

    -- 기간
    start_date DATETIME NULL COMMENT '시작일',
    end_date DATETIME NULL COMMENT '종료일',

    -- 설정
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '정렬 순서',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_domain (domain_id),
    INDEX idx_slug (slug),
    INDEX idx_active_date (is_active, start_date, end_date),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='기획전';

-- 2. shop_exhibition_items (기획전 상품)
CREATE TABLE IF NOT EXISTS shop_exhibition_items (
    item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '항목 ID',
    exhibition_id BIGINT UNSIGNED NOT NULL COMMENT '기획전 ID',

    -- 대상 유형
    target_type ENUM('goods', 'category') NOT NULL COMMENT '대상 유형',
    goods_id BIGINT UNSIGNED NULL COMMENT '상품 ID (target_type=goods)',
    category_code VARCHAR(20) NULL COMMENT '카테고리 코드 (target_type=category)',

    -- 정렬
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '정렬 순서',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',

    INDEX idx_exhibition (exhibition_id),
    INDEX idx_goods (goods_id),
    INDEX idx_category (category_code),
    FOREIGN KEY (exhibition_id) REFERENCES shop_exhibitions(exhibition_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='기획전 상품';
