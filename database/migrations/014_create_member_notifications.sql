-- Core 회원 알림 센터.
-- 외부 발송(메일/SMS/알림톡)과 분리된 사이트 내부 알림 및 읽음 상태를 저장한다.

CREATE TABLE IF NOT EXISTS member_notifications (
    notification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id BIGINT UNSIGNED NOT NULL,
    member_id BIGINT UNSIGNED NOT NULL,
    actor_member_id BIGINT UNSIGNED NULL,
    type VARCHAR(100) NOT NULL COMMENT '예: board.comment.created, message.received',
    title VARCHAR(200) NOT NULL,
    body VARCHAR(1000) NOT NULL DEFAULT '',
    target_url VARCHAR(500) NULL COMMENT '사이트 내부 상대 경로',
    source_type VARCHAR(100) NOT NULL COMMENT 'core | package:Board | plugin:Message',
    source_id VARCHAR(191) NULL,
    deduplication_key VARCHAR(191) NULL,
    metadata_json JSON NULL,
    read_at DATETIME NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uq_member_notification_dedupe (domain_id, member_id, deduplication_key),
    KEY idx_member_notifications_unread (domain_id, member_id, read_at, created_at),
    KEY idx_member_notifications_source (domain_id, source_type, source_id),
    KEY idx_member_notifications_expiry (expires_at),
    CONSTRAINT fk_member_notifications_domain
        FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
    CONSTRAINT fk_member_notifications_member
        FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE CASCADE,
    CONSTRAINT fk_member_notifications_actor
        FOREIGN KEY (actor_member_id) REFERENCES members(member_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도메인별 회원 사이트 내부 알림';

-- 알림 메뉴 시딩은 시더 001(도메인 1)과 MenuService::seedDefaultMenus(신규 도메인)가 담당한다.
