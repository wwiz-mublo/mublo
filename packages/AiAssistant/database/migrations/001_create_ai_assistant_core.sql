CREATE TABLE IF NOT EXISTS ai_companies (
    company_id CHAR(36) NOT NULL,
    framework_domain_id BIGINT UNSIGNED NULL,
    slug VARCHAR(63) NOT NULL,
    name VARCHAR(190) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (company_id),
    UNIQUE KEY uq_ai_companies_slug (slug),
    UNIQUE KEY uq_ai_companies_framework_domain (framework_domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_company_users (
    user_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    login_id VARCHAR(190) NOT NULL,
    nickname VARCHAR(190) NOT NULL DEFAULT '',
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    UNIQUE KEY uq_ai_company_users_login (company_id, login_id),
    KEY idx_ai_company_users_company_status (company_id, status),
    CONSTRAINT fk_ai_company_users_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_auth_tokens (
    token_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    access_hash CHAR(64) NOT NULL,
    refresh_hash CHAR(64) NOT NULL,
    access_expires_at DATETIME NOT NULL,
    refresh_expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    rotated_to_token_id CHAR(36) NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (token_id),
    UNIQUE KEY uq_ai_auth_tokens_access (access_hash),
    UNIQUE KEY uq_ai_auth_tokens_refresh (refresh_hash),
    KEY idx_ai_auth_tokens_principal (company_id, user_id, revoked_at),
    CONSTRAINT fk_ai_auth_tokens_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id),
    CONSTRAINT fk_ai_auth_tokens_user FOREIGN KEY (user_id) REFERENCES ai_company_users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_devices (
    device_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    enrolled_by_user_id CHAR(36) NOT NULL,
    installation_hash CHAR(64) NOT NULL,
    name VARCHAR(190) NOT NULL,
    platform VARCHAR(20) NOT NULL,
    public_key TEXT NOT NULL,
    fcm_token TEXT NULL,
    capabilities_json TEXT NOT NULL,
    health_json TEXT NULL,
    app_version VARCHAR(64) NOT NULL DEFAULT '',
    os_version VARCHAR(64) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    last_seen_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (device_id),
    UNIQUE KEY uq_ai_devices_installation (company_id, installation_hash),
    KEY idx_ai_devices_company_status (company_id, status),
    CONSTRAINT fk_ai_devices_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id),
    CONSTRAINT fk_ai_devices_user FOREIGN KEY (enrolled_by_user_id) REFERENCES ai_company_users(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_sync_records (
    record_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id CHAR(36) NOT NULL,
    object_type VARCHAR(40) NOT NULL,
    object_id CHAR(36) NOT NULL,
    operation VARCHAR(10) NOT NULL,
    object_version BIGINT UNSIGNED NOT NULL,
    payload_json LONGTEXT NOT NULL,
    search_token CHAR(64) NULL,
    source_device_id CHAR(36) NULL,
    object_updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    change_sequence BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (record_id),
    UNIQUE KEY uq_ai_sync_records_object (company_id, object_type, object_id),
    KEY idx_ai_sync_records_cursor (company_id, change_sequence),
    KEY idx_ai_sync_records_search (company_id, object_type, search_token),
    CONSTRAINT fk_ai_sync_records_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id),
    CONSTRAINT fk_ai_sync_records_device FOREIGN KEY (source_device_id) REFERENCES ai_devices(device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_sync_changes (
    sequence_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id CHAR(36) NOT NULL,
    object_type VARCHAR(40) NOT NULL,
    object_id CHAR(36) NOT NULL,
    operation VARCHAR(10) NOT NULL,
    object_version BIGINT UNSIGNED NOT NULL,
    payload_json LONGTEXT NOT NULL,
    object_updated_at DATETIME NOT NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (sequence_id),
    KEY idx_ai_sync_changes_cursor (company_id, sequence_id),
    CONSTRAINT fk_ai_sync_changes_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_idempotency_keys (
    idempotency_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id CHAR(36) NOT NULL,
    endpoint VARCHAR(120) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    response_json LONGTEXT NOT NULL,
    response_status SMALLINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (idempotency_id),
    UNIQUE KEY uq_ai_idempotency_scope (company_id, endpoint, key_hash),
    KEY idx_ai_idempotency_expiry (expires_at),
    CONSTRAINT fk_ai_idempotency_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
