-- 사이트 편집 충돌 감지와 감사 이력을 위한 코어 revision 저장소.
-- change set은 플러그인 소유이므로 correlation_id는 FK 없는 불투명 문자열이다.

CREATE TABLE IF NOT EXISTS `site_revision_counters` (
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `revision` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`domain_id`),
    CONSTRAINT `fk_site_revision_counter_domain`
        FOREIGN KEY (`domain_id`) REFERENCES `domain_configs`(`domain_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='도메인별 사이트 감사 revision 카운터';

CREATE TABLE IF NOT EXISTS `site_revisions` (
    `event_id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `revision` BIGINT UNSIGNED NOT NULL,
    `source` VARCHAR(20) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `correlation_id` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NULL,
    `changed_resources_json` LONGTEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_site_revision_domain_revision` (`domain_id`, `revision`),
    INDEX `idx_site_revision_correlation` (`domain_id`, `correlation_id`),
    INDEX `idx_site_revision_created` (`created_at`),
    CONSTRAINT `fk_site_revision_domain`
        FOREIGN KEY (`domain_id`) REFERENCES `domain_configs`(`domain_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='사이트 변경 감사 revision';

CREATE TABLE IF NOT EXISTS `site_resource_revisions` (
    `domain_id` BIGINT UNSIGNED NOT NULL,
    `resource_id` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `resource_type` VARCHAR(50) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `fingerprint` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`domain_id`, `resource_id`),
    INDEX `idx_site_resource_type` (`domain_id`, `resource_type`),
    CONSTRAINT `fk_site_resource_revision_domain`
        FOREIGN KEY (`domain_id`) REFERENCES `domain_configs`(`domain_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='사이트 자원별 단조 revision과 fingerprint';
