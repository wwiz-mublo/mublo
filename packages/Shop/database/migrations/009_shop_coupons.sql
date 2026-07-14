-- ====================================
-- Shop Package - 쿠폰
-- ====================================

-- 1. shop_coupon_group (쿠폰 정책)
CREATE TABLE IF NOT EXISTS shop_coupon_group (
    coupon_group_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '쿠폰 그룹 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    -- 기본 정보
    name VARCHAR(100) NOT NULL COMMENT '쿠폰명',
    description TEXT NULL COMMENT '설명',
    coupon_type ENUM('ADMIN', 'AUTO', 'DOWNLOAD') NOT NULL DEFAULT 'ADMIN' COMMENT '발행 유형',
    coupon_method ENUM('GOODS', 'CATEGORY', 'ORDER', 'SHIPPING') NOT NULL COMMENT '적용 대상',

    -- 할인
    discount_type ENUM('FIXED', 'PERCENTAGE') NOT NULL COMMENT '할인 유형',
    discount_value DECIMAL(10,2) NOT NULL COMMENT '할인 값',
    max_discount INT NULL COMMENT '최대 할인 금액 (PERCENTAGE일 때)',
    min_order_amount INT NOT NULL DEFAULT 0 COMMENT '최소 주문 금액',

    -- 기간
    issue_start DATETIME NULL COMMENT '발행 시작일',
    issue_end DATETIME NULL COMMENT '발행 종료일',
    valid_days INT NULL COMMENT '사용 가능 일수',

    -- 적용 범위
    target_goods_id BIGINT UNSIGNED NULL COMMENT '대상 상품 ID (GOODS 방식)',
    target_category VARCHAR(20) NULL COMMENT '대상 카테고리 코드 (CATEGORY 방식)',
    excluded_goods TEXT NULL COMMENT '제외 상품 ID 목록 (CSV)',
    excluded_categories TEXT NULL COMMENT '제외 카테고리 코드 목록 (CSV)',

    -- 제한
    duplicate_policy ENUM('ALLOW', 'DENY_ALL', 'DENY_SAME_METHOD') NOT NULL DEFAULT 'DENY_SAME_METHOD' COMMENT '중복 사용 정책',
    use_limit_per_member INT NOT NULL DEFAULT 1 COMMENT '1인당 사용 횟수 제한',
    download_limit_per_member INT NOT NULL DEFAULT 1 COMMENT '1인당 다운로드 횟수 제한',
    total_issue_limit INT NULL COMMENT '총 발행 수량 제한 (NULL=무제한)',
    allowed_member_levels VARCHAR(50) NULL COMMENT '허용 회원 등급 (CSV)',
    first_order_only TINYINT(1) NOT NULL DEFAULT 0 COMMENT '첫 주문 전용',

    -- 자동 발행
    auto_issue_trigger ENUM('JOIN', 'LOGIN', 'LEVEL') NULL COMMENT '자동 발행 트리거',
    promotion_code VARCHAR(50) NULL COMMENT '프로모션 코드',

    -- 관리
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부',
    staff_id BIGINT UNSIGNED NULL COMMENT '등록 관리자 ID',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_domain (domain_id),
    INDEX idx_type (coupon_type),
    UNIQUE INDEX idx_promotion_code (promotion_code),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='쿠폰 정책';

-- 2. shop_coupon_issue (발행된 쿠폰)
CREATE TABLE IF NOT EXISTS shop_coupon_issue (
    coupon_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '쿠폰 ID',
    coupon_group_id BIGINT UNSIGNED NOT NULL COMMENT '쿠폰 그룹 ID',
    member_id BIGINT UNSIGNED NOT NULL COMMENT '회원 ID',

    coupon_number VARCHAR(25) NULL COMMENT '쿠폰 번호',
    issued_at DATETIME NOT NULL COMMENT '발행일',
    valid_until DATETIME NOT NULL COMMENT '만료일',

    -- 사용 정보
    is_used TINYINT(1) NOT NULL DEFAULT 0 COMMENT '사용 여부',
    used_at DATETIME NULL COMMENT '사용일',
    order_no VARCHAR(20) NULL COMMENT '사용 주문번호',
    used_amount INT NOT NULL DEFAULT 0 COMMENT '실제 할인 적용 금액',

    -- 상태
    status ENUM('ISSUED', 'USED', 'REGISTERED', 'EXPIRED') NOT NULL DEFAULT 'ISSUED' COMMENT '상태',
    staff_id BIGINT UNSIGNED NULL COMMENT '발행 관리자 ID',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_member (member_id),
    INDEX idx_group (coupon_group_id),
    INDEX idx_order (order_no),
    INDEX idx_status (status),
    FOREIGN KEY (coupon_group_id) REFERENCES shop_coupon_group(coupon_group_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='발행된 쿠폰';
