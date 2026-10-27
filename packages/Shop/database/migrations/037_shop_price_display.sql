-- Shop Package - 가격 공개 대상 설정
--
-- 회원 인증을 받은 회원에게만 실제 판매가를 보여주는 쇼핑몰이 있다.
-- 기본값 ALL 은 지금처럼 모든 방문자에게 가격을 보여준다.

ALTER TABLE shop_config
    ADD COLUMN price_display VARCHAR(12) NOT NULL DEFAULT 'ALL' COMMENT '가격 공개 대상 (ALL/MEMBER/LEVEL)' AFTER detail_tab_order,
    ADD COLUMN price_display_level INT NOT NULL DEFAULT 0 COMMENT '가격 공개 최소 등급값 (LEVEL 일 때)' AFTER price_display,
    ADD COLUMN price_hidden_text VARCHAR(100) NULL COMMENT '가격 비공개 시 표시 문구' AFTER price_display_level;
