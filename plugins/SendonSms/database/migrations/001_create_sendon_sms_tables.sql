-- SendonSms 플러그인 테이블 (4개) — AligoMessage 패턴 동일

-- 1. 도메인별 연동 설정
CREATE TABLE IF NOT EXISTS plugin_sendon_sms_configs (
    config_id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL COMMENT '도메인 ID',
    api_id VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'Sendon API ID (암호화)',
    api_password VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'Sendon API Password (암호화)',
    default_sender VARCHAR(20) NOT NULL DEFAULT '' COMMENT '기본 발신번호',
    test_mode TINYINT(1) NOT NULL DEFAULT 1 COMMENT '테스트 모드',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '활성화',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_domain (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 도메인별 채널 (발신번호)
CREATE TABLE IF NOT EXISTS plugin_sendon_sms_channels (
    channel_id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL COMMENT '도메인 ID',
    channel_name VARCHAR(100) NOT NULL DEFAULT '' COMMENT '채널명',
    sender_number VARCHAR(20) NOT NULL DEFAULT '' COMMENT '발신번호',
    variables JSON DEFAULT NULL COMMENT '변수 매핑 JSON',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '활성화',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_domain (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 도메인별 템플릿
CREATE TABLE IF NOT EXISTS plugin_sendon_sms_templates (
    template_id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL COMMENT '도메인 ID',
    channel_id INT NOT NULL COMMENT '채널 FK',
    template_code VARCHAR(50) NOT NULL COMMENT '템플릿 코드',
    template_name VARCHAR(100) NOT NULL DEFAULT '' COMMENT '템플릿명',
    message_type ENUM('SMS','LMS','MMS') NOT NULL DEFAULT 'SMS' COMMENT '메시지 타입',
    title VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'LMS/MMS 제목',
    message_body TEXT COMMENT '본문 (#{변수} 치환)',
    image_ids JSON DEFAULT NULL COMMENT 'MMS 이미지 ID 배열',
    variable_schema JSON DEFAULT NULL COMMENT '변수 매핑 오버라이드',
    admin_recipients VARCHAR(500) NOT NULL DEFAULT '' COMMENT '관리자 CC 수신번호',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '활성화',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_domain_template (domain_id, template_code),
    KEY idx_domain_channel (domain_id, channel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. 발송 로그
CREATE TABLE IF NOT EXISTS plugin_sendon_sms_logs (
    log_id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL COMMENT '도메인 ID',
    message_type ENUM('SMS','LMS','MMS') NOT NULL DEFAULT 'SMS' COMMENT '메시지 타입',
    template_code VARCHAR(50) NOT NULL DEFAULT '' COMMENT '템플릿 코드',
    recipient VARCHAR(20) NOT NULL DEFAULT '' COMMENT '수신번호',
    group_id VARCHAR(100) NOT NULL DEFAULT '' COMMENT 'Sendon groupId',
    is_reservation TINYINT(1) NOT NULL DEFAULT 0 COMMENT '예약 발송 여부',
    reserved_at DATETIME DEFAULT NULL COMMENT '예약 시각',
    result_code VARCHAR(20) NOT NULL DEFAULT '' COMMENT '결과 코드',
    result_message VARCHAR(200) NOT NULL DEFAULT '' COMMENT '결과 메시지',
    request_payload JSON DEFAULT NULL,
    response_payload JSON DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY idx_domain_created (domain_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
