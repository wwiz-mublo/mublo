-- 정책 본문을 불변 개정본으로 보존하고 회원 동의를 정확한 개정본에 연결한다.

CREATE TABLE IF NOT EXISTS policy_revisions (
    revision_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    policy_id BIGINT UNSIGNED NOT NULL,
    domain_id BIGINT UNSIGNED NOT NULL,
    version VARCHAR(20) NOT NULL,
    title VARCHAR(200) NOT NULL,
    content LONGTEXT NOT NULL,
    content_hash CHAR(64) NOT NULL,
    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_policy_revision_version (policy_id, version),
    INDEX idx_policy_revision_domain (domain_id, policy_id),
    INDEX idx_policy_revision_hash (content_hash),
    CONSTRAINT fk_policy_revision_policy
        FOREIGN KEY (policy_id) REFERENCES policies(policy_id) ON DELETE CASCADE,
    CONSTRAINT fk_policy_revision_domain
        FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='정책/약관 불변 개정본';

ALTER TABLE policies
    ADD COLUMN current_revision_id BIGINT UNSIGNED NULL AFTER version,
    ADD INDEX idx_policy_current_revision (current_revision_id);

INSERT INTO policy_revisions
    (policy_id, domain_id, version, title, content, content_hash, published_at, created_at)
SELECT policy_id, domain_id, version, title, content, SHA2(content, 256), created_at, created_at
FROM policies;

UPDATE policies p
INNER JOIN policy_revisions r
    ON r.policy_id = p.policy_id AND r.version = p.version
SET p.current_revision_id = r.revision_id;

ALTER TABLE member_policy_agreements
    ADD COLUMN domain_id BIGINT UNSIGNED NULL AFTER member_id,
    ADD COLUMN revision_id BIGINT UNSIGNED NULL AFTER policy_id,
    ADD COLUMN content_hash CHAR(64) NULL AFTER policy_version,
    ADD COLUMN user_agent VARCHAR(255) NULL AFTER ip_address;

UPDATE member_policy_agreements a
INNER JOIN policies p ON p.policy_id = a.policy_id
LEFT JOIN policy_revisions exact_revision
    ON exact_revision.policy_id = a.policy_id AND exact_revision.version = a.policy_version
LEFT JOIN policy_revisions current_revision
    ON current_revision.revision_id = p.current_revision_id
SET a.domain_id = p.domain_id,
    a.revision_id = COALESCE(exact_revision.revision_id, current_revision.revision_id),
    a.content_hash = COALESCE(exact_revision.content_hash, current_revision.content_hash);

ALTER TABLE member_policy_agreements
    DROP INDEX uk_member_policy,
    MODIFY COLUMN domain_id BIGINT UNSIGNED NOT NULL,
    MODIFY COLUMN revision_id BIGINT UNSIGNED NOT NULL,
    MODIFY COLUMN content_hash CHAR(64) NOT NULL,
    ADD UNIQUE KEY uk_member_policy_revision (member_id, revision_id),
    ADD INDEX idx_agreement_domain (domain_id),
    ADD INDEX idx_agreement_revision (revision_id),
    ADD CONSTRAINT fk_agreement_domain
        FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_agreement_revision
        FOREIGN KEY (revision_id) REFERENCES policy_revisions(revision_id) ON DELETE RESTRICT;
