CREATE TABLE IF NOT EXISTS site_change_sets (
    change_set_id CHAR(36) PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    plan_id VARCHAR(80) NOT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'site_editor',
    status VARCHAR(24) NOT NULL,
    summary VARCHAR(500) NOT NULL DEFAULT '',
    before_fingerprint CHAR(64) NULL,
    after_fingerprint CHAR(64) NULL,
    restores_change_set_id CHAR(36) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    applied_at DATETIME NULL,
    restored_at DATETIME NULL,
    INDEX idx_site_change_sets_domain_created (domain_id, created_at),
    INDEX idx_site_change_sets_restores (restores_change_set_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_change_set_snapshots (
    snapshot_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    change_set_id CHAR(36) NOT NULL,
    resource_id VARCHAR(255) NOT NULL,
    resource_type VARCHAR(60) NOT NULL,
    before_json LONGTEXT NULL,
    after_json LONGTEXT NULL,
    before_fingerprint CHAR(64) NULL,
    after_fingerprint CHAR(64) NULL,
    restore_guard_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_site_change_snapshot_resource (change_set_id, resource_id),
    INDEX idx_site_change_snapshot_type (resource_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_editor_resource_bindings (
    domain_id INT UNSIGNED NOT NULL,
    logical_key VARCHAR(190) NOT NULL,
    resource_id VARCHAR(255) NOT NULL,
    resource_type VARCHAR(60) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (domain_id, logical_key),
    INDEX idx_site_editor_binding_resource (domain_id, resource_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_edit_previews (
    preview_id CHAR(36) PRIMARY KEY,
    domain_id INT UNSIGNED NOT NULL,
    plan_id VARCHAR(80) NOT NULL,
    overlay_json LONGTEXT NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_site_edit_previews_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_change_set_asset_holds (
    change_set_id CHAR(36) NOT NULL,
    asset_key VARCHAR(500) NOT NULL,
    hold_until DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (change_set_id, asset_key),
    INDEX idx_site_asset_holds_expiry (hold_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
