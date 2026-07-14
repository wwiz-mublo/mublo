-- 대리 로그인 토큰 테이블
-- 상위 관리자가 하위 도메인 관리자로 접속할 때 사용하는 일회용 토큰

CREATE TABLE IF NOT EXISTS proxy_login_tokens (
    token_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(64) NOT NULL,
    source_domain_id BIGINT UNSIGNED NOT NULL COMMENT '발행한 도메인 ID',
    target_domain_id BIGINT UNSIGNED NOT NULL COMMENT '대상 도메인 ID',
    admin_member_id BIGINT UNSIGNED NOT NULL COMMENT '발행한 관리자 회원 ID',
    redirect_url VARCHAR(500) NOT NULL DEFAULT '/admin/dashboard' COMMENT '로그인 후 리다이렉트 URL',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    used TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY uk_token (token),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
