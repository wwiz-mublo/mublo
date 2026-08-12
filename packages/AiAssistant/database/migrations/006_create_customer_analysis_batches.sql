CREATE TABLE IF NOT EXISTS ai_interaction_v3_index (
    server_sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interaction_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    customer_id CHAR(36) NOT NULL,
    customer_phone_id CHAR(36) NOT NULL,
    device_id CHAR(36) NOT NULL,
    channel VARCHAR(32) NOT NULL,
    source_record_id VARCHAR(256) NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (server_sequence),
    UNIQUE KEY uq_ai_interaction_v3_interaction (interaction_id),
    UNIQUE KEY uq_ai_interaction_v3_source (company_id, device_id, channel, source_record_id),
    KEY idx_ai_interaction_v3_manifest (company_id, customer_id, channel, server_sequence),
    CONSTRAINT fk_ai_interaction_v3_interaction FOREIGN KEY (interaction_id) REFERENCES ai_interactions(interaction_id),
    CONSTRAINT fk_ai_interaction_v3_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id),
    CONSTRAINT fk_ai_interaction_v3_customer FOREIGN KEY (customer_id) REFERENCES ai_customer_directory(customer_id),
    CONSTRAINT fk_ai_interaction_v3_phone FOREIGN KEY (customer_phone_id) REFERENCES ai_customer_phones(customer_phone_id),
    CONSTRAINT fk_ai_interaction_v3_device FOREIGN KEY (device_id) REFERENCES ai_devices(device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_analysis_consent_receipts (
    consent_receipt_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    user_id CHAR(36) NOT NULL,
    device_id CHAR(36) NOT NULL,
    consent_version VARCHAR(64) NOT NULL,
    accepted_at DATETIME NOT NULL,
    selected_customer_set_sha256 CHAR(64) NOT NULL,
    customer_ids_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (consent_receipt_id),
    KEY idx_ai_analysis_consent_company (company_id, accepted_at),
    CONSTRAINT fk_ai_analysis_consent_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id),
    CONSTRAINT fk_ai_analysis_consent_user FOREIGN KEY (user_id) REFERENCES ai_company_users(user_id),
    CONSTRAINT fk_ai_analysis_consent_device FOREIGN KEY (device_id) REFERENCES ai_devices(device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_analysis_batches (
    batch_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    requested_by_user_id CHAR(36) NOT NULL,
    device_id CHAR(36) NOT NULL,
    consent_receipt_id CHAR(36) NOT NULL,
    purpose VARCHAR(32) NOT NULL,
    selected_customer_set_sha256 CHAR(64) NOT NULL,
    status VARCHAR(32) NOT NULL,
    total_customers SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (batch_id),
    KEY idx_ai_analysis_batches_company (company_id, created_at),
    CONSTRAINT fk_ai_analysis_batches_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id),
    CONSTRAINT fk_ai_analysis_batches_user FOREIGN KEY (requested_by_user_id) REFERENCES ai_company_users(user_id),
    CONSTRAINT fk_ai_analysis_batches_device FOREIGN KEY (device_id) REFERENCES ai_devices(device_id),
    CONSTRAINT fk_ai_analysis_batches_consent FOREIGN KEY (consent_receipt_id) REFERENCES ai_analysis_consent_receipts(consent_receipt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_analysis_runs (
    run_id CHAR(36) NOT NULL,
    batch_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    customer_id CHAR(36) NOT NULL,
    mode VARCHAR(20) NOT NULL,
    base_analysis_id CHAR(36) NULL,
    from_cursor BIGINT UNSIGNED NULL,
    input_cursor BIGINT UNSIGNED NOT NULL,
    status VARCHAR(32) NOT NULL,
    stage VARCHAR(32) NOT NULL,
    progress_processed BIGINT UNSIGNED NULL,
    progress_total BIGINT UNSIGNED NULL,
    progress_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
    retryable TINYINT(1) NOT NULL DEFAULT 0,
    reason_code VARCHAR(64) NULL,
    analysis_id CHAR(36) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (run_id),
    UNIQUE KEY uq_ai_analysis_runs_batch_customer (batch_id, customer_id),
    KEY idx_ai_analysis_runs_company_customer (company_id, customer_id, created_at),
    KEY idx_ai_analysis_runs_status (status, updated_at),
    CONSTRAINT fk_ai_analysis_runs_batch FOREIGN KEY (batch_id) REFERENCES ai_analysis_batches(batch_id),
    CONSTRAINT fk_ai_analysis_runs_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id),
    CONSTRAINT fk_ai_analysis_runs_customer FOREIGN KEY (customer_id) REFERENCES ai_customer_directory(customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_analysis_manifests (
    manifest_id CHAR(36) NOT NULL,
    run_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    customer_id CHAR(36) NOT NULL,
    manifest_sha256 CHAR(64) NOT NULL,
    manifest_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (manifest_id),
    UNIQUE KEY uq_ai_analysis_manifests_run (run_id),
    KEY idx_ai_analysis_manifests_company (company_id, customer_id, created_at),
    CONSTRAINT fk_ai_analysis_manifests_run FOREIGN KEY (run_id) REFERENCES ai_analysis_runs(run_id),
    CONSTRAINT fk_ai_analysis_manifests_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_analysis_jobs_v2 (
    job_id CHAR(36) NOT NULL,
    run_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    status VARCHAR(32) NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    lease_owner VARCHAR(128) NULL,
    lease_token_hash CHAR(64) NULL,
    lease_expires_at DATETIME NULL,
    last_error_code VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (job_id),
    UNIQUE KEY uq_ai_analysis_jobs_v2_run (run_id),
    KEY idx_ai_analysis_jobs_v2_lease (status, available_at, lease_expires_at),
    CONSTRAINT fk_ai_analysis_jobs_v2_run FOREIGN KEY (run_id) REFERENCES ai_analysis_runs(run_id),
    CONSTRAINT fk_ai_analysis_jobs_v2_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_analysis_results_v2 (
    analysis_id CHAR(36) NOT NULL,
    job_id CHAR(36) NOT NULL,
    run_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    customer_id CHAR(36) NOT NULL,
    manifest_sha256 CHAR(64) NOT NULL,
    terminal_status VARCHAR(32) NOT NULL,
    result_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (analysis_id),
    UNIQUE KEY uq_ai_analysis_results_v2_job (job_id),
    UNIQUE KEY uq_ai_analysis_results_v2_run (run_id),
    KEY idx_ai_analysis_results_v2_customer (company_id, customer_id, created_at),
    CONSTRAINT fk_ai_analysis_results_v2_job FOREIGN KEY (job_id) REFERENCES ai_analysis_jobs_v2(job_id),
    CONSTRAINT fk_ai_analysis_results_v2_run FOREIGN KEY (run_id) REFERENCES ai_analysis_runs(run_id),
    CONSTRAINT fk_ai_analysis_results_v2_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_worker_heartbeats (
    worker_id VARCHAR(128) NOT NULL,
    status VARCHAR(20) NOT NULL,
    capabilities_json LONGTEXT NOT NULL,
    active_job_count SMALLINT UNSIGNED NOT NULL,
    version VARCHAR(64) NOT NULL,
    observed_at DATETIME NOT NULL,
    received_at DATETIME NOT NULL,
    PRIMARY KEY (worker_id),
    KEY idx_ai_worker_heartbeats_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
