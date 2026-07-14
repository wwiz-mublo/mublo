-- 010: 비밀번호 재설정 토큰 테이블
--
-- 이메일 인증 기반 비밀번호 재설정 플로우를 위한 토큰 저장
-- - 토큰은 SHA-256 해시로 저장 (평문은 이메일 링크에만 포함)
-- - 30분 만료, 1회용 (used_at 기록)
-- - Rate limiting: 이메일당/IP당 요청 빈도 제어

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    token_id      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id     BIGINT UNSIGNED NOT NULL,
    member_id     BIGINT UNSIGNED NOT NULL,
    token_hash    VARCHAR(64)  NOT NULL COMMENT 'SHA-256 해시',
    email         VARCHAR(255) NOT NULL COMMENT '발송 대상 이메일',
    ip_address    VARCHAR(45)  DEFAULT NULL COMMENT '요청 IP',
    expires_at    DATETIME     NOT NULL COMMENT '만료 시각',
    used_at       DATETIME     DEFAULT NULL COMMENT '사용 시각 (NULL=미사용)',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY uk_token_hash (token_hash),
    INDEX idx_member_expires (member_id, expires_at),
    INDEX idx_domain_email_created (domain_id, email, created_at),
    INDEX idx_ip_created (ip_address, created_at),

    CONSTRAINT fk_prt_domain FOREIGN KEY (domain_id)
        REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
    CONSTRAINT fk_prt_member FOREIGN KEY (member_id)
        REFERENCES members(member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
