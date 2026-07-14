-- EmailNotify 플러그인 테이블 (3개) — 코어 Mailer(서버 메일) 기반
-- SMS/알림톡과 달리 외부 API·발신번호·예약 개념이 없어 채널 테이블 불필요.

-- 1. 도메인별 발신 설정
CREATE TABLE IF NOT EXISTS plugin_email_notify_configs (
    config_id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL COMMENT '도메인 ID',
    from_name VARCHAR(100) NOT NULL DEFAULT '' COMMENT '발신자명 (미설정 시 코어 기본값)',
    from_email VARCHAR(190) NOT NULL DEFAULT '' COMMENT '발신 이메일 (미설정 시 코어 기본값)',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '활성화',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_domain (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 도메인별 이메일 템플릿
CREATE TABLE IF NOT EXISTS plugin_email_notify_templates (
    template_id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL COMMENT '도메인 ID',
    template_code VARCHAR(50) NOT NULL COMMENT '템플릿 코드',
    template_name VARCHAR(100) NOT NULL DEFAULT '' COMMENT '템플릿명',
    subject VARCHAR(200) NOT NULL DEFAULT '' COMMENT '메일 제목 (#{변수} 치환)',
    body MEDIUMTEXT COMMENT '메일 본문 HTML (#{변수} 치환)',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '활성화',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_domain_template (domain_id, template_code),
    KEY idx_domain (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 발송 로그
CREATE TABLE IF NOT EXISTS plugin_email_notify_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL COMMENT '도메인 ID',
    template_code VARCHAR(50) NOT NULL DEFAULT '' COMMENT '템플릿 코드',
    recipient VARCHAR(190) NOT NULL DEFAULT '' COMMENT '수신 이메일',
    subject VARCHAR(200) NOT NULL DEFAULT '' COMMENT '발송 제목',
    result_code VARCHAR(20) NOT NULL DEFAULT '' COMMENT '결과 코드 (OK/FAIL/...)',
    result_message VARCHAR(200) NOT NULL DEFAULT '' COMMENT '결과 메시지',
    request_payload JSON DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_domain_created (domain_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
