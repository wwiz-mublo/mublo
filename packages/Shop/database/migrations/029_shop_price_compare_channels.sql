-- ====================================
-- Shop Package - 가격비교 채널
-- ====================================
--
-- 피드는 전 상품의 이름·가격·링크를 기계가 읽을 수 있는 형태로 한 번에 내주는 공개
-- 엔드포인트다. 그래서 켜는 것은 운영자의 의사결정이어야 하고, 설치만으로 열리지
-- 않는다(is_active 기본 0).
--
-- 채널당 한 행을 둔다. 설정을 쇼핑몰 설정 테이블의 컬럼 하나에 몰아넣지 않는 이유는,
-- 채널마다 필요한 값이 다르고 나중에 카테고리 대응표나 상품별 예외가 붙을 때 그것들이
-- 매달릴 부모가 필요하기 때문이다. 행이 없으면 꺼진 것으로 본다.
--
-- channel_code 는 값이다. 채널이 늘어도 스키마가 움직이지 않는다.

CREATE TABLE IF NOT EXISTS shop_price_compare_channels (
    channel_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '채널 설정 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    channel_code VARCHAR(50) NOT NULL COMMENT '채널 코드 (ContractRegistry 등록 키)',

    is_active TINYINT(1) NOT NULL DEFAULT 0 COMMENT '피드 사용 여부',
    settings TEXT NULL DEFAULT NULL COMMENT '채널별 설정 (JSON)',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    UNIQUE KEY uk_domain_channel (domain_id, channel_code),
    INDEX idx_domain (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='가격비교 채널 설정';
