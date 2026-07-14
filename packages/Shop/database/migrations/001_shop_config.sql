-- ====================================
-- Shop Package - 쇼핑몰 설정
-- ====================================

-- 1. shop_config (쇼핑몰 환경 설정)
CREATE TABLE IF NOT EXISTS shop_config (
    config_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '설정 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    domain_group VARCHAR(100) NULL COMMENT '도메인 그룹 (domain_configs.domain_group 스냅샷)',

    -- 기본 설정
    shop_type ENUM('self','other') NOT NULL DEFAULT 'self' COMMENT '쇼핑몰 유형',
    membership VARCHAR(12) NOT NULL DEFAULT 'FREE' COMMENT '회원 유형',
    cart_keep_days INT NOT NULL DEFAULT 15 COMMENT '장바구니 보관 기간(일)',
    guest_cart_keep_days INT NOT NULL DEFAULT 1 COMMENT '비회원 장바구니 보관 기간(일)',
    use_guest_cart TINYINT(1) NOT NULL DEFAULT 1 COMMENT '비회원 장바구니 허용',
    skin_name VARCHAR(30) NOT NULL DEFAULT 'basic' COMMENT '스킨명',
    skin_config TEXT NULL COMMENT '기능별 프론트 스킨 (JSON 맵)',
    title VARCHAR(255) NULL COMMENT '쇼핑몰 제목',
    default_shipping_template_id BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '기본 배송 템플릿 ID',
    auto_confirm_days INT NOT NULL DEFAULT 8 COMMENT '자동 구매확정: 발송 후 N일(0=사용 안 함)',

    -- SEO / CS
    seo_keyword VARCHAR(255) NULL COMMENT 'SEO 키워드',
    seo_description VARCHAR(255) NULL COMMENT 'SEO 설명',
    kakao_chat_url VARCHAR(255) NULL COMMENT '카카오 채팅 URL',
    naver_chat_url VARCHAR(255) NULL COMMENT '네이버 채팅 URL',
    customer_tel VARCHAR(25) NULL COMMENT '고객센터 전화',
    customer_time TEXT NULL COMMENT '고객센터 운영시간',

    -- 수수료
    commission_type TINYINT NOT NULL DEFAULT 1 COMMENT '수수료 유형',
    commission_rate INT NOT NULL DEFAULT 1 COMMENT '수수료 비율',

    -- 결제 설정
    payment_pg_key VARCHAR(25) NULL COMMENT '기본 PG 키 (ContractRegistry 조회용)',
    payment_pg_keys TEXT NULL COMMENT '사용 PG 키 목록 (JSON)',
    payment_merchant_code VARCHAR(100) NULL COMMENT 'PG 상점 코드',
    payment_methods TEXT NULL COMMENT '허용 결제 수단 (JSON)',
    payment_bank_info TEXT NULL COMMENT '무통장 입금 계좌 정보',
    use_point_payment TINYINT(1) NOT NULL DEFAULT 1 COMMENT '포인트 결제 사용',
    point_unit INT NOT NULL DEFAULT 100 COMMENT '포인트 사용 단위',
    point_min INT NOT NULL DEFAULT 100 COMMENT '포인트 최소 사용',
    point_max INT NOT NULL DEFAULT 30000 COMMENT '포인트 최대 사용',
    point_level_settings TEXT DEFAULT NULL COMMENT '레벨별 포인트 설정 (JSON)',

    -- 적립금
    reward_type VARCHAR(12) NOT NULL DEFAULT 'NONE' COMMENT '적립금 유형 (NONE/BASIC/LEVEL/PERCENTAGE/FIXED)',
    reward_value DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '적립금 값',
    reward_review INT NOT NULL DEFAULT 0 COMMENT '리뷰 적립금',
    reward_level_settings TEXT DEFAULT NULL COMMENT '레벨별 적립 설정 (JSON)',

    -- 할인
    discount_type VARCHAR(12) NOT NULL DEFAULT 'NONE' COMMENT '할인 유형 (NONE/BASIC/LEVEL/PERCENTAGE/FIXED)',
    discount_value DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '할인 값',
    discount_level_settings TEXT DEFAULT NULL COMMENT '레벨별 할인 설정 (JSON)',

    -- 쿠폰 / 주문상태
    use_coupon TINYINT(1) NOT NULL DEFAULT 1 COMMENT '쿠폰 사용 허용',
    order_states TEXT NULL COMMENT '주문 상태 설정 (JSON, FSM 스키마)',
    order_state_actions TEXT NULL COMMENT '상태별 액션 설정 (JSON)',

    -- 상품 상세 탭
    goods_view_tab VARCHAR(100) NULL COMMENT '상품 상세 탭 설정 (CSV: review,inquiry)',
    detail_tab_order VARCHAR(200) NOT NULL DEFAULT 'detail,template,review,inquiry' COMMENT '상세 탭 순서 (CSV)',
    checkout_policies TEXT DEFAULT NULL COMMENT '주문서 약관 (JSON 배열)',

    -- 타임스탬프
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    UNIQUE KEY uk_domain (domain_id),
    INDEX idx_domain_group (domain_group),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쇼핑몰 설정';
