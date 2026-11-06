-- ====================================
-- Innopay Plugin - 취소 기록
-- ====================================
-- 이노페이는 취소가 일어나면 결제결과 통보(Noti)로 다시 알려 준다. 그 취소가
--   (1) 우리가 API 로 요청한 것(관리자 환불·자동 취소)이면 소비 패키지에 이미 반영됐으니 건너뛰고
--   (2) 이노페이 상점관리 등 외부에서 일어난 것이면 소비 패키지에 외부 환불로 기록해야 한다.
-- 소비 패키지마다 중복 판정 방식이 달라(거래번호 / 남은 금액) 플러그인이 직접 구분한다.
CREATE TABLE IF NOT EXISTS plugin_innopay_cancels (
    cancel_id     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id     BIGINT UNSIGNED NOT NULL,
    moid          VARCHAR(40) NOT NULL DEFAULT '',
    tid           VARCHAR(40) NOT NULL COMMENT '원거래 거래번호',
    amount        INT NOT NULL DEFAULT 0 COMMENT '취소 금액',
    source        VARCHAR(10) NOT NULL COMMENT 'API(우리가 요청) / NOTI(외부 취소 통보)',
    cancel_tid    VARCHAR(40) NULL COMMENT '취소 거래번호 (API 응답 pgCancelTid)',
    noti_key      VARCHAR(100) NULL COMMENT '외부 취소 통보 중복 방지 키',
    noti_matched  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'API 취소에 대응하는 통보를 받았는가',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_noti_key (domain_id, noti_key),
    INDEX idx_tid (domain_id, tid, source, noti_matched)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='이노페이 취소 기록';
