CREATE TABLE IF NOT EXISTS ai_customer_directory (
    customer_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    display_name_ciphertext LONGTEXT NOT NULL,
    management_status VARCHAR(20) NOT NULL,
    object_version BIGINT UNSIGNED NOT NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (customer_id),
    KEY idx_ai_customer_directory_company_status (company_id, management_status, deleted_at),
    CONSTRAINT fk_ai_customer_directory_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_customer_phones (
    customer_phone_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    customer_id CHAR(36) NOT NULL,
    phone_ciphertext LONGTEXT NOT NULL,
    phone_lookup_token CHAR(64) NOT NULL,
    management_status VARCHAR(20) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    object_version BIGINT UNSIGNED NOT NULL,
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (customer_phone_id),
    KEY idx_ai_customer_phones_owner (company_id, customer_id, deleted_at),
    KEY idx_ai_customer_phones_lookup (company_id, phone_lookup_token, deleted_at),
    CONSTRAINT fk_ai_customer_phones_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id),
    CONSTRAINT fk_ai_customer_phones_customer FOREIGN KEY (customer_id) REFERENCES ai_customer_directory(customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_contact_permissions (
    permission_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    customer_phone_id CHAR(36) NOT NULL,
    channel VARCHAR(20) NOT NULL,
    purpose VARCHAR(32) NOT NULL,
    status VARCHAR(20) NOT NULL,
    legal_basis VARCHAR(64) NOT NULL,
    captured_at DATETIME NOT NULL,
    source VARCHAR(64) NOT NULL,
    expires_at DATETIME NULL,
    permission_version BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (permission_id),
    UNIQUE KEY uq_ai_contact_permissions_scope (company_id, customer_phone_id, channel, purpose),
    KEY idx_ai_contact_permissions_status (company_id, channel, purpose, status, expires_at),
    CONSTRAINT fk_ai_contact_permissions_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id),
    CONSTRAINT fk_ai_contact_permissions_phone FOREIGN KEY (customer_phone_id) REFERENCES ai_customer_phones(customer_phone_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_suppression_entries (
    suppression_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    phone_lookup_token CHAR(64) NOT NULL,
    channel VARCHAR(20) NOT NULL,
    reason VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    lifted_at DATETIME NULL,
    PRIMARY KEY (suppression_id),
    UNIQUE KEY uq_ai_suppression_scope (company_id, phone_lookup_token, channel),
    KEY idx_ai_suppression_active (company_id, channel, lifted_at),
    CONSTRAINT fk_ai_suppression_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE ai_interactions
    ADD COLUMN customer_phone_id CHAR(36) NULL AFTER customer_id,
    ADD KEY idx_ai_interactions_phone (company_id, customer_phone_id, occurred_at),
    ADD CONSTRAINT fk_ai_interactions_phone FOREIGN KEY (customer_phone_id) REFERENCES ai_customer_phones(customer_phone_id);
