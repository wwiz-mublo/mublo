-- 센드온 알림톡: 채널(발신프로필) 테이블

CREATE TABLE IF NOT EXISTS plugin_sendon_talk_channels (
    channel_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    channel_name VARCHAR(100) NOT NULL DEFAULT '' COMMENT '채널명 (카카오 채널명)',
    send_profile_id VARCHAR(100) NOT NULL DEFAULT '' COMMENT '센드온 발신프로필 ID',
    variables TEXT DEFAULT NULL COMMENT '채널 공유 치환 변수 JSON',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_domain (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
