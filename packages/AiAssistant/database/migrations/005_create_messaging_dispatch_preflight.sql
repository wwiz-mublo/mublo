CREATE TABLE IF NOT EXISTS ai_messaging_campaign_policies (
    campaign_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    channel VARCHAR(20) NOT NULL,
    message_class VARCHAR(20) NOT NULL,
    content_version BIGINT UNSIGNED NOT NULL,
    approved_content_version BIGINT UNSIGNED NULL,
    timezone VARCHAR(64) NOT NULL,
    quiet_hours_start CHAR(5) NULL,
    quiet_hours_end CHAR(5) NULL,
    per_recipient_daily_limit SMALLINT UNSIGNED NOT NULL,
    policy_version BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (campaign_id),
    KEY idx_ai_campaign_policies_company (company_id, channel, message_class),
    CONSTRAINT fk_ai_campaign_policies_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_messaging_dispatch_reservations (
    reservation_id CHAR(36) NOT NULL,
    preflight_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    campaign_id CHAR(36) NOT NULL,
    snapshot_batch_id CHAR(36) NOT NULL,
    customer_id CHAR(36) NOT NULL,
    customer_phone_id CHAR(36) NOT NULL,
    channel VARCHAR(20) NOT NULL,
    message_class VARCHAR(20) NOT NULL,
    content_version BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL,
    reason_codes_json TEXT NOT NULL,
    permission_version BIGINT UNSIGNED NULL,
    suppression_version BIGINT UNSIGNED NULL,
    evaluated_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (reservation_id),
    UNIQUE KEY uq_ai_dispatch_reservation_recipient
        (company_id, campaign_id, customer_phone_id, content_version),
    UNIQUE KEY uq_ai_dispatch_preflight_recipient
        (company_id, preflight_id, customer_phone_id),
    KEY idx_ai_dispatch_frequency
        (company_id, customer_phone_id, channel, status, evaluated_at),
    KEY idx_ai_dispatch_preflight (company_id, preflight_id),
    CONSTRAINT fk_ai_dispatch_reservation_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id),
    CONSTRAINT fk_ai_dispatch_reservation_campaign FOREIGN KEY (campaign_id) REFERENCES ai_messaging_campaign_policies(campaign_id),
    CONSTRAINT fk_ai_dispatch_reservation_customer FOREIGN KEY (customer_id) REFERENCES ai_customer_directory(customer_id),
    CONSTRAINT fk_ai_dispatch_reservation_phone FOREIGN KEY (customer_phone_id) REFERENCES ai_customer_phones(customer_phone_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
