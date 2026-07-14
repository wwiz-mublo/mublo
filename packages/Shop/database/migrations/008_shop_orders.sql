-- ====================================
-- Shop Package - 주문
-- ====================================

-- 1. shop_orders (주문 헤더)
CREATE TABLE IF NOT EXISTS shop_orders (
    order_no VARCHAR(20) PRIMARY KEY COMMENT '주문번호 (YYYYMMDD + 시퀀스)',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    cart_session_id VARCHAR(36) NULL COMMENT '장바구니 세션 UUID',
    member_id BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '회원 ID (0=비회원)',

    -- 주문인 정보 (AES-256-GCM 암호화)
    orderer_name TEXT NOT NULL COMMENT '주문인명 (암호화)',
    orderer_phone TEXT NOT NULL COMMENT '주문인 연락처 (암호화)',
    orderer_email TEXT NULL COMMENT '주문인 이메일 (암호화)',
    orderer_name_index VARCHAR(64) NULL COMMENT '주문인명 검색 인덱스 (blind index)',
    orderer_phone_index VARCHAR(64) NULL COMMENT '주문인 연락처 검색 인덱스',

    -- 금액
    total_price INT NOT NULL DEFAULT 0 COMMENT '상품 합계',
    extra_price INT NOT NULL DEFAULT 0 COMMENT '추가 금액',
    point_used INT NOT NULL DEFAULT 0 COMMENT '사용 포인트',
    coupon_discount INT NOT NULL DEFAULT 0 COMMENT '쿠폰 할인 합계',
    coupon_id BIGINT UNSIGNED NULL COMMENT '적용 쿠폰 ID',
    coupon_breakdown JSON NULL COMMENT '복수 쿠폰 적용 내역 [{coupon_id, coupon_group_id, coupon_method, discount}]',
    shipping_fee INT NOT NULL DEFAULT 0 COMMENT '배송비 합계',
    shipping_breakdown JSON NULL COMMENT '배송비 분해 [{template_id, template_name, base_fee, extra_fee, extra_label}]',
    agreed_policies JSON NULL COMMENT '주문 시 동의 약관 스냅샷 [{policy_id, version, title, agreed_at}]',
    tax_amount INT NOT NULL DEFAULT 0 COMMENT '세금',

    -- 배송지 (AES-256-GCM 암호화)
    shipping_zip TEXT NULL COMMENT '우편번호 (암호화)',
    shipping_address1 TEXT NULL COMMENT '주소1 (암호화)',
    shipping_address2 TEXT NULL COMMENT '주소2 (암호화)',
    recipient_name TEXT NULL COMMENT '수령인 (암호화)',
    recipient_phone TEXT NULL COMMENT '수령인 연락처 (암호화)',
    recipient_name_index VARCHAR(64) NULL COMMENT '수령인명 검색 인덱스 (blind index)',
    recipient_phone_index VARCHAR(64) NULL COMMENT '수령인 연락처 검색 인덱스',

    -- 결제
    payment_gateway VARCHAR(25) NULL COMMENT 'PG사 키 (tosspay, inicis 등)',
    payment_method VARCHAR(20) NOT NULL DEFAULT 'BANK' COMMENT '결제 수단 (CARD/PHONE/VBANK/BANK/POINT)',
    bank_account_info TEXT NULL COMMENT '무통장입금 선택 계좌 정보 (JSON)',
    order_status VARCHAR(50) NULL COMMENT '주문 상태 (state id)',

    -- 메모
    order_memo TEXT NULL COMMENT '주문 메모',
    campaign_key VARCHAR(100) NULL DEFAULT NULL COMMENT '전환 시점 캠페인 키 (세션 기반)',

    -- 플래그
    is_complete TINYINT(1) NOT NULL DEFAULT 0 COMMENT '결제 완료 여부',
    is_direct_order TINYINT(1) NOT NULL DEFAULT 0 COMMENT '바로 구매 여부',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_domain (domain_id),
    INDEX idx_member (member_id),
    INDEX idx_status (order_status),
    INDEX idx_member_date (member_id, created_at),
    INDEX idx_domain_date (domain_id, created_at),
    INDEX idx_campaign_key (domain_id, campaign_key, created_at),
    INDEX idx_orderer_name_si (orderer_name_index),
    INDEX idx_orderer_phone_si (orderer_phone_index),
    INDEX idx_recipient_name_si (recipient_name_index),
    INDEX idx_recipient_phone_si (recipient_phone_index),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='주문';

-- 2. shop_order_details (주문 상세 — 개별 상품)
CREATE TABLE IF NOT EXISTS shop_order_details (
    order_detail_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '주문 상세 ID',
    order_no VARCHAR(20) NOT NULL COMMENT '주문번호',
    cart_item_id BIGINT UNSIGNED NULL COMMENT '원본 장바구니 아이템 ID',

    -- 상품/옵션
    goods_id BIGINT UNSIGNED NOT NULL COMMENT '상품 ID',
    goods_name VARCHAR(100) NOT NULL COMMENT '상품명 (스냅샷)',
    goods_image VARCHAR(255) NULL COMMENT '상품 이미지 (스냅샷)',
    option_mode ENUM('NONE', 'SINGLE', 'COMBINATION') NOT NULL DEFAULT 'NONE' COMMENT '옵션 모드',
    option_id BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '옵션 ID',
    option_code VARCHAR(255) NULL COMMENT '옵션 코드',
    option_name VARCHAR(255) NULL COMMENT '옵션명 (스냅샷)',
    option_type ENUM('BASIC', 'EXTRA') NOT NULL DEFAULT 'BASIC' COMMENT '옵션 유형',

    -- 금액
    goods_price INT NOT NULL DEFAULT 0 COMMENT '상품 가격',
    option_price INT NOT NULL DEFAULT 0 COMMENT '옵션 추가 금액',
    support_price INT NOT NULL DEFAULT 0 COMMENT '공급가',
    total_price INT NOT NULL DEFAULT 0 COMMENT '합계',
    quantity INT NOT NULL DEFAULT 1 COMMENT '수량',
    point_amount INT NOT NULL DEFAULT 0 COMMENT '적립금',
    coupon_discount INT NOT NULL DEFAULT 0 COMMENT '쿠폰 할인',
    coupon_id BIGINT UNSIGNED NULL COMMENT '적용 쿠폰 ID',

    -- 공급처/담당자
    support_id BIGINT UNSIGNED NULL COMMENT '공급처 ID',
    staff_id BIGINT UNSIGNED NULL COMMENT '담당자 ID',

    -- 상태
    status VARCHAR(50) NULL COMMENT '상품별 상태 (state id)',
    is_paid TINYINT(1) NOT NULL DEFAULT 0 COMMENT '결제 완료',
    is_preparing TINYINT(1) NOT NULL DEFAULT 0 COMMENT '배송 준비',
    is_shipped TINYINT(1) NOT NULL DEFAULT 0 COMMENT '배송 중',
    is_completed TINYINT(1) NOT NULL DEFAULT 0 COMMENT '배송 완료',
    stock_deducted TINYINT(1) NOT NULL DEFAULT 0 COMMENT '재고 차감 여부 (0=미차감, 1=차감됨)',

    -- 반품/교환
    return_type ENUM('NONE', 'CANCEL', 'RETURN', 'EXCHANGE') NOT NULL DEFAULT 'NONE' COMMENT '반품 유형',
    return_status ENUM('NONE', 'REQUESTED', 'ACCEPTED', 'COMPLETED', 'REFUSED') NOT NULL DEFAULT 'NONE' COMMENT '반품 상태',

    -- 리뷰
    review_status ENUM('NONE', 'PHOTO', 'TEXT') NOT NULL DEFAULT 'NONE' COMMENT '리뷰 상태',
    review_point INT NOT NULL DEFAULT 0 COMMENT '리뷰 적립금',

    paid_at DATETIME NULL COMMENT '결제 일시',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_order (order_no),
    INDEX idx_cart_item (cart_item_id),
    INDEX idx_goods (goods_id),
    INDEX idx_status (status),
    INDEX idx_order_status (order_no, status),
    FOREIGN KEY (order_no) REFERENCES shop_orders(order_no) ON DELETE CASCADE,
    FOREIGN KEY (goods_id) REFERENCES shop_products(goods_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='주문 상세';

-- 3. shop_order_logs (주문 상태 변경 이력)
CREATE TABLE IF NOT EXISTS shop_order_logs (
    log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '로그 ID',
    order_no VARCHAR(20) NOT NULL COMMENT '주문번호',
    order_detail_id BIGINT UNSIGNED NULL COMMENT '주문 상세 ID (NULL=주문 전체)',

    -- 상태 변경
    prev_status VARCHAR(50) NULL COMMENT '이전 상태 (state id)',
    prev_status_label VARCHAR(50) NULL COMMENT '이전 상태 라벨 (스냅샷)',
    new_status VARCHAR(50) NOT NULL COMMENT '변경 상태 (state id)',
    new_status_label VARCHAR(50) NOT NULL DEFAULT '' COMMENT '변경 상태 라벨 (스냅샷)',
    change_type ENUM('STATUS', 'PAYMENT', 'SHIPPING', 'RETURN', 'SYSTEM') NOT NULL DEFAULT 'STATUS' COMMENT '변경 유형',

    -- 처리자
    changed_by ENUM('SYSTEM', 'STAFF', 'CUSTOMER') NOT NULL DEFAULT 'SYSTEM' COMMENT '변경 주체',
    staff_id BIGINT UNSIGNED NULL COMMENT '처리 담당자 ID',

    -- 사유
    reason VARCHAR(255) NULL COMMENT '변경 사유',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',

    INDEX idx_order (order_no),
    INDEX idx_detail (order_detail_id),
    INDEX idx_created (created_at),
    FOREIGN KEY (order_no) REFERENCES shop_orders(order_no) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='주문 상태 변경 이력';

-- 4. shop_order_memos (주문 관리자 메모/상담)
CREATE TABLE IF NOT EXISTS shop_order_memos (
    memo_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '메모 ID',
    order_no VARCHAR(20) NOT NULL COMMENT '주문번호',

    -- 메모 정보
    memo_type ENUM('MEMO', 'CS_CALL', 'CS_CHAT', 'CS_EMAIL', 'INTERNAL') NOT NULL DEFAULT 'MEMO' COMMENT '메모 유형',
    content TEXT NOT NULL COMMENT '내용',

    -- 작성자
    staff_id BIGINT UNSIGNED NOT NULL COMMENT '작성 담당자 ID',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_order (order_no),
    INDEX idx_staff (staff_id),
    INDEX idx_type (memo_type),
    FOREIGN KEY (order_no) REFERENCES shop_orders(order_no) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='주문 관리자 메모';
