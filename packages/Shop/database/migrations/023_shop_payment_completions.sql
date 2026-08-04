-- ====================================
-- Shop Package - 결제 완료 후처리 원장
-- ====================================

CREATE TABLE IF NOT EXISTS shop_payment_completions (
    order_no VARCHAR(20) PRIMARY KEY COMMENT '주문번호 (주문당 결제 완료 후처리 1건)',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL COMMENT '외부 이벤트 멱등 ID',
    pg_key VARCHAR(25) NOT NULL DEFAULT '' COMMENT 'PG사 키 (0원 결제는 공백)',
    pg_tid VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'PG 거래 ID (0원 결제는 공백)',
    verify_data JSON NOT NULL COMMENT '정규화된 PG 검증 데이터',
    status ENUM('PENDING', 'PROCESSING', 'COMPLETED', 'FAILED') NOT NULL DEFAULT 'PENDING',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    lease_token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    processing_started_at DATETIME NULL,
    completed_at DATETIME NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uk_payment_completion_event (event_id),
    KEY idx_payment_completion_retry (status, processing_started_at),
    KEY idx_payment_completion_domain (domain_id),
    FOREIGN KEY (order_no) REFERENCES shop_orders(order_no) ON DELETE CASCADE,
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='결제 완료 후처리 멱등 원장';

-- 이 마이그레이션 이전의 PAID 주문은 기존 동작에서 이미 후처리됐다고 간주한다.
-- 과거 주문 조회가 신규 알림·웹훅을 재발행하지 않도록 COMPLETED 상태로 백필한다.
INSERT IGNORE INTO shop_payment_completions (
    order_no,
    domain_id,
    event_id,
    pg_key,
    pg_tid,
    verify_data,
    status,
    completed_at
)
SELECT
    o.order_no,
    o.domain_id,
    LOWER(MD5(CONCAT('shop-payment-legacy:', o.order_no))),
    COALESCE(o.payment_gateway, ''),
    '',
    JSON_OBJECT('legacy_backfill', TRUE),
    'COMPLETED',
    CURRENT_TIMESTAMP
FROM shop_orders o
WHERE o.order_status = 'paid'
   OR EXISTS (
       SELECT 1
       FROM shop_payment_transactions pt
       WHERE pt.order_no = o.order_no
         AND pt.transaction_type = 'PAYMENT'
         AND pt.transaction_status = 'SUCCESS'
   );
