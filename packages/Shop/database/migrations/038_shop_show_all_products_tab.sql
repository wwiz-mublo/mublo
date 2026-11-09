-- Shop Package - 상품 목록 "전체" 탭 보이기 설정
--
-- 카테고리별로만 상품을 보여 주고 싶은 쇼핑몰이 있다.
-- 기본값 1 은 지금처럼 "전체" 링크를 보여 주고 /shop, /shop/products 에서 전체 상품을 보여 준다.
-- 0 이면 "전체" 링크를 숨기고, 카테고리·검색어 없는 목록 요청은 첫 번째 대분류로 보낸다.

ALTER TABLE shop_config
    ADD COLUMN show_all_products_tab TINYINT(1) NOT NULL DEFAULT 1 COMMENT '상품 목록 전체 탭 보이기' AFTER price_hidden_text;
