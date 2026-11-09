-- Shop Package - 주문인 이메일 필수 설정
--
-- 주문 안내를 메일로 꼭 보내야 하는 쇼핑몰이 있다.
-- 기본값 0 은 지금처럼 주문서의 주문인 이메일을 선택 입력으로 둔다.

ALTER TABLE shop_config
    ADD COLUMN orderer_email_required TINYINT(1) NOT NULL DEFAULT 0 COMMENT '주문인 이메일 필수' AFTER show_all_products_tab;
