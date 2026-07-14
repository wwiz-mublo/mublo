-- 블록 칸 다중 콘텐츠 스택 (콘텐츠 스택)
-- 설계: storage/docs/block-column-content-stack-implementation-plan-2026-07-25.md
--
-- content_mode : 'single'(기존, 기본) | 'stack'(하위 콘텐츠 소유)
-- pc/mobile_content_gap : 스택 자식 사이 CSS gap(px)
-- 문장을 분리해 이미 적용된 컬럼/테이블은 MigrationRunner가 개별 건너뛴다.
-- MySQL/MariaDB 공통 문법만 사용한다.

ALTER TABLE `block_columns`
    ADD COLUMN `content_mode` VARCHAR(10) NOT NULL DEFAULT 'single'
        COMMENT '콘텐츠 배치 상태 (single|stack)' AFTER `content_items`;

ALTER TABLE `block_columns`
    ADD COLUMN `pc_content_gap` SMALLINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'PC 스택 콘텐츠 간격(px)' AFTER `content_mode`;

ALTER TABLE `block_columns`
    ADD COLUMN `mobile_content_gap` SMALLINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Mobile 스택 콘텐츠 간격(px)' AFTER `pc_content_gap`;

-- 복합 FK 대상: 자식 테이블이 (column_id, domain_id) 를 참조하려면 부모에 해당 키 필요
ALTER TABLE `block_columns`
    ADD UNIQUE KEY `uq_block_columns_id_domain` (`column_id`, `domain_id`);

CREATE TABLE IF NOT EXISTS `block_column_contents` (
    `content_id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '콘텐츠 ID',
    `column_id` BIGINT UNSIGNED NOT NULL COMMENT '소속 칸 ID',
    `domain_id` BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '스택 내 세로 순서 (UNIQUE 없음 — ORDER BY sort_order, content_id 로 결정성 보장)',
    `title_config` JSON NULL COMMENT '제목 설정 (block_columns.title_config 과 동일 구조)',
    `content_type` VARCHAR(50) NULL COMMENT '콘텐츠 타입 (board, banner, product, html 등)',
    `content_kind` VARCHAR(20) NOT NULL DEFAULT 'CORE' COMMENT '콘텐츠 종류 (CORE, PLUGIN, PACKAGE)',
    `content_skin` VARCHAR(50) NULL COMMENT '스킨',
    `content_config` JSON NULL COMMENT '콘텐츠 설정 (block_columns.content_config 과 동일 구조)',
    `content_items` JSON NULL COMMENT '출력 데이터 (block_columns.content_items 와 동일 구조)',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',
    INDEX `idx_domain_type_active` (`domain_id`, `content_type`, `is_active`),
    INDEX `idx_domain_kind_active` (`domain_id`, `content_kind`, `is_active`),
    INDEX `idx_column_active_sort` (`column_id`, `is_active`, `sort_order`),
    CONSTRAINT `fk_bcc_column_domain`
        FOREIGN KEY (`column_id`, `domain_id`)
        REFERENCES `block_columns`(`column_id`, `domain_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bcc_domain`
        FOREIGN KEY (`domain_id`) REFERENCES `domain_configs`(`domain_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='블록 칸 스택 하위 콘텐츠';
