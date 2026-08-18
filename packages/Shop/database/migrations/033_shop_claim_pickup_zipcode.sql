-- ====================================
-- Shop Package - 회수지 우편번호 암호화 정합
-- ====================================
--
-- shop_orders.shipping_zip 은 암호화되어 TEXT 로 저장되는데 회수지 스냅샷을 받는
-- shop_returns.pickup_zipcode 는 VARCHAR(10) 이었다. 고객이 회수지를 따로 적지 않으면
-- 주문의 암호문 우편번호가 그대로 들어가 INSERT 가 통째로 실패했다(1406 Data too long).
-- 다른 회수지 필드(이름·연락처·주소)와 같은 규격으로 맞춘다.

ALTER TABLE shop_returns
    MODIFY pickup_zipcode TEXT NULL COMMENT '회수 우편번호 (암호화)';
