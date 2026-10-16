-- 도메인 별칭 (한 사이트에 접속 주소를 여럿 두기)
--
-- 사이트 주소(domain_configs.domain)는 하나지만, 여기 등록한 호스트로 들어온 요청도
-- 같은 사이트(domain_id)로 처리한다. 쓰임새:
--   - 주소를 옮기는 동안 옛 주소·새 주소를 함께 살려 둔다 (앱처럼 주소가 박혀 있는 클라이언트).
--   - 호스트명 변경 때 옛 주소를 별칭으로 남겨, 변경 후에도 옛 주소가 끊기지 않게 한다.
--
-- 호스트 하나는 domain_configs.domain 과 domain_aliases.alias_host 를 통틀어 한 곳에만 있어야 한다.
-- 테이블을 넘나드는 유일성은 DB 제약으로 걸 수 없어 DomainService::isDomainTaken() 한 곳에서 판정한다.
-- 해석은 진짜 주소가 우선이다 (DomainResolver — domain_configs 에서 못 찾을 때만 별칭을 본다).
--
-- 사이트를 지우면 별칭도 함께 지운다 (FK CASCADE).

CREATE TABLE IF NOT EXISTS domain_aliases (
    alias_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '별칭 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '연결된 사이트 도메인 ID',
    alias_host VARCHAR(255) NOT NULL COMMENT '별칭 호스트명 (소문자, 포트 포함 가능)',
    created_by BIGINT UNSIGNED NULL COMMENT '등록한 관리자 회원 ID',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '등록일',

    UNIQUE KEY uk_alias_host (alias_host),
    INDEX idx_domain_id (domain_id),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도메인 별칭';

-- 검증 기록의 용도 — 별칭 추가에도 같은 DNS 확인을 쓰므로, 변경 이력(purpose='change')과 구분한다.
-- 기존 행은 모두 호스트명 변경이었으므로 기본값 'change' 그대로 맞다.
-- (중복 컬럼은 MigrationRunner가 건너뛴다.)
ALTER TABLE `domain_verifications`
    ADD COLUMN `purpose` ENUM('change', 'alias') NOT NULL DEFAULT 'change' COMMENT '소진 용도 (change: 호스트명 변경, alias: 별칭 추가)' AFTER `status`;
