-- Shop Package - 구매후기 관리자 승인 후 공개
--
-- 후기를 관리자가 확인한 뒤에만 공개하려는 쇼핑몰이 있다.
-- shop_config.review_require_approval 기본값 0 은 지금처럼 작성 즉시 공개한다.
-- 1 이면 프론트에서 쓴 후기를 비공개(is_visible = 0) + 승인 대기(is_pending = 1)로 저장하고,
-- 관리자가 승인(또는 공개로 변경)하면 is_pending 을 0 으로 되돌린다.
-- 기존 후기는 모두 is_pending = 0 (승인 대기 아님) 이다.

ALTER TABLE shop_config
    ADD COLUMN review_require_approval TINYINT(1) NOT NULL DEFAULT 0 COMMENT '후기 관리자 승인 후 공개' AFTER orderer_email_required;

ALTER TABLE shop_reviews
    ADD COLUMN is_pending TINYINT(1) NOT NULL DEFAULT 0 COMMENT '관리자 승인 대기' AFTER is_visible,
    ADD INDEX idx_domain_pending (domain_id, is_pending);
