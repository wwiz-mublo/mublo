-- ====================================
-- Shop Package - 반품/환불
-- ====================================

CREATE TABLE IF NOT EXISTS shop_returns (
    return_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '반품 ID',
    order_no VARCHAR(20) NOT NULL COMMENT '주문번호',
    order_detail_id BIGINT UNSIGNED NOT NULL COMMENT '주문 상세 ID',
    member_id BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '회원 ID',

    -- 유형
    return_type ENUM('CANCEL', 'RETURN', 'EXCHANGE') NOT NULL COMMENT '유형 (취소/반품/교환)',
    return_status ENUM('REQUESTED', 'ACCEPTED', 'COLLECTING', 'COLLECTED', 'COMPLETED', 'REFUSED') NOT NULL DEFAULT 'REQUESTED' COMMENT '처리 상태',

    -- 사유
    reason_type ENUM('CHANGE_MIND', 'DEFECT', 'WRONG_PRODUCT', 'WRONG_OPTION', 'LATE_DELIVERY', 'OTHER') NOT NULL COMMENT '사유 유형',
    reason_detail TEXT NULL COMMENT '상세 사유',

    -- 금액
    quantity INT NOT NULL DEFAULT 1 COMMENT '반품 수량',
    refund_amount INT NOT NULL DEFAULT 0 COMMENT '환불 금액',
    return_shipping_fee INT NOT NULL DEFAULT 0 COMMENT '반품 배송비',
    refund_method ENUM('ORIGINAL', 'BANK', 'POINT') NULL COMMENT '환불 방법',

    -- 환불 계좌 (무통장 환불 시)
    refund_bank VARCHAR(25) NULL COMMENT '환불 은행',
    refund_account VARCHAR(30) NULL COMMENT '환불 계좌번호',
    refund_holder VARCHAR(50) NULL COMMENT '환불 예금주',

    -- 수거 정보
    collect_courier VARCHAR(25) NULL COMMENT '수거 택배사',
    collect_invoice VARCHAR(30) NULL COMMENT '수거 송장번호',

    -- 처리
    staff_id BIGINT UNSIGNED NULL COMMENT '처리 담당자 ID',
    staff_memo TEXT NULL COMMENT '관리자 메모',
    refused_reason VARCHAR(255) NULL COMMENT '거절 사유',

    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '요청일',
    completed_at DATETIME NULL COMMENT '완료일',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_order (order_no),
    INDEX idx_detail (order_detail_id),
    INDEX idx_member (member_id),
    INDEX idx_status (return_status),
    FOREIGN KEY (order_no) REFERENCES shop_orders(order_no) ON DELETE CASCADE,
    FOREIGN KEY (order_detail_id) REFERENCES shop_order_details(order_detail_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='반품/환불';
