-- 기간 랭킹의 도메인·시각 범위 집계를 위한 원장 인덱스.
ALTER TABLE balance_logs
    ADD INDEX idx_domain_created_member (domain_id, created_at, member_id);
