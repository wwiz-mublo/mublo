-- ====================================
-- Shop Package - 옵션 프리셋
-- ====================================
--
-- 설계 철학:
-- 1. 프리셋은 "템플릿" 역할 — 상품과 FK로 연결되지 않음
-- 2. 상품 등록 시 프리셋을 선택하면 옵션/값이 상품으로 "복사"됨
-- 3. 복사 후 상품 옵션은 독립적 — 프리셋 수정/삭제가 기존 상품에 영향 없음
-- 4. 프리셋 없이 상품에서 직접 옵션 생성도 가능
--

-- 1. shop_option_presets (옵션 프리셋 그룹)
CREATE TABLE IF NOT EXISTS shop_option_presets (
    preset_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '프리셋 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    name VARCHAR(100) NOT NULL COMMENT '프리셋명 (예: 의류 옵션, 신발 옵션)',
    description VARCHAR(255) NULL COMMENT '설명',
    option_mode ENUM('SINGLE', 'COMBINATION') NOT NULL DEFAULT 'SINGLE' COMMENT '옵션 모드 (단독형/조합형)',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_domain (domain_id),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='옵션 프리셋';

-- 2. shop_option_preset_options (프리셋 내 옵션 정의)
CREATE TABLE IF NOT EXISTS shop_option_preset_options (
    preset_option_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '프리셋 옵션 ID',
    preset_id BIGINT UNSIGNED NOT NULL COMMENT '프리셋 ID',

    option_name VARCHAR(50) NOT NULL COMMENT '옵션명 (색상, 사이즈 등)',
    option_type ENUM('BASIC', 'EXTRA') NOT NULL DEFAULT 'BASIC' COMMENT '옵션 유형 (기본/추가)',
    is_required TINYINT(1) NOT NULL DEFAULT 1 COMMENT '필수 여부',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '정렬 순서',

    INDEX idx_preset (preset_id),
    FOREIGN KEY (preset_id) REFERENCES shop_option_presets(preset_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='프리셋 옵션';

-- 3. shop_option_preset_values (프리셋 옵션 값)
CREATE TABLE IF NOT EXISTS shop_option_preset_values (
    preset_value_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '프리셋 값 ID',
    preset_option_id BIGINT UNSIGNED NOT NULL COMMENT '프리셋 옵션 ID',

    value_name VARCHAR(50) NOT NULL COMMENT '값 이름 (빨강, XL 등)',
    extra_price INT NOT NULL DEFAULT 0 COMMENT '기본 추가 금액',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '정렬 순서',

    INDEX idx_option (preset_option_id),
    FOREIGN KEY (preset_option_id) REFERENCES shop_option_preset_options(preset_option_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='프리셋 옵션 값';
