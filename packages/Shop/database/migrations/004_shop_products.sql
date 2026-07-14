-- ====================================
-- Shop Package - 상품 및 관련 테이블
-- ====================================

-- 1. shop_products (상품 마스터)
CREATE TABLE IF NOT EXISTS shop_products (
    goods_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '상품 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    -- 분류 (shop_category_items.category_code 참조)
    category_code VARCHAR(20) NULL COMMENT '주 카테고리 코드',
    category_code_extra VARCHAR(20) NULL COMMENT '보조 카테고리 코드',

    -- 상품 정보
    item_code VARCHAR(25) NOT NULL COMMENT '상품 코드 (YYYYMM + 시퀀스)',
    goods_name VARCHAR(100) NOT NULL COMMENT '상품명',
    goods_slug VARCHAR(100) NULL COMMENT '상품 슬러그 (SEO)',
    goods_origin VARCHAR(50) NULL COMMENT '원산지',
    goods_manufacturer VARCHAR(50) NULL COMMENT '제조사',
    goods_code VARCHAR(25) NULL COMMENT '자체 관리 코드',
    goods_badge VARCHAR(50) NULL COMMENT '배지 (NEW, SALE 등)',
    goods_icon VARCHAR(25) NULL COMMENT '아이콘명',
    goods_filter VARCHAR(25) NULL COMMENT '필터 태그',
    goods_tags VARCHAR(255) NULL COMMENT '태그 (쉼표 구분)',

    -- 가격
    origin_price INT NOT NULL DEFAULT 0 COMMENT '원가',
    display_price INT NOT NULL DEFAULT 0 COMMENT '판매가',
    discount_type VARCHAR(12) NULL COMMENT '할인 유형 (DEFAULT/NONE/BASIC/LEVEL/PERCENTAGE/FIXED)',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '할인 값',
    discount_level_settings TEXT NULL COMMENT '등급별 할인 설정 (JSON)',

    -- 적립
    reward_type VARCHAR(12) NULL COMMENT '적립 유형',
    reward_value DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '적립 값',
    reward_level_settings TEXT NULL COMMENT '등급별 적립 설정 (JSON)',
    reward_review INT NOT NULL DEFAULT 0 COMMENT '리뷰 적립금',

    -- 재고/옵션
    allowed_coupon TINYINT(1) NOT NULL DEFAULT 1 COMMENT '쿠폰 사용 허용',
    stock_quantity INT NULL DEFAULT NULL COMMENT '재고 수량 (NULL=미관리)',
    option_mode ENUM('NONE', 'SINGLE', 'COMBINATION') NOT NULL DEFAULT 'NONE' COMMENT '옵션 모드',

    -- 배송
    shipping_template_id BIGINT UNSIGNED NULL COMMENT '배송 템플릿 ID',
    shipping_apply_type ENUM('COMBINED', 'SEPARATE') NOT NULL DEFAULT 'COMBINED' COMMENT '배송비 적용 방식',

    -- 판매자
    seller_id BIGINT UNSIGNED NULL COMMENT '판매자 ID',
    support_id BIGINT UNSIGNED NULL COMMENT '공급처 ID',

    -- 상태
    hit INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '조회수',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    UNIQUE KEY uk_item_code (item_code),
    INDEX idx_domain (domain_id),
    INDEX idx_domain_category (domain_id, category_code, is_active),
    INDEX idx_domain_active_date (domain_id, is_active, created_at),
    INDEX idx_category (category_code),
    INDEX idx_name (goods_name),
    INDEX idx_seller (seller_id),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품';

-- 2. shop_product_images (상품 이미지)
CREATE TABLE IF NOT EXISTS shop_product_images (
    image_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '이미지 ID',
    goods_id BIGINT UNSIGNED NOT NULL COMMENT '상품 ID',

    image_url VARCHAR(255) NOT NULL COMMENT '원본 이미지 URL',
    thumbnail_url VARCHAR(255) NULL COMMENT '썸네일 URL',
    webp_url VARCHAR(255) NULL COMMENT 'WebP URL',
    is_main TINYINT(1) NOT NULL DEFAULT 0 COMMENT '대표 이미지 여부',
    sort_order TINYINT NOT NULL DEFAULT 0 COMMENT '정렬 순서',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_goods (goods_id),
    FOREIGN KEY (goods_id) REFERENCES shop_products(goods_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품 이미지';

-- 3. shop_product_details (상품 상세 정보)
CREATE TABLE IF NOT EXISTS shop_product_details (
    detail_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '상세 ID',
    goods_id BIGINT UNSIGNED NOT NULL COMMENT '상품 ID',

    detail_type VARCHAR(50) NOT NULL COMMENT '상세 유형',
    detail_value MEDIUMTEXT NOT NULL COMMENT '상세 내용 (HTML)',
    lang_code VARCHAR(10) NOT NULL DEFAULT 'ko' COMMENT '언어 코드',
    sort_order TINYINT NOT NULL DEFAULT 0 COMMENT '정렬 순서',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_goods (goods_id),
    FOREIGN KEY (goods_id) REFERENCES shop_products(goods_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품 상세 정보';

-- 4. shop_product_icons (상품 아이콘 정의)
CREATE TABLE IF NOT EXISTS shop_product_icons (
    icon_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '아이콘 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    icon_name VARCHAR(25) NOT NULL COMMENT '아이콘명',
    icon_text_color VARCHAR(12) NOT NULL DEFAULT '' COMMENT '텍스트 색상',
    icon_bg_color VARCHAR(12) NOT NULL DEFAULT '' COMMENT '배경 색상',
    icon_image VARCHAR(100) NULL COMMENT '아이콘 이미지',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_domain (domain_id),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품 아이콘';

-- 5. shop_product_templates (상품 정보 공통 템플릿)
CREATE TABLE IF NOT EXISTS shop_product_templates (
    template_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '템플릿 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    category VARCHAR(25) NOT NULL DEFAULT '' COMMENT '적용 카테고리',
    goods_id BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '적용 상품 ID (0=공통)',
    tab_id VARCHAR(25) NOT NULL DEFAULT '' COMMENT '탭 ID',
    tab_name VARCHAR(25) NOT NULL DEFAULT '' COMMENT '탭 이름',
    subject VARCHAR(255) NOT NULL DEFAULT '' COMMENT '제목',
    content MEDIUMTEXT NULL COMMENT '내용 (HTML)',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부',
    sort_order TINYINT NOT NULL DEFAULT 0 COMMENT '정렬 순서',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_domain (domain_id),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품 정보 템플릿';
