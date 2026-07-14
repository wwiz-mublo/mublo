-- ====================================
-- Mublo Core - 회원 시스템
-- ====================================
--
-- [Event 발생 시점]
-- Plugin/Package에서 listen할 수 있는 Event 목록
--
-- 1. MemberRegistering (회원가입 전)
--    - 시점: 회원가입 요청 검증 후, DB 저장 전
--    - 용도: 추가 검증, 필드 값 변경, 가입 차단
--
-- 2. MemberRegistered (회원가입 완료)
--    - 시점: members 테이블 INSERT 후
--    - 용도: 환영 이메일, 포인트 지급, 알림 발송
--
-- 3. MemberLoggingIn (로그인 시도 전)
--    - 시점: 비밀번호 검증 전
--    - 용도: IP 차단, 로그인 제한
--
-- 4. MemberLoggedIn (로그인 성공)
--    - 시점: 로그인 성공, last_login_at 업데이트 후
--    - 용도: 로그인 이력 기록, 알림 발송
--
-- 5. MemberLoggedOut (로그아웃)
--    - 시점: 세션 종료 후
--    - 용도: 로그아웃 이력 기록
--
-- 6. MemberUpdating (회원정보 수정 전)
--    - 시점: 수정 요청 검증 후, DB 업데이트 전
--    - 용도: 추가 검증, 변경 이력 기록
--
-- 7. MemberUpdated (회원정보 수정 완료)
--    - 시점: members 테이블 UPDATE 후
--    - 용도: 변경 알림, 이력 기록
--
-- 8. MemberDeleting (회원 탈퇴 전)
--    - 시점: 탈퇴 검증 후, DB 삭제 전
--    - 용도: 탈퇴 방지, 보류 회원 처리
--
-- 9. MemberDeleted (회원 탈퇴 완료)
--    - 시점: members 테이블 DELETE 후
--    - 용도: 연관 데이터 정리, 탈퇴 이력 기록
--
-- ====================================

-- 1. member_levels (회원 레벨 정의) - 전역 테이블
-- Note: 슈퍼관리자만 관리 가능, 모든 도메인이 동일한 레벨 체계 공유
CREATE TABLE IF NOT EXISTS member_levels (
    level_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '레벨 ID',
    -- domain_id 없음: 전역 레벨

    -- 레벨 정보
    level_value TINYINT UNSIGNED NOT NULL UNIQUE COMMENT '레벨 값 (1~255, 숫자가 높을수록 높은 레벨)',
    level_name VARCHAR(50) NOT NULL COMMENT '레벨명 (예: 일반회원, VIP, 관리자)',
    level_type ENUM('SUPER', 'STAFF', 'PARTNER', 'SELLER', 'SUPPLIER', 'BASIC') NOT NULL DEFAULT 'BASIC' COMMENT '레벨 타입',

    -- 역할 구분
    is_super TINYINT(1) NOT NULL DEFAULT 0 COMMENT '최고관리자 여부 (시스템 전체 관리)',
    is_admin TINYINT(1) NOT NULL DEFAULT 0 COMMENT '관리자 모드 접근 권한',
    can_operate_domain TINYINT(1) NOT NULL DEFAULT 0 COMMENT '도메인 소유/운영 가능 여부',

    -- 관리
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_level_type (level_type),
    INDEX idx_is_super (is_super),
    INDEX idx_is_admin (is_admin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='회원 레벨 정의 (전역, 슈퍼관리자만 관리)';

-- 2. members (회원 Core 정보)
CREATE TABLE IF NOT EXISTS members (
    member_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '회원 ID',

    -- 도메인 소속
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '소속 도메인 ID',

    -- 인증 정보
    user_id VARCHAR(50) NOT NULL COMMENT '아이디',
    password VARCHAR(255) NOT NULL COMMENT '비밀번호 (bcrypt)',

    -- 프로필
    nickname VARCHAR(50) NULL COMMENT '닉네임 (표시명)',

    -- 권한
    level_value TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '회원 레벨 값 (member_levels.level_value 참조)',
    domain_group VARCHAR(100) NULL COMMENT '관리 권한 범위 (관리자용, 예: 1/3)',

    -- 최초 가입 도메인 (불변, 유니크 예약 기준 — 이슈 #5-a)
    -- 가입 시 1회만 기록. 사이트 개설로 domain_id가 이동해도 태생 사이트의
    -- (origin, user_id/nickname) 슬롯이 영구 예약되어 재사용·복귀 충돌을 막는다.
    -- FK 미부여: domain_id FK(CASCADE)와 달리 독립해나간 회원의 예약을 논리값으로 보존.
    origin_domain_id BIGINT UNSIGNED NULL COMMENT '최초 가입 도메인 ID (불변, 유니크 예약 기준). 신규 가입부터 기록, legacy는 NULL',

    can_create_site TINYINT(1) NOT NULL DEFAULT 0 COMMENT '사이트 생성 가능 여부 (플랫폼 레벨 권한)',

    -- 잔액
    point_balance INT NOT NULL DEFAULT 0 COMMENT '포인트 잔액 (Balance Manager 스냅샷)',

    -- 상태
    status ENUM('active', 'inactive', 'dormant', 'blocked', 'pending', 'withdrawn') NOT NULL DEFAULT 'active' COMMENT '회원 상태',

    -- 로그인 정보
    last_login_at DATETIME NULL COMMENT '마지막 로그인 시간',
    last_login_ip VARCHAR(45) NULL COMMENT '마지막 로그인 IP (IPv6 지원)',

    -- 관리
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '가입일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',
    withdrawn_at DATETIME NULL DEFAULT NULL COMMENT '탈퇴일',
    withdrawal_reason VARCHAR(500) NULL DEFAULT NULL COMMENT '탈퇴 사유',

    UNIQUE KEY uk_domain_user_id (domain_id, user_id),
    UNIQUE KEY uk_domain_nickname (domain_id, nickname),
    UNIQUE KEY uk_origin_user_id (origin_domain_id, user_id),
    UNIQUE KEY uk_origin_nickname (origin_domain_id, nickname),
    INDEX idx_domain_id (domain_id),
    INDEX idx_level_value (level_value),
    INDEX idx_status (status),
    INDEX idx_domain_group (domain_group),
    INDEX idx_origin_domain_id (origin_domain_id),
    INDEX idx_point_balance (point_balance),
    INDEX idx_withdrawn_at (withdrawn_at),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='회원 Core 정보 (인증/권한)';

-- 3. member_level_denied_menus (레벨별 접근 불가 메뉴 - 네거티브 방식)
-- 등록된 메뉴+액션만 차단, 미등록은 허용
-- is_super=1인 슈퍼관리자는 이 테이블 체크 안 함 (모든 권한)
CREATE TABLE IF NOT EXISTS member_level_denied_menus (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    level_value TINYINT UNSIGNED NOT NULL COMMENT '레벨 값',

    -- 메뉴 코드 (activeCode 그대로 사용)
    -- 예: '003' (Core), '003_001' (Core 서브), 'P_MemberPoint_001' (Plugin)
    menu_code VARCHAR(50) NOT NULL COMMENT '메뉴 코드 (activeCode)',

    -- 차단할 액션들 (콤마 구분)
    -- 예: 'list,write,edit,delete,download' 또는 '*' (전체 차단)
    denied_actions VARCHAR(50) NOT NULL DEFAULT '*' COMMENT '차단 액션 (list,read,write,edit,delete,download 또는 *)',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',

    UNIQUE KEY uk_domain_level_menu (domain_id, level_value, menu_code),
    INDEX idx_domain_level (domain_id, level_value),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='레벨별 접근 불가 메뉴 (네거티브 ACL)';

-- 4. member_fields (도메인별 추가 필드 정의)
CREATE TABLE IF NOT EXISTS member_fields (
    field_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '필드 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    -- 필드 정의
    field_name VARCHAR(50) NOT NULL COMMENT '필드명 (영문, 예: email, phone, address)',
    field_label VARCHAR(100) NOT NULL COMMENT '필드 라벨 (한글, 예: 이메일, 전화번호)',
    field_type ENUM('text', 'email', 'tel', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox', 'address', 'file', 'avatar') NOT NULL COMMENT '필드 타입',
    field_options JSON NULL COMMENT '필드 옵션 (select, radio, checkbox용)',
    field_config JSON NULL COMMENT '필드 설정 (file: max_size, allowed_ext 등)',

    -- 보안
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0 COMMENT '암호화 필드 여부 (email, phone 등)',

    -- 검증
    is_required TINYINT(1) NOT NULL DEFAULT 0 COMMENT '필수 여부',
    is_unique TINYINT(1) NOT NULL DEFAULT 0 COMMENT '중복 불가 여부 (1: 중복 체크 필요)',
    validation_rule VARCHAR(255) NULL COMMENT '검증 규칙 (정규식 등)',

    -- UI 설정
    sort_order INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '표시 순서',
    is_visible_signup TINYINT(1) NOT NULL DEFAULT 1 COMMENT '회원가입 시 표시',
    is_visible_profile TINYINT(1) NOT NULL DEFAULT 1 COMMENT '프로필 수정 시 표시',
    is_visible_list TINYINT(1) NOT NULL DEFAULT 0 COMMENT '회원 목록에 표시',
    is_admin_only TINYINT(1) NOT NULL DEFAULT 0 COMMENT '관리자 전용 필드',
    is_searched TINYINT(1) NOT NULL DEFAULT 0 COMMENT '검색 가능 여부 (1: 검색 필드로 사용)',

    -- 관리
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    UNIQUE KEY uk_domain_field (domain_id, field_name),
    INDEX idx_domain_id (domain_id),
    INDEX idx_sort_order (sort_order),
    INDEX idx_is_encrypted (is_encrypted),
    INDEX idx_is_searched (is_searched),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도메인별 회원 추가 필드 정의';

-- 5. member_field_values (회원별 추가 필드 값)
CREATE TABLE IF NOT EXISTS member_field_values (
    value_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '값 ID',
    member_id BIGINT UNSIGNED NOT NULL COMMENT '회원 ID',
    field_id BIGINT UNSIGNED NOT NULL COMMENT '필드 ID',

    -- 값 (평문 또는 암호화)
    field_value TEXT NULL COMMENT '필드 값 (is_encrypted=1이면 암호화된 값)',

    -- 검색용 Blind Index (암호화+검색 가능 필드용)
    search_index VARCHAR(64) NULL COMMENT 'HMAC 해시 (pepper 기반, 암호화 필드 검색용)',

    -- 관리
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    UNIQUE KEY uk_member_field (member_id, field_id),
    INDEX idx_member_id (member_id),
    INDEX idx_field_id (field_id),
    INDEX idx_search_index (search_index),
    FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (field_id) REFERENCES member_fields(field_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='회원별 추가 필드 값 (암호화 지원)';

-- 6. policies (정책/약관 관리)
CREATE TABLE IF NOT EXISTS policies (
    policy_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '정책 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    -- 정책 식별
    slug VARCHAR(50) NOT NULL COMMENT 'URL 식별자 (예: terms, privacy)',
    policy_type ENUM('terms', 'privacy', 'marketing', 'location', 'custom') NOT NULL COMMENT '정책 유형',

    -- 정책 내용
    title VARCHAR(200) NOT NULL COMMENT '정책 제목',
    content LONGTEXT NOT NULL COMMENT '정책 내용 (HTML)',
    version VARCHAR(20) NOT NULL DEFAULT '1.0' COMMENT '정책 버전',

    -- 동의 설정
    is_required TINYINT(1) NOT NULL DEFAULT 1 COMMENT '필수 동의 여부',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부',
    sort_order INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '표시 순서',

    -- 회원가입 출력
    show_in_register TINYINT(1) NOT NULL DEFAULT 0 COMMENT '회원가입 시 출력 여부',

    -- 관리
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    UNIQUE KEY uk_domain_slug (domain_id, slug),
    INDEX idx_domain_id (domain_id),
    INDEX idx_policy_type (policy_type),
    INDEX idx_is_active (is_active),
    INDEX idx_show_in_register (show_in_register),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='정책/약관 관리';

-- 7. member_policy_agreements (회원 정책 동의 내역)
CREATE TABLE IF NOT EXISTS member_policy_agreements (
    agreement_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '동의 ID',
    member_id BIGINT UNSIGNED NOT NULL COMMENT '회원 ID',
    policy_id BIGINT UNSIGNED NOT NULL COMMENT '정책 ID',

    -- 동의 정보
    policy_version VARCHAR(20) NOT NULL COMMENT '동의한 정책 버전',
    agreed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '동의 일시',
    ip_address VARCHAR(45) NULL COMMENT '동의 시 IP',

    UNIQUE KEY uk_member_policy (member_id, policy_id),
    INDEX idx_member_id (member_id),
    INDEX idx_policy_id (policy_id),
    FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE CASCADE,
    FOREIGN KEY (policy_id) REFERENCES policies(policy_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='회원 정책 동의 내역';

-- 8. balance_logs (포인트 변경 원장 - Balance Manager)
-- Note: INSERT ONLY - UPDATE/DELETE 금지 (감사 추적용 불변 원장)
--
-- [Event 발생 시점]
-- 1. BalanceAdjusting (잔액 변경 전)
--    - 시점: 잔액 변경 요청 검증 후, DB 저장 전
--    - 용도: 추가 검증, 차단 (잔액 부족 등)
--
-- 2. BalanceAdjusted (잔액 변경 완료)
--    - 시점: members.point_balance UPDATE + balance_logs INSERT 후
--    - 용도: 알림, 추가 로깅
--
CREATE TABLE IF NOT EXISTS balance_logs (
    log_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '로그 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    member_id BIGINT UNSIGNED NOT NULL COMMENT '회원 ID',

    -- 변경 정보
    amount INT NOT NULL COMMENT '변경량 (+증가, -차감)',
    balance_before INT NOT NULL COMMENT '변경 전 잔액',
    balance_after INT NOT NULL COMMENT '변경 후 잔액',

    -- 출처 정보 (Plugin/Package 식별)
    source_type ENUM('core', 'plugin', 'package', 'admin', 'system') NOT NULL DEFAULT 'core' COMMENT '출처 타입',
    source_name VARCHAR(50) NOT NULL COMMENT '출처명 (MemberPoint, Shop 등)',

    -- 상세 정보
    action VARCHAR(50) NOT NULL COMMENT '액션 (article_write, purchase, admin_adjust 등)',
    message VARCHAR(255) NOT NULL COMMENT '사용자 친화적 메시지 (맥락 포함)',
    reference_type VARCHAR(50) NULL COMMENT '참조 타입 (article, order, comment 등)',
    reference_id VARCHAR(50) NULL COMMENT '참조 ID',

    -- 메타데이터
    ip_address VARCHAR(45) NULL COMMENT '요청 IP (IPv6 지원)',
    admin_id BIGINT UNSIGNED NULL COMMENT '관리자 조정 시 관리자 ID',
    memo TEXT NULL COMMENT '관리자 메모',

    -- 멱등성 키 (중복 요청 방지)
    idempotency_key VARCHAR(64) NULL COMMENT '멱등성 키 (클라이언트 제공, 중복 요청 방지)',

    -- 타임스탬프
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',

    -- 인덱스
    INDEX idx_member_created (member_id, created_at DESC),
    INDEX idx_domain_member (domain_id, member_id),
    INDEX idx_source (source_type, source_name),
    INDEX idx_reference (reference_type, reference_id),
    INDEX idx_created (created_at),
    UNIQUE INDEX uk_domain_idempotency (domain_id, idempotency_key)

    -- Note: FK 제거 - 회원 삭제 시에도 감사 로그는 보존
    -- 애플리케이션 레벨에서 member_id 유효성 검증
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='포인트 변경 원장 (Immutable Ledger - INSERT ONLY)';

-- ====================================
-- 초기 데이터
-- ====================================
--
-- 초기 데이터는 웹 설치 과정에서 생성됩니다.
-- install/index.php에서 처리
--
-- 설치 시 생성될 기본 구조:
-- 1. 기본 레벨 생성 (전역):
--    level_value=255, level_type=SUPER, is_super=1, is_admin=1, can_operate_domain=1 (최고관리자)
--    level_value=230, level_type=STAFF, is_admin=1 (스태프)
--    level_value=220, level_type=PARTNER, is_admin=1 (파트너)
--    level_value=215, level_type=SELLER, is_admin=1, can_operate_domain=1 (판매자)
--    level_value=210, level_type=SUPPLIER (공급처)
--    level_value=1,   level_type=BASIC (일반회원)
-- 2. 슈퍼관리자 회원 생성 (level_value=255, domain_group='1')
-- 3. 기본 추가 필드 생성 (email, name, phone 등 - 암호화 설정)
-- 4. 기본 약관 생성 (이용약관, 개인정보처리방침)
