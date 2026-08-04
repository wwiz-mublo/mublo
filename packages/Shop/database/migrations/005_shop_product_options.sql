-- ====================================
-- Shop Package - 상품별 옵션 (Per-product)
-- ====================================
--
-- 설계 철학:
-- 1. 각 상품이 자신만의 옵션을 소유 (글로벌 옵션 그룹 참조 없음)
-- 2. 프리셋에서 복사해 올 수 있지만, 복사 후 독립적
-- 3. 조합형(COMBINATION) 옵션은 shop_product_option_combos에서 재고/가격 개별 관리
--
-- [옵션 모드별 처리]
-- NONE: 옵션 없음 — shop_products.stock_quantity로 재고 관리
-- SINGLE: 단독 옵션 — shop_product_option_values에서 개별 재고/가격
-- COMBINATION: 조합 옵션 — shop_product_option_combos에서 조합별 재고/가격
--

-- 1. shop_product_options (상품별 옵션 정의)
CREATE TABLE IF NOT EXISTS shop_product_options (
    option_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '옵션 ID',
    goods_id BIGINT UNSIGNED NOT NULL COMMENT '상품 ID',

    option_name VARCHAR(50) NOT NULL COMMENT '옵션명 (색상, 사이즈 등)',
    option_type ENUM('BASIC', 'EXTRA') NOT NULL DEFAULT 'BASIC' COMMENT '옵션 유형 (기본/추가)',
    is_required TINYINT(1) NOT NULL DEFAULT 1 COMMENT '필수 여부',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '정렬 순서',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_goods (goods_id),
    FOREIGN KEY (goods_id) REFERENCES shop_products(goods_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품 옵션';

-- 2. shop_product_option_values (옵션 값)
CREATE TABLE IF NOT EXISTS shop_product_option_values (
    value_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '옵션 값 ID',
    option_id BIGINT UNSIGNED NOT NULL COMMENT '옵션 ID',

    value_name VARCHAR(50) NOT NULL COMMENT '값 이름 (빨강, XL 등)',
    extra_price INT NOT NULL DEFAULT 0 COMMENT '추가 금액',
    stock_quantity INT NULL DEFAULT NULL COMMENT '재고 수량 (NULL=미관리, 0=품절)',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '정렬 순서',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_option (option_id),
    FOREIGN KEY (option_id) REFERENCES shop_product_options(option_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품 옵션 값';

-- 3. shop_product_option_combos (조합형 옵션)
-- combination_key: 옵션값을 정렬된 순서로 연결 (예: "빨강/XL", "파랑/M")
CREATE TABLE IF NOT EXISTS shop_product_option_combos (
    combo_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '조합 ID',
    goods_id BIGINT UNSIGNED NOT NULL COMMENT '상품 ID',

    combination_key VARCHAR(255) NOT NULL COMMENT '조합 키 (값1/값2/값3)',
    extra_price INT NOT NULL DEFAULT 0 COMMENT '추가 금액',
    stock_quantity INT NULL DEFAULT NULL COMMENT '재고 수량 (NULL=미관리, 0=품절)',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    UNIQUE KEY uk_goods_combo (goods_id, combination_key),
    INDEX idx_goods (goods_id),
    FOREIGN KEY (goods_id) REFERENCES shop_products(goods_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품 조합형 옵션';
