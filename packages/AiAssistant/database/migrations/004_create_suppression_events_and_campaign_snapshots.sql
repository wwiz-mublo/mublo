ALTER TABLE ai_suppression_entries
    ADD COLUMN customer_phone_id CHAR(36) NULL AFTER company_id,
    ADD COLUMN source VARCHAR(128) NOT NULL DEFAULT 'LEGACY' AFTER reason,
    ADD COLUMN suppression_version BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER source,
    ADD COLUMN updated_at DATETIME NULL AFTER lifted_at,
    ADD KEY idx_ai_suppression_phone (company_id, customer_phone_id, channel),
    ADD CONSTRAINT fk_ai_suppression_phone FOREIGN KEY (customer_phone_id) REFERENCES ai_customer_phones(customer_phone_id);

CREATE TABLE IF NOT EXISTS ai_suppression_events (
    event_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    customer_phone_id CHAR(36) NOT NULL,
    phone_lookup_token CHAR(64) NOT NULL,
    channel VARCHAR(20) NOT NULL,
    action VARCHAR(20) NOT NULL,
    reason VARCHAR(64) NOT NULL,
    source VARCHAR(128) NOT NULL,
    occurred_at DATETIME NOT NULL,
    suppression_version BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (event_id),
    UNIQUE KEY uq_ai_suppression_event_version
        (company_id, phone_lookup_token, channel, suppression_version),
    KEY idx_ai_suppression_events_phone (company_id, customer_phone_id, occurred_at),
    CONSTRAINT fk_ai_suppression_events_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id),
    CONSTRAINT fk_ai_suppression_events_phone FOREIGN KEY (customer_phone_id) REFERENCES ai_customer_phones(customer_phone_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_campaign_recipient_snapshots (
    snapshot_id CHAR(36) NOT NULL,
    snapshot_batch_id CHAR(36) NOT NULL,
    campaign_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    customer_id CHAR(36) NOT NULL,
    customer_phone_id CHAR(36) NOT NULL,
    channel VARCHAR(20) NOT NULL,
    message_class VARCHAR(20) NOT NULL,
    content_version BIGINT UNSIGNED NOT NULL,
    eligible TINYINT(1) NOT NULL,
    reason_codes_json TEXT NOT NULL,
    permission_version BIGINT UNSIGNED NULL,
    suppression_version BIGINT UNSIGNED NULL,
    policy_checked_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (snapshot_id),
    UNIQUE KEY uq_ai_campaign_snapshot_recipient (company_id, campaign_id, customer_phone_id),
    KEY idx_ai_campaign_snapshot_batch (company_id, snapshot_batch_id),
    KEY idx_ai_campaign_snapshot_eligibility (company_id, campaign_id, eligible),
    CONSTRAINT fk_ai_campaign_snapshot_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id),
    CONSTRAINT fk_ai_campaign_snapshot_customer FOREIGN KEY (customer_id) REFERENCES ai_customer_directory(customer_id),
    CONSTRAINT fk_ai_campaign_snapshot_phone FOREIGN KEY (customer_phone_id) REFERENCES ai_customer_phones(customer_phone_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
