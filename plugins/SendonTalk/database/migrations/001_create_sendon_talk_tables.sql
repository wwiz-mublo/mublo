-- 센드온 알림톡 플러그인 테이블

-- 1. 도메인별 설정
CREATE TABLE IF NOT EXISTS plugin_sendon_talk_configs (
    config_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    api_id VARCHAR(255) DEFAULT NULL COMMENT '센드온 API ID (암호화)',
    api_password VARCHAR(255) DEFAULT NULL COMMENT '센드온 API Password (암호화)',
    sender_key VARCHAR(255) DEFAULT NULL COMMENT '카카오 발신 프로필 키',
    send_profile_id VARCHAR(100) DEFAULT NULL COMMENT '센드온 채널(발신프로필) ID',
    send_profile_name VARCHAR(200) DEFAULT NULL COMMENT '채널명 (캐시)',
    test_mode TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_domain (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. 알림톡 템플릿
CREATE TABLE IF NOT EXISTS plugin_sendon_talk_templates (
    template_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    channel_id INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '채널 FK',
    send_profile_id VARCHAR(100) DEFAULT NULL COMMENT '센드온 채널(발신프로필) ID',
    send_profile_name VARCHAR(200) DEFAULT NULL COMMENT '채널명 (캐시)',
    template_code VARCHAR(50) NOT NULL COMMENT '내부 템플릿 코드',
    template_name VARCHAR(100) NOT NULL COMMENT '관리용 이름',
    kakao_template_code VARCHAR(50) DEFAULT NULL COMMENT '카카오 등록 템플릿 코드',
    kakao_template_id VARCHAR(100) DEFAULT NULL COMMENT '센드온 API 템플릿 ID',
    kakao_status ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'draft' COMMENT '카카오 심사 상태',
    kakao_rejected_reason TEXT DEFAULT NULL COMMENT '반려 사유',
    message_body TEXT NOT NULL COMMENT '메시지 본문 (#{변수} 치환)',
    buttons JSON DEFAULT NULL COMMENT '버튼 설정 [{type, name, url_mobile, url_pc}]',
    variable_schema JSON DEFAULT NULL COMMENT '변수 매핑 [{key, field}]',
    admin_recipients VARCHAR(500) DEFAULT NULL COMMENT '관리자 수신번호 (쉼표 구분)',
    fallback_sms TINYINT(1) NOT NULL DEFAULT 1 COMMENT '실패 시 LMS 대체 발송',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_domain_code (domain_id, template_code),
    INDEX idx_domain_status (domain_id, kakao_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. 발송 로그
CREATE TABLE IF NOT EXISTS plugin_sendon_talk_logs (
    log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    template_code VARCHAR(50) DEFAULT NULL,
    recipient VARCHAR(20) DEFAULT NULL,
    message_type ENUM('ALIMTALK','LMS') NOT NULL DEFAULT 'ALIMTALK' COMMENT '실제 발송 타입',
    group_id VARCHAR(100) DEFAULT NULL COMMENT 'API 응답 groupId',
    is_fallback TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'LMS 대체 발송 여부',
    result_code VARCHAR(20) DEFAULT NULL,
    result_message TEXT DEFAULT NULL,
    request_payload JSON DEFAULT NULL,
    response_payload JSON DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_domain_date (domain_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
