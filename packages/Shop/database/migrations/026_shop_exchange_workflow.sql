-- ====================================
-- Shop Package - 국내형 교환 클레임 워크플로우
-- ====================================

-- 기존 shop_returns는 반품/교환 클레임 본체로 유지한다.
ALTER TABLE shop_returns
    ADD COLUMN domain_id BIGINT UNSIGNED NULL AFTER return_id,
    MODIFY return_status VARCHAR(30) NOT NULL DEFAULT 'REQUESTED' COMMENT '클레임 처리 상태',
    ADD COLUMN original_item_status VARCHAR(50) NULL AFTER return_status,
    ADD COLUMN responsibility ENUM('CUSTOMER', 'SELLER', 'MANUAL') NOT NULL DEFAULT 'MANUAL' AFTER reason_detail,
    ADD COLUMN exchange_shipping_fee INT NOT NULL DEFAULT 0 AFTER return_shipping_fee,
    ADD COLUMN fee_status ENUM('UNPAID', 'PAID', 'WAIVED') NOT NULL DEFAULT 'WAIVED' AFTER exchange_shipping_fee,
    ADD COLUMN fee_method ENUM('BANK', 'COD', 'MANUAL') NULL AFTER fee_status,
    ADD COLUMN fee_paid_at DATETIME NULL AFTER fee_method,
    ADD COLUMN pickup_name TEXT NULL COMMENT '회수 수령인 (암호화)' AFTER collect_invoice,
    ADD COLUMN pickup_phone TEXT NULL COMMENT '회수 연락처 (암호화)' AFTER pickup_name,
    ADD COLUMN pickup_zipcode VARCHAR(10) NULL AFTER pickup_phone,
    ADD COLUMN pickup_address1 TEXT NULL COMMENT '회수 주소1 (암호화)' AFTER pickup_zipcode,
    ADD COLUMN pickup_address2 TEXT NULL COMMENT '회수 주소2 (암호화)' AFTER pickup_address1,
    ADD COLUMN accepted_at DATETIME NULL AFTER requested_at,
    ADD COLUMN collected_at DATETIME NULL AFTER accepted_at,
    ADD COLUMN inspected_at DATETIME NULL AFTER collected_at,
    ADD COLUMN reshipped_at DATETIME NULL AFTER inspected_at,
    ADD COLUMN cancelled_at DATETIME NULL AFTER reshipped_at,
    ADD INDEX idx_domain_status (domain_id, return_status),
    ADD INDEX idx_domain_order (domain_id, order_no);

UPDATE shop_returns r
INNER JOIN shop_orders o ON o.order_no = r.order_no
SET r.domain_id = o.domain_id
WHERE r.domain_id IS NULL;

ALTER TABLE shop_returns
    MODIFY domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID';

-- 주문상품의 return_status는 상세 클레임 상태의 요약 캐시다.
ALTER TABLE shop_order_details
    MODIFY return_status VARCHAR(30) NOT NULL DEFAULT 'NONE' COMMENT '클레임 상태 요약';

-- 교환 대상 상품/옵션과 재고 처리 멱등 상태를 별도 보존한다.
CREATE TABLE IF NOT EXISTS shop_exchange_items (
    exchange_item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_id BIGINT UNSIGNED NOT NULL,
    source_order_detail_id BIGINT UNSIGNED NOT NULL,
    goods_id BIGINT UNSIGNED NOT NULL,
    option_mode ENUM('NONE', 'SINGLE', 'COMBINATION') NOT NULL DEFAULT 'NONE',
    option_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    option_code VARCHAR(255) NULL,
    option_name VARCHAR(255) NULL,
    option_price INT NOT NULL DEFAULT 0,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    stock_reservation_status ENUM('NONE', 'RESERVED', 'RELEASED', 'SHIPPED') NOT NULL DEFAULT 'NONE',
    stock_reserved_at DATETIME NULL,
    stock_released_at DATETIME NULL,
    inspection_result ENUM('PENDING', 'SALEABLE', 'DEFECTIVE', 'DISCARD', 'WRONG_ITEM') NOT NULL DEFAULT 'PENDING',
    source_restocked_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_return (return_id),
    INDEX idx_source_detail (source_order_detail_id),
    INDEX idx_goods_option (goods_id, option_id),
    FOREIGN KEY (return_id) REFERENCES shop_returns(return_id) ON DELETE CASCADE,
    FOREIGN KEY (source_order_detail_id) REFERENCES shop_order_details(order_detail_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='교환 대상 및 재고 처리';

CREATE TABLE IF NOT EXISTS shop_claim_logs (
    claim_log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    return_id BIGINT UNSIGNED NOT NULL,
    domain_id BIGINT UNSIGNED NOT NULL,
    prev_status VARCHAR(30) NOT NULL,
    new_status VARCHAR(30) NOT NULL,
    changed_by ENUM('CUSTOMER', 'STAFF', 'SYSTEM') NOT NULL DEFAULT 'SYSTEM',
    changed_by_id BIGINT UNSIGNED NULL,
    reason VARCHAR(255) NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_claim_date (return_id, created_at),
    INDEX idx_domain_date (domain_id, created_at),
    FOREIGN KEY (return_id) REFERENCES shop_returns(return_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='교환/반품 클레임 상태 이력';

-- 최초 배송, 회수, 교환 재출고, 검수 거절 반송을 한 테이블에서 구분한다.
ALTER TABLE shop_shipments
    ADD COLUMN claim_id BIGINT UNSIGNED NULL AFTER order_detail_id,
    ADD COLUMN shipment_role VARCHAR(30) NOT NULL DEFAULT 'ORIGINAL' AFTER claim_id,
    ADD INDEX idx_claim_role (claim_id, shipment_role),
    ADD FOREIGN KEY (claim_id) REFERENCES shop_returns(return_id) ON DELETE SET NULL;

-- 주문 상태 Action과 분리된 교환 전용 알림/웹훅 설정.
ALTER TABLE shop_config
    ADD COLUMN claim_state_actions TEXT NULL COMMENT '교환 상태별 Action 설정 (JSON)' AFTER order_state_actions;
