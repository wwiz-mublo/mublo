-- ====================================
-- Shop Package - 회원 등급별 가격 정책
-- ====================================

CREATE TABLE IF NOT EXISTS shop_level_pricing (
    level_pricing_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '정책 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    level_value TINYINT UNSIGNED NOT NULL COMMENT '회원 등급 (member_levels.level_value 참조)',

    -- 할인
    discount_rate DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT '할인율 (%)',

    -- 적립
    reward_rate DECIMAL(5,2) NOT NULL DEFAULT 0 COMMENT '적립률 (%)',

    -- 배송
    free_shipping TINYINT(1) NOT NULL DEFAULT 0 COMMENT '무료배송 여부',
    free_shipping_threshold INT NOT NULL DEFAULT 0 COMMENT '무료배송 기준 금액 (0=무조건 무료)',

    -- 쿠폰
    auto_coupon_group_id BIGINT UNSIGNED NULL COMMENT '등급 달성 시 자동 발행 쿠폰 그룹 ID',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    UNIQUE KEY uk_domain_level (domain_id, level_value),
    INDEX idx_domain (domain_id),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='회원 등급별 가격 정책';
