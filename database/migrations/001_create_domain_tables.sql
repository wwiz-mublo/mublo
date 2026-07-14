-- ====================================
-- Mublo Core - 멀티 도메인 시스템
-- ====================================
--
-- [Event 발생 시점]
-- Plugin/Package에서 listen할 수 있는 Event 목록
--
-- 1. DomainConfigUpdating (도메인 설정 변경 전)
--    - 시점: 설정 변경 요청 검증 후, DB 업데이트 전
--    - 용도: 설정 검증, 백업 생성
--
-- 2. DomainConfigUpdated (도메인 설정 변경 완료)
--    - 시점: domain_configs 테이블 UPDATE 후
--    - 용도: 캐시 무효화, 설정 변경 알림
--
-- ====================================

-- 1. domain_configs (도메인 설정 통합)
CREATE TABLE IF NOT EXISTS domain_configs (
    domain_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '도메인 ID',

    -- 도메인 식별
    domain VARCHAR(255) NOT NULL UNIQUE COMMENT '실제 도메인 (예: shop1.com)',

    -- 계층 구조 (분양/대리점 체계)
    domain_group VARCHAR(100) NOT NULL COMMENT '계층 경로 (예: 1, 1/3, 1/3/12)',

    -- 소유자
    member_id BIGINT UNSIGNED NULL COMMENT '도메인 소유자(관리자) 회원ID',

    -- 상태
    status ENUM('active', 'inactive', 'blocked') NOT NULL DEFAULT 'active' COMMENT '도메인 상태',

    -- 용량/제한
    storage_limit BIGINT UNSIGNED DEFAULT 1073741824 COMMENT '저장공간 제한 (bytes, 기본 1GB, 0: 무제한)',
    member_limit INT UNSIGNED DEFAULT 10000 COMMENT '회원수 제한 (0: 무제한)',

    -- 사이트 기본 설정 (JSON)
    site_config JSON NULL COMMENT '사이트 기본 정보 {
        "site_title": "사이트명",
        "site_subtitle": "부제목",
        "admin_email": "admin@example.com",
        "timezone": "Asia/Seoul",
        "language": "ko",
        "editor": "mublo-editor",
        "per_page": 20
    }',

    -- 회사 정보 (JSON)
    company_config JSON NULL COMMENT '회사/사업자 정보 {
        "name": "회사명",
        "owner": "대표자명",
        "tel": "02-1234-5678",
        "fax": "02-1234-5679",
        "email": "info@example.com",
        "business_number": "123-45-67890",
        "tongsin_number": "제2026-서울강남-0001호",
        "zipcode": "06234",
        "address": "서울시 강남구 테헤란로 123",
        "address_detail": "위즈빌딩 5층",
        "privacy_officer": "개인정보 책임자",
        "privacy_email": "privacy@example.com"
    }',

    -- SEO 설정 (JSON) - 로고/파비콘 + SEO + SNS 통합
    seo_config JSON NULL COMMENT '로고 및 SEO 설정 {
        "logo_pc": "/uploads/D1/site/logo_pc.png",
        "logo_mobile": "/uploads/D1/site/logo_mobile.png",
        "favicon": "/uploads/D1/site/favicon.ico",
        "meta_title": "기본 메타 타이틀",
        "meta_description": "기본 메타 설명",
        "meta_keywords": "키워드1, 키워드2",
        "og_image": "/uploads/D1/site/og_image.png",
        "google_analytics": "G-XXXXXXXXXX",
        "google_site_verification": "verification_code",
        "naver_site_verification": "verification_code",
        "sns_channels": [
            {"type": "youtube", "url": "https://youtube.com/@channel"}
        ]
    }',

    -- 스킨/테마 설정 (JSON) - views/Front 디렉토리 구조 기준
    theme_config JSON NULL COMMENT '스킨 설정 (views/Front/{컴포넌트}/{스킨명}/) {
        "admin": "basic",
        "layout": "basic",
        "header": "basic",
        "footer": "basic",
        "index": "basic",
        "board": "basic",
        "member": "basic",
        "auth": "basic"
    }',

    -- 플러그인/패키지 활성화 설정 (JSON)
    extension_config JSON NULL COMMENT '플러그인/패키지 활성화 설정 {
        "plugins": ["Banner", "MemberPoint", "SocialLogin"],
        "packages": ["Shop", "Booking"]
    }',

    -- 확장 설정 (JSON)
    extra_config JSON NULL COMMENT '확장/임시 설정 (여분 필드)',

    -- 생성자 (감사 추적: 실제 생성 작업을 수행한 회원)
    created_by BIGINT UNSIGNED NULL COMMENT '생성 작업자 회원ID (소유자와 다를 수 있음)',

    -- 관리
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_domain_group (domain_group),
    INDEX idx_member_id (member_id),
    INDEX idx_created_by (created_by),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='도메인 설정 통합 테이블 (기본 정보 + 모든 설정)';

-- ====================================
-- 초기 데이터
-- ====================================
--
-- 초기 데이터는 웹 설치 과정에서 생성됩니다.
-- install/index.php에서 처리
--
-- 설치 시 생성될 기본 구조:
-- - domain_id = 1, domain_group = '1' (최상위 도메인)
-- - 설치 시 입력받은 정보로 각종 config JSON 생성
-- - 민감 정보(smtp_password, api_key 등)는 암호화하여 저장
