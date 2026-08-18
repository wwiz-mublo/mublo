-- ====================================
-- Shop Package - 환불과 클레임 연결
-- ====================================
--
-- 반품 환불이 주문에만 귀속되고 클레임과 이어지지 않았다. 한 주문에 반품이 둘이면
-- 어느 환불이 어느 건인지 알 수 없었고, 실제로 환불하지 않고도 반품을 완료로
-- 찍을 수 있었다. 환불 기록이 클레임을 가리키게 해서 그 연결을 만든다.

ALTER TABLE shop_payment_transactions
    ADD COLUMN claim_id BIGINT UNSIGNED NULL COMMENT '반품 클레임 ID (반품 환불인 경우)' AFTER order_no,
    ADD INDEX idx_claim (claim_id);
