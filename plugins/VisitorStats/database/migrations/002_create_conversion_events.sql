-- 이벤트 기반 전환 기록
--
-- 기존 전환 화면은 AutoForm 의 form_submissions 를 읽는다. 그건 "폼 접수"라는
-- 한 종류의 전환일 뿐이고, 주문·상담·가입 같은 전환은 담을 곳이 없었다.
-- ConversionRecordedEvent(src/Contract/Tracking/) 를 발행하는 확장이면 무엇이든
-- 여기에 쌓인다 — VisitorStats 는 발행자가 누구인지 알지 못한다.

CREATE TABLE IF NOT EXISTS `plugin_visitor_conversions` (
    `conversion_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    -- 발행 확장이 정한 전환 종류. ConversionSourceTypes 상수(rental_order 등)를 권장하되,
    -- 코어가 목록을 강제하지 않으므로 임의 문자열도 보관한다.
    `source_type`   VARCHAR(50) NOT NULL,
    -- 발행 확장 안에서 그 전환을 가리키는 식별자(주문번호 등). 멱등키의 절반이다.
    `source_id`     VARCHAR(100) NOT NULL,
    `source_label`  VARCHAR(100) NULL DEFAULT NULL,
    `campaign_key`  VARCHAR(100) NULL DEFAULT NULL,
    `status`        VARCHAR(20) NOT NULL DEFAULT 'success',
    `member_id`     INT UNSIGNED NULL DEFAULT NULL,
    `value_amount`  DECIMAL(15,2) NULL DEFAULT NULL,
    `currency`      VARCHAR(10) NOT NULL DEFAULT 'KRW',
    `occurred_at`   DATETIME NOT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`conversion_id`),
    -- 같은 사건이 두 번 발행돼도(재시도·웹훅 중복) 한 건으로 수렴한다.
    UNIQUE KEY `uk_domain_source` (`domain_id`, `source_type`, `source_id`),
    KEY `idx_domain_occurred` (`domain_id`, `occurred_at`),
    KEY `idx_domain_campaign` (`domain_id`, `campaign_key`, `occurred_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
