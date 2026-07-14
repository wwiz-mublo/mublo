-- ====================================
-- Shop Package - 배송 템플릿
-- ====================================

CREATE TABLE IF NOT EXISTS shop_shipping_templates (
    shipping_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '배송 템플릿 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    -- 기본 정보
    name VARCHAR(100) NULL COMMENT '템플릿명',
    category VARCHAR(25) NULL COMMENT '적용 카테고리 코드 (NULL=기본)',

    -- 배송비 계산
    shipping_method VARCHAR(25) NOT NULL COMMENT '배송비 유형 (FREE/COND/PAID/QUANTITY/AMOUNT)',
    basic_cost INT NOT NULL DEFAULT 0 COMMENT '기본 배송비',
    price_ranges VARCHAR(255) NULL COMMENT '금액별 배송비 (JSON: [{min,max,cost}])',
    free_threshold INT NOT NULL DEFAULT 0 COMMENT '무료 배송 기준 금액',
    goods_per_unit INT NOT NULL DEFAULT 0 COMMENT '수량별 배송비 단위',
    extra_cost_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '추가 배송비 사용',
    extra_cost_ranges JSON NULL COMMENT '도서산간 추가배송비 [{label, zip_from, zip_to, cost}]',

    -- 반품/교환
    return_cost INT NOT NULL DEFAULT 0 COMMENT '반품 배송비',
    exchange_cost INT NOT NULL DEFAULT 0 COMMENT '교환 배송비',
    shipping_guide VARCHAR(100) NULL COMMENT '배송 안내',

    -- 배송 방법 / 택배사
    delivery_method VARCHAR(25) NULL COMMENT '배송 방법 (COURIER/POSTAL/PICKUP/OWN/ETC)',
    delivery_company_id BIGINT UNSIGNED NULL COMMENT '택배사 ID',

    -- 출고지 주소
    origin_zipcode VARCHAR(10) NULL COMMENT '출고지 우편번호',
    origin_address1 VARCHAR(255) NULL COMMENT '출고지 주소1',
    origin_address2 VARCHAR(255) NULL COMMENT '출고지 주소2',

    -- 반품/교환지 주소
    return_zipcode VARCHAR(10) NULL COMMENT '반품지 우편번호',
    return_address1 VARCHAR(255) NULL COMMENT '반품지 주소1',
    return_address2 VARCHAR(255) NULL COMMENT '반품지 주소2',

    is_active TINYINT(1) NOT NULL DEFAULT 0 COMMENT '사용 여부',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_domain (domain_id),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='배송 템플릿';
