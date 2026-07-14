-- ====================================
-- Mublo Core - 블록 킷 보관소 (block_kits, block_kit_applications)
-- ====================================
--
-- 배경:
--   블록 킷은 파일 하나짜리 JSON 이다. 지금은 내보내면 브라우저가 받고,
--   가져오면 곧바로 적용된다. 보관되지 않으므로 같은 블록 킷을 다시 적용하려면
--   파일을 다시 올려야 하고, "무엇을 언제 적용했는지" 를 아무도 모른다.
--
-- 설계:
--   블록 킷 JSON 본문을 DB 에 그대로 둔다(kit_json). 블록 킷은 2 MiB 상한의 텍스트이므로
--   파일시스템에 흩어 놓을 이유가 없다. 파일로 두면 도메인별 디렉터리 생성,
--   고아 파일 정리, 다중 서버 동기화, 그리고 DB 트랜잭션 밖의 쓰기가 따라온다.
--
--   대신 목록 조회는 kit_json 을 SELECT 하지 않는다. 그래서 목록에 필요한 값
--   (이름·설명·버전·저작자·대상·스크립트 포함 여부)은 별도 컬럼으로 뽑아 둔다.
--   이 컬럼들은 kit_json 의 사본이며, 업로드 시점에 검증된 값만 들어간다.
--
--   스크린샷만 파일이다. 목록에서 <img> 로 그려야 하는데, 매번 kit_json 을 파싱해
--   data URI 를 꺼내면 목록이 무거워진다. 업로드 시 한 번 추출해 캐시한다.
--
-- 적용 이력을 왜 분리하는가:
--   블록 킷 하나는 여러 번, 여러 위치에 적용된다. block_kits 에 적용 정보를 두면
--   마지막 한 번만 남는다. 되돌리기는 "특정 적용" 을 되돌리는 것이므로
--   site_config 스냅샷도 적용 단위로 붙어야 한다.
--
-- 도메인 격리:
--   블록 킷은 도메인 자산이다. domain_configs 삭제 시 함께 사라진다(CASCADE).
--   적용 이력은 블록 킷 삭제 시 함께 사라진다(CASCADE) — 이력만 남아도 의미가 없다.

CREATE TABLE IF NOT EXISTS block_kits (
    kit_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '블록 킷 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    -- 목록/검색용 사본 (kit_json 에서 뽑아 둔 값)
    kit_name VARCHAR(100) NOT NULL COMMENT '블록 킷 이름',
    kit_description VARCHAR(500) NOT NULL DEFAULT '' COMMENT '블록 킷 설명',
    kit_version VARCHAR(20) NOT NULL DEFAULT '1.0.0' COMMENT '블록 킷 버전',
    kit_author VARCHAR(100) NOT NULL DEFAULT '' COMMENT '저작자',
    kit_author_url VARCHAR(255) NOT NULL DEFAULT '' COMMENT '저작자 URL',

    -- 대상 (target.kind + target.position / target.page_code, screen은 현재 main만 지원)
    target_kind VARCHAR(10) NOT NULL COMMENT '대상 종류 (position | page | screen)',
    target_position VARCHAR(20) NULL COMMENT 'position 블록 킷의 위치 코드',
    target_menu_code VARCHAR(50) NULL COMMENT 'position 블록 킷의 메뉴 코드 (전역이면 NULL)',
    target_page_code VARCHAR(50) NULL COMMENT 'page 블록 킷의 페이지 코드',

    -- 배지/경고용
    export_mode VARCHAR(20) NOT NULL DEFAULT 'distribution' COMMENT '배포 모드 (distribution | clone)',
    contains_script TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'js 콘텐츠 포함 여부 (업로드 시 실측)',
    row_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '행 수',
    column_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '칸 수',

    -- 본문
    kit_json LONGTEXT NOT NULL COMMENT '블록 킷 JSON 원본 (2 MiB 상한)',
    screenshot_path VARCHAR(255) NULL COMMENT '추출한 스크린샷 상대경로 (없으면 NULL)',

    -- 출처
    source_type VARCHAR(10) NOT NULL DEFAULT 'upload' COMMENT '보관 경로 (upload | export)',

    is_deleted TINYINT(1) NOT NULL DEFAULT 0 COMMENT '삭제 여부 (0=정상, 1=삭제)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '등록일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_domain_id (domain_id),
    INDEX idx_domain_listing (domain_id, is_deleted, kit_id),
    INDEX idx_target (domain_id, target_kind),

    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='블록 킷 보관소';


CREATE TABLE IF NOT EXISTS block_kit_applications (
    application_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '적용 ID',
    kit_id BIGINT UNSIGNED NOT NULL COMMENT '블록 킷 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '적용된 도메인 ID',

    -- 무엇을 어디에 적용했나 (블록 킷의 target 과 다를 수 있다 — 사용자가 위치를 고른다)
    apply_mode VARCHAR(10) NOT NULL COMMENT '적용 모드 (append | replace)',
    target_kind VARCHAR(10) NOT NULL COMMENT '대상 종류 (position | page | screen)',
    target_position VARCHAR(20) NULL COMMENT '적용된 위치 코드',
    target_menu_code VARCHAR(50) NULL COMMENT '적용된 메뉴 코드',
    target_page_code VARCHAR(50) NULL COMMENT '적용된 페이지 코드',

    created_row_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '생성된 행 수',

    -- 되돌리기용. 블록 킷이 site_config 를 건드렸을 때만 채운다.
    site_config_snapshot LONGTEXT NULL COMMENT '적용 직전 site_config 스냅샷 (JSON)',

    -- 회원이 탈퇴해도 "언제 무엇이 적용됐는지" 는 남아야 한다. 그래서 CASCADE 가 아니라 SET NULL.
    applied_by BIGINT UNSIGNED NULL COMMENT '적용한 회원 ID (회원 삭제 시 NULL)',
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '적용 일시',

    INDEX idx_kit_id (kit_id),
    INDEX idx_domain_applied (domain_id, applied_at),
    INDEX idx_applied_by (applied_by),

    FOREIGN KEY (kit_id) REFERENCES block_kits(kit_id) ON DELETE CASCADE,
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
    FOREIGN KEY (applied_by) REFERENCES members(member_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='블록 킷 적용 이력';
