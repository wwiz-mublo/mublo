-- Domain-scoped AI provider credentials and daily usage counters.
-- API keys are encrypted by EncryptionService before they reach this table.

CREATE TABLE IF NOT EXISTS domain_ai_configs (
    domain_id BIGINT UNSIGNED PRIMARY KEY COMMENT '도메인 ID',
    provider VARCHAR(20) NOT NULL COMMENT 'openai | anthropic | gemini',
    model VARCHAR(100) NOT NULL COMMENT '서버 허용 모델 ID',
    encrypted_api_key TEXT NULL COMMENT 'AES-256-GCM 암호문',
    is_enabled TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'AI 기능 활성 여부',
    daily_request_limit INT UNSIGNED NOT NULL DEFAULT 50 COMMENT '도메인별 일일 요청 상한',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT fk_domain_ai_configs_domain
        FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도메인별 AI 공급자 설정';

CREATE TABLE IF NOT EXISTS domain_ai_usage_daily (
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    usage_date DATE NOT NULL COMMENT '도메인 사용량 집계일',
    request_count INT UNSIGNED NOT NULL DEFAULT 0,
    input_chars BIGINT UNSIGNED NOT NULL DEFAULT 0,
    output_chars BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (domain_id, usage_date),
    CONSTRAINT fk_domain_ai_usage_domain
        FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도메인별 AI 일일 사용량';
