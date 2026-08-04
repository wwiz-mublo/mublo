-- ====================================
-- Shop Package - 장바구니
-- ====================================

-- 1. shop_carts (장바구니 아이템)
CREATE TABLE IF NOT EXISTS shop_carts (
    cart_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '장바구니 아이템 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    -- 소유자
    cart_session_id VARCHAR(36) NOT NULL COMMENT '장바구니 세션 UUID',
    member_id BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '회원 ID (0=비회원)',

    -- 상품/옵션
    goods_id BIGINT UNSIGNED NOT NULL COMMENT '상품 ID',
    option_mode ENUM('NONE', 'SINGLE', 'COMBINATION') NOT NULL DEFAULT 'NONE' COMMENT '옵션 모드',
    option_id BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '옵션 ID (shop_product_options 또는 shop_product_option_combos)',
    option_code VARCHAR(255) NULL COMMENT '옵션 코드',
    option_type ENUM('BASIC', 'EXTRA') NOT NULL DEFAULT 'BASIC' COMMENT '옵션 유형',

    -- 가격
    goods_price INT NOT NULL DEFAULT 0 COMMENT '상품 가격',
    option_price INT NOT NULL DEFAULT 0 COMMENT '옵션 추가 금액',
    total_price INT NOT NULL DEFAULT 0 COMMENT '합계 (goods_price + option_price) × quantity',
    quantity INT NOT NULL DEFAULT 1 COMMENT '수량',
    point_amount INT NOT NULL DEFAULT 0 COMMENT '예상 적립금',

    -- 상태
    cart_status ENUM('PENDING', 'ORDERED') NOT NULL DEFAULT 'PENDING' COMMENT '상태 (대기/주문완료)',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_domain (domain_id),
    INDEX idx_session (cart_session_id),
    INDEX idx_member (member_id),
    INDEX idx_goods (goods_id),
    INDEX idx_status (cart_status),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
    FOREIGN KEY (goods_id) REFERENCES shop_products(goods_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='장바구니';

-- 2. shop_cart_fees (장바구니 배송비/쿠폰 수수료)
CREATE TABLE IF NOT EXISTS shop_cart_fees (
    fee_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '수수료 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    cart_session_id VARCHAR(36) NOT NULL COMMENT '장바구니 세션 UUID',
    member_id BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '회원 ID',
    goods_id BIGINT UNSIGNED NOT NULL COMMENT '상품 ID',

    -- 배송비
    shipping_fee INT NOT NULL DEFAULT 0 COMMENT '배송비',
    shipping_template_id BIGINT UNSIGNED NULL COMMENT '배송 템플릿 ID',
    shipping_apply_type ENUM('COMBINED', 'SEPARATE') NOT NULL DEFAULT 'COMBINED' COMMENT '배송비 적용 방식',

    -- 쿠폰
    coupon_id BIGINT UNSIGNED NULL COMMENT '적용 쿠폰 ID',
    coupon_discount INT NOT NULL DEFAULT 0 COMMENT '쿠폰 할인 금액',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    UNIQUE KEY uk_session_goods (cart_session_id, goods_id),
    INDEX idx_domain (domain_id),
    INDEX idx_session (cart_session_id),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
    FOREIGN KEY (goods_id) REFERENCES shop_products(goods_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='장바구니 수수료';
