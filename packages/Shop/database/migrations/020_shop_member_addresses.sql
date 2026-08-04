-- 회원 배송지 주소록
CREATE TABLE IF NOT EXISTS shop_member_addresses (
    address_id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    member_id        BIGINT UNSIGNED NOT NULL,
    domain_id        BIGINT UNSIGNED NOT NULL,

    address_name     VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '배송지명 (자택, 직장 등)',
    -- PII는 shop_orders와 동일하게 AES-256-GCM 암호문(base64)으로 저장 — TEXT 사용
    recipient_name   TEXT NOT NULL COMMENT '수령인 (암호화)',
    recipient_phone  TEXT NOT NULL COMMENT '연락처 (암호화)',
    zip_code         TEXT NOT NULL COMMENT '우편번호 (암호화)',
    address1         TEXT NOT NULL COMMENT '기본주소 (암호화)',
    address2         TEXT NOT NULL COMMENT '상세주소 (암호화)',

    is_default       TINYINT(1) NOT NULL DEFAULT 0 COMMENT '기본 배송지 여부',
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_member (member_id, domain_id),
    INDEX idx_default (member_id, is_default),
    FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='회원 배송지 주소록';
