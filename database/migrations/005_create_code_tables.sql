-- ============================================
-- 006: Unique Code Management Tables
-- ============================================
-- 통합 코드 관리 테이블
-- 메뉴코드, 주문번호, 쿠폰코드 등 유니크 코드 중앙 관리
-- ============================================

-- 유니크 코드 테이블
CREATE TABLE IF NOT EXISTS unique_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    code_type VARCHAR(50) NOT NULL COMMENT '코드 타입 (menu, order, coupon, invite 등)',
    code VARCHAR(100) NOT NULL COMMENT '생성된 코드',
    reference_id BIGINT UNSIGNED NULL COMMENT '연결된 레코드 ID (선택적)',
    reference_table VARCHAR(100) NULL COMMENT '연결된 테이블명 (선택적)',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_domain_type_code (domain_id, code_type, code),
    INDEX idx_domain_type (domain_id, code_type),
    INDEX idx_reference (reference_table, reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
