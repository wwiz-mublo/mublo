-- ====================================
-- Shop Package - 반품을 교환과 같은 클레임 워크플로우로
-- ====================================
--
-- 반품은 '요청 → 승인' 두 단계뿐이라 회수·검수 자리가 없었다. 승인 즉시 반품완료가
-- 찍혀 물건을 받기 전에 완료 처리되는 구조였다. 교환이 쓰던 회수·검수 워크플로우를
-- 반품도 함께 쓰도록 상태와 컬럼을 정리하고, 그 환불을 클레임에 귀속시킨다.

-- 검수 결과는 클레임 본체의 것이다. 교환 대상 상품 테이블에 두면 반품은 적을 곳이 없다.
--
-- pickup_zipcode 는 규격이 어긋나 있었다. shop_orders.shipping_zip 은 암호화되어 TEXT 로
-- 저장되는데 그 스냅샷을 받는 이 컬럼은 026 에서 VARCHAR(10) 으로 만들어졌다. 고객이
-- 회수지를 따로 적지 않으면 주문의 암호문이 그대로 들어가 INSERT 가 통째로 실패했다
-- (1406 Data too long). 다른 회수지 필드(이름·연락처·주소)와 같은 규격으로 맞춘다.
ALTER TABLE shop_returns
    ADD COLUMN inspection_result ENUM('PENDING', 'SALEABLE', 'DEFECTIVE', 'DISCARD', 'WRONG_ITEM')
        NOT NULL DEFAULT 'PENDING' COMMENT '회수품 검수 결과' AFTER fee_paid_at,
    ADD COLUMN source_restocked_at DATETIME NULL COMMENT '회수품 재고 복구 시각' AFTER inspection_result,
    ADD COLUMN refunded_at DATETIME NULL COMMENT '환불 확인 시각 (반품)' AFTER source_restocked_at,
    MODIFY pickup_zipcode TEXT NULL COMMENT '회수 우편번호 (암호화)';

-- 교환 건에 이미 쌓인 검수 결과를 옮긴다.
UPDATE shop_returns r
INNER JOIN shop_exchange_items e ON e.return_id = r.return_id
SET r.inspection_result = e.inspection_result,
    r.source_restocked_at = e.source_restocked_at;

-- 구 2단계 반품(REQUESTED/COMPLETED/REFUSED)은 클레임 상태값과 그대로 겹치므로
-- 상태 이관이 필요 없다. 다만 승인 완료 건은 회수·검수를 거치지 않았음을 남긴다.
UPDATE shop_returns
SET staff_memo = CONCAT(COALESCE(staff_memo, ''), ' [구 반품 흐름으로 처리된 건: 회수·검수 기록 없음]')
WHERE return_type = 'RETURN' AND return_status = 'COMPLETED';

-- 반품 환불이 주문에만 귀속되고 클레임과 이어지지 않았다. 한 주문에 반품이 둘이면
-- 어느 환불이 어느 건인지 알 수 없었고, 실제로 환불하지 않고도 반품을 완료로
-- 찍을 수 있었다. 환불 기록이 클레임을 가리키게 해서 그 연결을 만든다.
ALTER TABLE shop_payment_transactions
    ADD COLUMN claim_id BIGINT UNSIGNED NULL COMMENT '반품 클레임 ID (반품 환불인 경우)' AFTER order_no,
    ADD INDEX idx_claim (claim_id);
