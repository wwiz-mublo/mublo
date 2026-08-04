-- 배송비 구간 JSON이 255자를 넘는 설정도 안전하게 저장한다.
ALTER TABLE shop_shipping_templates
    MODIFY price_ranges TEXT NULL COMMENT '금액별 배송비 JSON';
