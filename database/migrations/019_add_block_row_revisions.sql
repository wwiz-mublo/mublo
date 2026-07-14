-- 블록 행 낙관적 잠금과 복구 이력
-- 문장을 분리해 이미 적용된 컬럼/테이블은 MigrationRunner가 개별 건너뛴다.
ALTER TABLE `block_rows`
    ADD COLUMN `revision_no` BIGINT UNSIGNED NOT NULL DEFAULT 1
        COMMENT '낙관적 잠금 버전' AFTER `dismiss_hours`;

CREATE TABLE IF NOT EXISTS `block_row_revisions` (
    `revision_id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `row_id` BIGINT UNSIGNED NOT NULL COMMENT '삭제 후에도 원래 행 ID를 보존',
    `row_revision_no` BIGINT UNSIGNED NOT NULL,
    `source` VARCHAR(30) NOT NULL DEFAULT 'interactive',
    `snapshot_json` LONGTEXT NOT NULL,
    `created_by` BIGINT UNSIGNED NULL,
    `restored_row_id` BIGINT UNSIGNED NULL COMMENT '복구로 새로 생성된 행 ID',
    `restored_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_block_row_revision_row` (`domain_id`, `row_id`, `revision_id`),
    INDEX `idx_block_row_revision_restore` (`domain_id`, `restored_at`, `revision_id`),
    INDEX `idx_block_row_revision_created` (`created_at`),
    CONSTRAINT `fk_block_row_revision_domain`
        FOREIGN KEY (`domain_id`) REFERENCES `domain_configs`(`domain_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_block_row_revision_member`
        FOREIGN KEY (`created_by`) REFERENCES `members`(`member_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='블록 행 변경 전 스냅샷';
