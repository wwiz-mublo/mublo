-- ====================================
-- Innopay Plugin - 가상계좌 발급 정보
-- ====================================
-- 가상계좌는 승인 = 계좌 발급이고, 입금은 결제결과 통보(Noti)로 따로 온다.
-- 그 사이(DEPOSIT_WAITING) 구매자에게 다시 보여줄 계좌 정보를 결제 시도에 둔다.
ALTER TABLE plugin_innopay_attempts
    ADD COLUMN vbank_bank     VARCHAR(30) NULL COMMENT '가상계좌 은행명' AFTER tid,
    ADD COLUMN vbank_account  VARCHAR(30) NULL COMMENT '가상계좌 번호' AFTER vbank_bank,
    ADD COLUMN vbank_due_date VARCHAR(10) NULL COMMENT '입금 기한 (Y-m-d)' AFTER vbank_account;
