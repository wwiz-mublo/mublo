-- ====================================
-- Innopay Plugin - 설정 / 결제 시도 / 로그
-- ====================================

-- 도메인별 연동 설정 (Merchant-Key·취소 비밀번호는 암호화 저장)
CREATE TABLE IF NOT EXISTS plugin_innopay_configs (
    config_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id   BIGINT UNSIGNED NOT NULL,
    config_data JSON NOT NULL,
    created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_domain (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='이노페이 연동 설정';

-- 결제 시도 — 주문번호(moid) 한 건의 진행 상태.
-- 이노페이 returnUrl 은 100자 제한이라 복귀 경로·소비 패키지 키를 URL 에 싣지 못한다.
-- 결제 준비 때 여기에 적어 두고, 복귀 시 moid 로 찾아 쓴다(열린 리다이렉트도 막힌다).
CREATE TABLE IF NOT EXISTS plugin_innopay_attempts (
    attempt_id     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id      BIGINT UNSIGNED NOT NULL,
    moid           VARCHAR(40) NOT NULL COMMENT '주문번호 (이노페이 moid)',
    consumer       VARCHAR(30) NOT NULL DEFAULT '' COMMENT '결제 결과를 돌려줄 소비 패키지 키',
    success_url    VARCHAR(500) NOT NULL DEFAULT '' COMMENT '성공 시 이동 경로 (소비처 제공)',
    fail_url       VARCHAR(500) NOT NULL DEFAULT '' COMMENT '실패 시 이동 경로 (소비처 제공)',
    pay_method     VARCHAR(10) NOT NULL DEFAULT '' COMMENT '요청 결제수단',
    amount         INT NOT NULL DEFAULT 0 COMMENT '결제 요청 금액 (amt + taxFreeAmt)',
    status         VARCHAR(20) NOT NULL DEFAULT 'READY'
                   COMMENT 'READY/APPROVING/APPROVED/COMPLETED/FAILED/CANCELLED/NEEDS_ACTION',
    tid            VARCHAR(40) NULL COMMENT '이노페이 거래번호',
    result_code    VARCHAR(20) NULL COMMENT '마지막 결과 코드',
    result_msg     VARCHAR(255) NULL COMMENT '마지막 결과 메시지',
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_domain_moid (domain_id, moid),
    INDEX idx_tid (tid),
    INDEX idx_status (domain_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='이노페이 결제 시도';

-- 단계별 로그 — 결제창 호출부터 승인·검증·취소까지 한 줄씩 남긴다.
-- 비밀값(Merchant-Key, Payment-Token, 취소 비밀번호)은 마스킹 후 저장한다.
CREATE TABLE IF NOT EXISTS plugin_innopay_logs (
    log_id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id    BIGINT UNSIGNED NOT NULL,
    moid         VARCHAR(40) NOT NULL DEFAULT '' COMMENT '주문번호',
    tid          VARCHAR(40) NOT NULL DEFAULT '' COMMENT '거래번호',
    step         VARCHAR(30) NOT NULL COMMENT '단계 (PREPARE, RETURN, APPROVE_REQ ...)',
    level        VARCHAR(10) NOT NULL DEFAULT 'info' COMMENT 'info / warning / error',
    amount       INT NULL COMMENT '관련 금액',
    result_code  VARCHAR(20) NULL,
    result_msg   VARCHAR(255) NULL,
    http_status  SMALLINT NULL,
    duration_ms  INT NULL,
    request      MEDIUMTEXT NULL COMMENT '요청 (JSON, 마스킹)',
    response     MEDIUMTEXT NULL COMMENT '응답 (JSON)',
    ip           VARCHAR(45) NULL,
    user_agent   VARCHAR(255) NULL,
    created_at   DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    INDEX idx_domain_created (domain_id, created_at),
    INDEX idx_moid (domain_id, moid),
    INDEX idx_tid (tid),
    INDEX idx_step (domain_id, step)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='이노페이 결제 로그';
