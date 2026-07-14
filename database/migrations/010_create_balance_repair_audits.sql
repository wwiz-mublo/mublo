-- ====================================
-- Balance repair audit trail
-- ====================================
--
-- balance_logs is an immutable transaction ledger and remains the source of
-- truth for point balance. Snapshot repair is an administrative correction of
-- members.point_balance, so its audit trail is stored separately and never
-- changes SUM(balance_logs.amount).

CREATE TABLE IF NOT EXISTS balance_repair_audits (
    audit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '감사 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    member_id BIGINT UNSIGNED NOT NULL COMMENT '회원 ID',

    snapshot_before INT NOT NULL COMMENT '복구 전 스냅샷 잔액',
    ledger_sum INT NOT NULL COMMENT '복구 기준 원장 합계',
    diff INT NOT NULL COMMENT '원장 합계 - 복구 전 스냅샷',

    admin_id BIGINT UNSIGNED NOT NULL COMMENT '복구 실행 관리자 ID',
    reason VARCHAR(255) NOT NULL COMMENT '복구 사유',
    memo TEXT NULL COMMENT '복구 상세 메모',

    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',

    INDEX idx_domain_member_created (domain_id, member_id, created_at),
    INDEX idx_admin_created (admin_id, created_at),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='포인트 잔액 스냅샷 복구 감사 기록';
