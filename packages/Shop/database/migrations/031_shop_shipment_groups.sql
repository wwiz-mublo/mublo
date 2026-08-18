-- ====================================
-- Shop Package - 송장의 배송비 그룹 귀속
-- ====================================
--
-- 배송비 그룹(= 상품별 배송 템플릿)은 출고 정책 단위이고, 그룹마다 반품지가
-- 따로 스냅샷된다. 즉 배송지가 하나여도 그룹이 둘이면 물건은 두 곳에서 나간다.
-- 송장을 그 그룹에 귀속시켜, 어느 송장이 어느 상품을 싣고 있는지 확정한다.
--
-- NULL = 주문 전체 묶음배송 (기존 데이터 및 단일 그룹 주문의 기본값)

ALTER TABLE shop_shipments
    ADD COLUMN shipping_group_key VARCHAR(60) NULL COMMENT '배송비 그룹 키 (NULL=주문 전체 묶음)' AFTER order_detail_id,
    ADD INDEX idx_order_group (order_no, shipping_group_key);
