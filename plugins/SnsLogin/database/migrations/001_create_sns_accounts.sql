-- SNS 로그인 연동 계정 테이블
CREATE TABLE IF NOT EXISTS `plugin_sns_login_accounts` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `domain_id`        BIGINT UNSIGNED NOT NULL,
    `member_id`        BIGINT UNSIGNED NOT NULL,
    `provider`         VARCHAR(20)  NOT NULL COMMENT 'naver | kakao | google',
    `provider_uid`     VARCHAR(255) NOT NULL COMMENT '제공자 측 고유 사용자 ID',
    `provider_email`   VARCHAR(255) NULL     COMMENT '제공자에서 받은 이메일 (스냅샷)',
    `access_token`     TEXT         NULL     COMMENT 'AES 암호화 저장',
    `refresh_token`    TEXT         NULL     COMMENT 'AES 암호화 저장',
    `token_expires_at` DATETIME     NULL,
    `linked_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY `uk_provider_uid` (`domain_id`, `provider`, `provider_uid`),
    KEY `idx_member_id` (`member_id`),
    CONSTRAINT `fk_plugin_sns_login_accounts_member`
        FOREIGN KEY (`member_id`) REFERENCES `members`(`member_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SNS 로그인 플러그인 설정 테이블
CREATE TABLE IF NOT EXISTS `plugin_sns_login_configs` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `domain_id`  INT UNSIGNED NOT NULL UNIQUE,
    `config`     LONGTEXT     NOT NULL COMMENT 'JSON: 제공자별 Client ID/Secret, 옵션',
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
