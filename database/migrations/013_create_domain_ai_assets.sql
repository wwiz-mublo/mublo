-- Domain-scoped reusable AI reference assets and HTML generation history.

CREATE TABLE IF NOT EXISTS domain_ai_assets (
    asset_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id BIGINT UNSIGNED NOT NULL,
    parent_asset_id BIGINT UNSIGNED NULL COMMENT '이미지 편집본의 원본 자료',
    kind VARCHAR(20) NOT NULL COMMENT 'image | document',
    title VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    extension VARCHAR(20) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    storage_path VARCHAR(500) NOT NULL COMMENT 'storage/files 하위 비공개 상대 경로',
    sha256 CHAR(64) NOT NULL,
    extracted_text MEDIUMTEXT NULL,
    metadata_json JSON NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_domain_ai_asset_hash (domain_id, sha256),
    KEY idx_domain_ai_assets_kind_created (domain_id, kind, created_at),
    KEY idx_domain_ai_assets_parent (parent_asset_id),
    CONSTRAINT fk_domain_ai_assets_domain
        FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
    CONSTRAINT fk_domain_ai_assets_parent
        FOREIGN KEY (parent_asset_id) REFERENCES domain_ai_assets(asset_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도메인별 재사용 AI 이미지·문서 자료';

CREATE TABLE IF NOT EXISTS domain_ai_generation_records (
    record_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id BIGINT UNSIGNED NOT NULL,
    member_id BIGINT UNSIGNED NULL,
    row_id BIGINT UNSIGNED NOT NULL,
    column_index INT UNSIGNED NOT NULL,
    mode VARCHAR(20) NOT NULL,
    provider VARCHAR(20) NOT NULL,
    model VARCHAR(100) NOT NULL,
    prompt TEXT NOT NULL,
    asset_ids_json JSON NULL,
    result_html MEDIUMTEXT NULL,
    result_css MEDIUMTEXT NULL,
    result_js MEDIUMTEXT NULL,
    notes TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'success',
    error_message VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    KEY idx_domain_ai_records_created (domain_id, created_at),
    KEY idx_domain_ai_records_target (domain_id, row_id, column_index, created_at),
    CONSTRAINT fk_domain_ai_records_domain
        FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='HTML 블록 AI 생성·재사용 기록';
