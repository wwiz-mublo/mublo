-- 도메인 프레임 편집: header/footer HTML 오버라이드 저장.
-- DB에는 {{...}} 템플릿 원문을 저장하고 치환은 렌더 시점에 한다 (원문 저장 원칙).
-- 설계: storage/docs/Mublo_Domain_Frame_Editing_Implementation_Plan.md §4

CREATE TABLE IF NOT EXISTS domain_frame_overrides (
    override_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id BIGINT UNSIGNED NOT NULL,
    part VARCHAR(20) NOT NULL COMMENT 'header | footer',
    status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft | published',
    html MEDIUMTEXT NULL COMMENT '게시본 템플릿 원문 ({{...}} 유지)',
    css MEDIUMTEXT NULL,
    js MEDIUMTEXT NULL,
    draft_html MEDIUMTEXT NULL,
    draft_css MEDIUMTEXT NULL,
    draft_js MEDIUMTEXT NULL,
    seeded_from_skin VARCHAR(100) NULL COMMENT '시드 출처 스킨 (detached copy 안내용)',
    updated_by BIGINT UNSIGNED NULL COMMENT '수정 관리자 member_id (감사)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY uq_domain_frame_part (domain_id, part),
    CONSTRAINT fk_domain_frame_overrides_domain
        FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도메인 프레임(header/footer) HTML 오버라이드';
