CREATE TABLE IF NOT EXISTS ai_interactions (
    interaction_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    customer_id CHAR(36) NOT NULL,
    device_id CHAR(36) NOT NULL,
    channel VARCHAR(32) NOT NULL,
    occurred_at DATETIME NOT NULL,
    envelope_json LONGTEXT NOT NULL,
    envelope_sha256 CHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'QUEUED',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (interaction_id),
    KEY idx_ai_interactions_customer (company_id, customer_id, occurred_at),
    KEY idx_ai_interactions_status (company_id, status),
    CONSTRAINT fk_ai_interactions_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id),
    CONSTRAINT fk_ai_interactions_device FOREIGN KEY (device_id) REFERENCES ai_devices(device_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_analysis_jobs (
    job_id CHAR(36) NOT NULL,
    interaction_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    customer_id CHAR(36) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'QUEUED',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    lease_owner VARCHAR(128) NULL,
    lease_token_hash CHAR(64) NULL,
    lease_expires_at DATETIME NULL,
    last_error_code VARCHAR(64) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (job_id),
    UNIQUE KEY uq_ai_analysis_jobs_interaction (interaction_id),
    KEY idx_ai_analysis_jobs_lease (status, available_at, lease_expires_at),
    KEY idx_ai_analysis_jobs_company (company_id, customer_id),
    CONSTRAINT fk_ai_analysis_jobs_interaction FOREIGN KEY (interaction_id) REFERENCES ai_interactions(interaction_id),
    CONSTRAINT fk_ai_analysis_jobs_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_analysis_results (
    analysis_id CHAR(36) NOT NULL,
    job_id CHAR(36) NOT NULL,
    interaction_id CHAR(36) NOT NULL,
    company_id CHAR(36) NOT NULL,
    customer_id CHAR(36) NOT NULL,
    input_cursor VARCHAR(256) NOT NULL,
    result_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (analysis_id),
    UNIQUE KEY uq_ai_analysis_results_job (job_id),
    KEY idx_ai_analysis_results_customer (company_id, customer_id, created_at),
    CONSTRAINT fk_ai_analysis_results_job FOREIGN KEY (job_id) REFERENCES ai_analysis_jobs(job_id),
    CONSTRAINT fk_ai_analysis_results_interaction FOREIGN KEY (interaction_id) REFERENCES ai_interactions(interaction_id),
    CONSTRAINT fk_ai_analysis_results_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
