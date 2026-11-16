-- Shop Package - 주문인·배송지 정보 수정 이력
--
-- 관리자가 주문 상세에서 주문인·배송지를 고치면 shop_order_logs 에 change_type='INFO' 로 남긴다.
-- change_detail 에는 바뀐 항목명과 전후 값(마스킹)을 JSON 으로 담는다.
-- STOCK 은 재고 부족 오버셀 기록(StockDeductActionHandler)이 이미 쓰는 값이라 함께 정식 값으로 올린다.

ALTER TABLE shop_order_logs
    MODIFY COLUMN change_type ENUM('STATUS', 'PAYMENT', 'SHIPPING', 'RETURN', 'SYSTEM', 'STOCK', 'INFO')
        NOT NULL DEFAULT 'STATUS' COMMENT '변경 유형 (INFO=주문인·배송지 정보 수정)',
    ADD COLUMN change_detail JSON NULL
        COMMENT '정보 수정 상세 [{field, label, before, after}] (전후 값은 마스킹)' AFTER reason;
