CREATE TABLE IF NOT EXISTS ai_subscription_plans (
    plan_code VARCHAR(40) NOT NULL,
    name VARCHAR(100) NOT NULL,
    monthly_price_krw INT UNSIGNED NULL,
    customer_limit INT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (plan_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_company_subscriptions (
    company_id CHAR(36) NOT NULL,
    plan_code VARCHAR(40) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    started_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (company_id),
    KEY idx_ai_company_subscriptions_plan_status (plan_code, status),
    CONSTRAINT fk_ai_company_subscriptions_company FOREIGN KEY (company_id) REFERENCES ai_companies(company_id),
    CONSTRAINT fk_ai_company_subscriptions_plan FOREIGN KEY (plan_code) REFERENCES ai_subscription_plans(plan_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO ai_subscription_plans
    (plan_code, name, monthly_price_krw, customer_limit, status, created_at, updated_at)
VALUES
    ('BASIC', 'Basic', NULL, 30, 'ACTIVE', UTC_TIMESTAMP(), UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    customer_limit = VALUES(customer_limit),
    status = VALUES(status),
    updated_at = VALUES(updated_at);

INSERT INTO ai_company_subscriptions
    (company_id, plan_code, status, started_at, created_at, updated_at)
SELECT company_id, 'BASIC', 'ACTIVE', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()
  FROM ai_companies
ON DUPLICATE KEY UPDATE
    updated_at = ai_company_subscriptions.updated_at;
