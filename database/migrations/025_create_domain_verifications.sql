-- 도메인 호스트명 변경 전 DNS/도달 검증 기록 + 변경 감사
--
-- 호스트명 변경은 "그 호스트가 실제로 이 설치본에 도달하는가"를 먼저 증명해야 한다.
-- 검증 요청 시 nonce를 발급해 pending 행을 만들고,
--   1) DNS A/AAAA/CNAME 조회 (보고용)
--   2) http(s)://{host}/.well-known/mublo-domain-verify?nonce=... 루프백 프로브 (합격 판정)
-- 결과를 이 테이블에 남긴다. 변경 API는 클라이언트 응답을 믿지 않고
-- 이 테이블에서 passed 행을 다시 찾아 게이트한다(없으면 반려).
--
-- 실제 변경까지 간 행은 status='consumed'로 마감되며 그때 감사 정보가 채워진다
-- (previous_host / consumed_by / consumed_at). 확인에서 끝난 행(pending·passed·failed)은
-- 이 값들이 NULL이다 — 변경 이력 조회는 status='consumed'만 본다.
--
-- requested_by(검증 요청자)와 consumed_by(변경 실행자)를 나눠 두는 이유:
-- 최고관리자는 여러 명일 수 있고(members.level_value에 UNIQUE 제약 없음), 검증은 호스트명
-- 기준으로 찾으므로 A가 확인한 기록을 30분 안에 B가 소진할 수 있다. 두 값이 다를 수 있다.
--
-- 프로브는 아직 등록되지 않은 호스트로도 들어오므로 domain_id는 NULL 가능하며,
-- 감사 목적상 도메인이 지워져도 기록이 남아야 해서 FK를 걸지 않는다
-- (members.origin_domain_id와 같은 논리 보존 방식).

CREATE TABLE IF NOT EXISTS domain_verifications (
    verification_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '검증 ID',

    domain_id BIGINT UNSIGNED NULL COMMENT '변경 대상 도메인 ID (신규 등록 검증은 NULL)',
    host VARCHAR(255) NOT NULL COMMENT '검증 대상 호스트명 (소문자, 포트 포함 가능)',
    previous_host VARCHAR(255) NULL COMMENT '변경 직전 호스트명 (실제 변경 시에만 기록)',
    nonce CHAR(64) NOT NULL COMMENT '루프백 프로브 일회용 nonce',

    status ENUM('pending', 'passed', 'failed', 'consumed') NOT NULL DEFAULT 'pending' COMMENT '검증 상태',
    verdict VARCHAR(32) NULL COMMENT '판정 코드 (reachable, dev_local, dns_missing, unreachable 등)',
    message VARCHAR(255) NULL COMMENT '판정 요약 메시지',
    dns_result JSON NULL COMMENT 'DNS 조회 결과 {a:[], aaaa:[], cname:[]}',
    probe_result JSON NULL COMMENT '루프백 프로브 결과 {url, http_code, ok, error}',

    requested_by BIGINT UNSIGNED NULL COMMENT '검증 요청 관리자 회원 ID',
    consumed_by BIGINT UNSIGNED NULL COMMENT '변경을 실행한 관리자 회원 ID (요청자와 다를 수 있음)',

    expires_at DATETIME NOT NULL COMMENT '검증 유효 만료 시각',
    checked_at DATETIME NULL COMMENT '검증 실행 시각',
    consumed_at DATETIME NULL COMMENT '변경에 사용된 시각',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',

    UNIQUE KEY uk_nonce (nonce),
    INDEX idx_host_status (host, status),
    INDEX idx_domain_id (domain_id),
    INDEX idx_domain_status_consumed (domain_id, status, consumed_at),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도메인 호스트명 검증·변경 기록';
