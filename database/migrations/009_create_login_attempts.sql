-- 로그인 시도 기록 테이블 (Rate Limiting)
CREATE TABLE IF NOT EXISTS login_attempts (
    attempt_id    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id     BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    user_id       VARCHAR(100)    NOT NULL COMMENT '시도한 아이디',
    ip_address    VARCHAR(45)     NOT NULL COMMENT '요청 IP',
    is_successful TINYINT(1)      NOT NULL DEFAULT 0 COMMENT '성공 여부 (0=실패, 1=성공)',
    attempted_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '시도 시각',

    INDEX idx_domain_user_attempted (domain_id, user_id, attempted_at),
    INDEX idx_ip_attempted (ip_address, attempted_at),
    INDEX idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
