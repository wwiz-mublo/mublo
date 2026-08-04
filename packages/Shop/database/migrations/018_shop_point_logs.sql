-- ====================================
-- Shop Package - 포인트 로그
-- ====================================

CREATE TABLE IF NOT EXISTS shop_point_logs (
    log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '로그 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    member_id BIGINT UNSIGNED NOT NULL COMMENT '회원 ID',

    -- 포인트 정보
    point_type ENUM('EARN', 'USE', 'REFUND', 'EXPIRE', 'ADMIN') NOT NULL COMMENT '유형 (적립/사용/환불/만료/관리자)',
    point_amount INT NOT NULL DEFAULT 0 COMMENT '포인트 금액 (양수=적립, 음수=차감)',
    balance INT NOT NULL DEFAULT 0 COMMENT '처리 후 잔액',

    -- 사유
    reason_type ENUM('ORDER', 'REVIEW', 'CANCEL', 'EXPIRE', 'ADMIN_ADD', 'ADMIN_SUB') NOT NULL COMMENT '사유 유형',
    reason_detail VARCHAR(255) NULL COMMENT '상세 사유',

    -- 연관 정보
    order_no VARCHAR(20) NULL COMMENT '관련 주문번호',
    goods_id BIGINT UNSIGNED NULL COMMENT '관련 상품 ID',
    review_id BIGINT UNSIGNED NULL COMMENT '관련 후기 ID',

    -- 만료
    expire_date DATE NULL COMMENT '만료일',
    is_expired TINYINT(1) NOT NULL DEFAULT 0 COMMENT '만료 처리 여부',

    -- 관리
    staff_id BIGINT UNSIGNED NULL COMMENT '처리 관리자 ID',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',

    INDEX idx_domain_member (domain_id, member_id),
    INDEX idx_member (member_id),
    INDEX idx_order (order_no),
    INDEX idx_type (point_type),
    INDEX idx_expire (expire_date, is_expired),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='포인트 로그';
