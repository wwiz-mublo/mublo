-- Shop Package - 분류별 상품 목록 안내 이미지
--
-- 상품 목록의 분류 탭 아래·상품 목록 위에 분류마다 다른 안내 이미지를 보인다.
-- 기존 image 칼럼은 분류 메뉴 아이콘(ShopCategoryProvider 의 icon)으로 쓰이므로 따로 둔다.
-- 설정하지 않은 분류는 상위 분류의 이미지를 물려받고, 아무 데도 없으면 지금처럼 아무것도 나오지 않는다.

ALTER TABLE shop_category_items
    ADD COLUMN list_banner_image VARCHAR(255) NULL COMMENT '목록 안내 이미지 (PC)' AFTER image,
    ADD COLUMN list_banner_mobile_image VARCHAR(255) NULL COMMENT '목록 안내 이미지 (모바일, 없으면 PC 이미지)' AFTER list_banner_image,
    ADD COLUMN list_banner_link VARCHAR(500) NULL COMMENT '목록 안내 이미지 링크 URL' AFTER list_banner_mobile_image;
