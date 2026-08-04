-- 결제 준비(prepare) 시점에 PG가 발급한 거래번호를 주문에 남긴다.
--
-- 지금까지는 검증에 성공해 shop_payment_transactions 행이 생겨야만 거래번호가 기록됐다.
-- 그래서 결제창 이탈·webhook 유실처럼 검증 전에 흐름이 끊기면, PG 에는 승인이 남아 있는데
-- 우리 쪽에는 조회할 키조차 없는 상태가 된다(대사 불가).
--
-- 이 컬럼은 "PG 에 이 거래번호로 결제를 요청했다"는 사실만 담는다. 결제 성공을 뜻하지 않으며
-- 승인 여부의 권위는 여전히 PG 조회(verify)와 shop_payment_transactions 에 있다.
ALTER TABLE shop_orders
    ADD COLUMN payment_tid VARCHAR(100) NULL DEFAULT NULL COMMENT 'PG 거래번호(결제 준비 시점 기록, 승인 보장 아님)' AFTER payment_gateway;

CREATE INDEX idx_shop_orders_payment_tid ON shop_orders (payment_tid);
