-- ====================================
-- Shop Package - 주문 추가 필드
-- ====================================

-- 1. shop_order_fields (필드 정의)
CREATE TABLE IF NOT EXISTS shop_order_fields (
    field_id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id       BIGINT UNSIGNED NOT NULL,
    field_name      VARCHAR(50) NOT NULL COMMENT '영문 식별자',
    field_label     VARCHAR(100) NOT NULL COMMENT '한글 표시명',
    field_type      ENUM('text','email','tel','number','date','textarea',
                         'select','radio','checkbox','address','file') DEFAULT 'text',
    field_options   JSON NULL COMMENT 'select/radio/checkbox 선택지',
    field_config    JSON NULL COMMENT 'file: {max_size, allowed_ext}',
    placeholder     VARCHAR(200) NULL COMMENT '안내 문구',
    is_encrypted    TINYINT(1) DEFAULT 0 COMMENT '암호화 저장 여부',
    is_required     TINYINT(1) DEFAULT 0 COMMENT '필수 입력 여부',
    sort_order      INT UNSIGNED DEFAULT 0 COMMENT '정렬 순서',
    is_active       TINYINT(1) DEFAULT 1 COMMENT '활성 여부',
    is_admin_only   TINYINT(1) DEFAULT 0 COMMENT '관리자 전용 필드',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_domain_field (domain_id, field_name),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='주문 추가 필드 정의';

-- 2. shop_order_field_values (주문별 값)
CREATE TABLE IF NOT EXISTS shop_order_field_values (
    value_id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_no        VARCHAR(20) NOT NULL COMMENT '주문번호',
    field_id        BIGINT UNSIGNED NOT NULL COMMENT '필드 ID',
    field_value     TEXT NULL COMMENT '필드 값 (암호화 또는 평문, file: JSON 메타)',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_order_field (order_no, field_id),
    FOREIGN KEY (order_no) REFERENCES shop_orders(order_no) ON DELETE CASCADE,
    FOREIGN KEY (field_id) REFERENCES shop_order_fields(field_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='주문 추가 필드 값';
