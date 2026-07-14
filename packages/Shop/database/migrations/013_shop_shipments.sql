-- ====================================
-- Shop Package - 배송 추적
-- ====================================

CREATE TABLE IF NOT EXISTS shop_shipments (
    shipment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '배송 ID',
    order_no VARCHAR(20) NOT NULL COMMENT '주문번호',
    order_detail_id BIGINT UNSIGNED NULL COMMENT '주문 상세 ID (NULL=묶음배송)',

    -- 송장 정보
    company_id BIGINT UNSIGNED NULL COMMENT '택배사 ID',
    invoice_no VARCHAR(30) NOT NULL COMMENT '송장번호',

    -- 배송 상태
    shipment_status ENUM('READY', 'PICKED_UP', 'IN_TRANSIT', 'DELIVERED', 'FAILED') NOT NULL DEFAULT 'READY' COMMENT '배송 상태',

    -- 일시
    shipped_at DATETIME NULL COMMENT '발송일',
    delivered_at DATETIME NULL COMMENT '배송 완료일',

    -- 메모
    admin_memo VARCHAR(255) NULL COMMENT '관리자 메모',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_order (order_no),
    INDEX idx_detail (order_detail_id),
    INDEX idx_invoice (invoice_no),
    INDEX idx_status (shipment_status),
    FOREIGN KEY (order_no) REFERENCES shop_orders(order_no) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES shop_delivery_companies(company_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='배송 추적';
