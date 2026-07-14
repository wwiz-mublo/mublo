-- ====================================
-- Shop Package - 결제 내역
-- ====================================

CREATE TABLE IF NOT EXISTS shop_payment_transactions (
    transaction_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '거래 ID',
    order_no VARCHAR(20) NOT NULL COMMENT '주문번호',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    -- PG 정보
    pg_key VARCHAR(25) NOT NULL COMMENT 'PG사 키 (tosspay, inicis 등)',
    pg_tid VARCHAR(100) NULL COMMENT 'PG 거래 ID',
    pg_approval_no VARCHAR(50) NULL COMMENT 'PG 승인번호',
    pg_response TEXT NULL COMMENT 'PG 응답 원본 (JSON)',

    -- 결제 정보
    payment_method VARCHAR(20) NOT NULL COMMENT '결제 수단 (CARD/PHONE/VBANK/BANK/POINT)',
    amount INT NOT NULL DEFAULT 0 COMMENT '거래 금액',
    transaction_type ENUM('PAYMENT', 'CANCEL', 'PARTIAL_CANCEL') NOT NULL DEFAULT 'PAYMENT' COMMENT '거래 유형',
    transaction_status ENUM('PENDING', 'SUCCESS', 'FAILED', 'CANCELLED') NOT NULL DEFAULT 'PENDING' COMMENT '거래 상태',

    -- 카드 정보 (카드 결제 시)
    card_company VARCHAR(25) NULL COMMENT '카드사',
    card_number VARCHAR(25) NULL COMMENT '카드번호 (마스킹)',
    installment INT NOT NULL DEFAULT 0 COMMENT '할부 개월',

    -- 가상계좌 (가상계좌 결제 시)
    vbank_name VARCHAR(25) NULL COMMENT '가상계좌 은행명',
    vbank_number VARCHAR(30) NULL COMMENT '가상계좌 번호',
    vbank_holder VARCHAR(50) NULL COMMENT '가상계좌 예금주',
    vbank_due_date DATETIME NULL COMMENT '가상계좌 입금 기한',

    -- 취소 정보
    cancel_amount INT NOT NULL DEFAULT 0 COMMENT '취소 금액',
    cancel_reason VARCHAR(255) NULL COMMENT '취소 사유',
    cancelled_at DATETIME NULL COMMENT '취소 일시',

    -- 메모
    admin_memo VARCHAR(255) NULL COMMENT '관리자 메모',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_order (order_no),
    INDEX idx_domain (domain_id),
    INDEX idx_pg_tid (pg_tid),
    INDEX idx_status (transaction_status),
    FOREIGN KEY (order_no) REFERENCES shop_orders(order_no) ON DELETE CASCADE,
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='결제 내역';
