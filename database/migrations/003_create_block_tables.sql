-- ====================================
-- Mublo Core - 블록 시스템
-- ====================================
--
-- 블록 시스템은 두 가지 용도로 사용됩니다:
-- 1. 기존 화면의 특정 위치에 블록 출력 (position 기반)
-- 2. 블록을 조합하여 새로운 페이지 생성 (page_id 기반)
--
-- 테이블 구조:
-- block_pages  → 페이지 정의 (회사소개, 이벤트 등)
-- block_rows   → 한 줄 (row)
-- block_columns → 칸 (column, 최대 4개/줄)
--
-- [Event 발생 시점]
-- 1. BlockRendering (블록 렌더링 전)
--    - 시점: 블록 데이터 로드 후, HTML 렌더링 전
--    - 용도: 블록 데이터 수정, 추가 컬럼 삽입
--
-- 2. BlockRendered (블록 렌더링 완료)
--    - 시점: HTML 생성 후, 출력 전
--    - 용도: HTML 후처리, 스크립트 삽입
--
-- 3. PageViewed (페이지 조회)
--    - 시점: block_pages 조회 시
--    - 용도: 조회 통계, 접근 로그
--
-- ====================================

-- ====================================
-- 1. block_pages (페이지 정의)
-- ====================================
CREATE TABLE IF NOT EXISTS block_pages (
    page_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '페이지 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    -- 페이지 정보
    page_code VARCHAR(50) NOT NULL COMMENT '페이지 코드 (URL 슬러그: about, contact 등)',
    page_title VARCHAR(100) NOT NULL COMMENT '페이지 제목',
    page_description TEXT NULL COMMENT '페이지 설명',

    -- SEO
    seo_title VARCHAR(255) NULL COMMENT 'SEO 타이틀',
    seo_description VARCHAR(500) NULL COMMENT 'SEO 설명',
    seo_keywords VARCHAR(255) NULL COMMENT 'SEO 키워드',

    -- 레이아웃
    layout_type TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '레이아웃 타입 (1:전체, 2:좌측, 3:우측, 4:3단)',
    use_fullpage TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '넓이 타입 (0:최대넓이, 1:와이드, 2:사용자지정)',
    custom_width SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '사용자 지정 넓이 (px, use_fullpage=2일 때)',
    sidebar_left_width SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '좌측 사이드바 넓이 (0=사이트 기본값)',
    sidebar_left_mobile TINYINT(1) NOT NULL DEFAULT 0 COMMENT '좌측 사이드바 모바일 출력',
    sidebar_right_width SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '우측 사이드바 넓이 (0=사이트 기본값)',
    sidebar_right_mobile TINYINT(1) NOT NULL DEFAULT 0 COMMENT '우측 사이드바 모바일 출력',
    use_header TINYINT(1) NOT NULL DEFAULT 1 COMMENT '헤더 사용 여부',
    use_footer TINYINT(1) NOT NULL DEFAULT 1 COMMENT '푸터 사용 여부',

    -- 권한
    allow_level TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '접근 가능 레벨 (0: 모두)',
    page_config JSON NULL COMMENT '페이지 확장 설정 (JSON)',

    -- 관리
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부',
    is_deleted TINYINT(1) NOT NULL DEFAULT 0 COMMENT '삭제 여부 (0=정상, 1=삭제)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    UNIQUE KEY uk_domain_code (domain_id, page_code),
    INDEX idx_domain_id (domain_id),
    INDEX idx_is_active (is_active),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='블록 페이지 정의';

-- ====================================
-- 2. block_rows (한 줄)
-- ====================================
CREATE TABLE IF NOT EXISTS block_rows (
    row_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '행 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    -- 연결 (둘 중 하나 사용)
    page_id BIGINT UNSIGNED NULL COMMENT '페이지 ID (페이지용일 때)',
    position VARCHAR(20) NULL COMMENT '출력 위치 (위치 기반일 때: index, header, footer, left, right)',
    position_menu VARCHAR(50) NULL COMMENT '특정 메뉴에서만 출력 (메뉴 코드)',

    -- 기본 정보
    section_id VARCHAR(50) NULL COMMENT '섹션 ID (식별용)',
    admin_title VARCHAR(100) NULL COMMENT '관리용 제목',

    -- 레이아웃
    width_type TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '넓이 타입 (0: 와이드/전체, 1: 최대넓이)',
    column_count TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '칸 수 (1~4)',
    column_margin INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '칸 간격 (px)',
    column_width_unit TINYINT NOT NULL DEFAULT 1 COMMENT '칸 너비 단위 (1: %, 2: px)',

    -- 높이/여백 (외부여백 margin = 배경 밖 행 간 투명 간격 / 내부여백 padding = 배경 밴드 안쪽)
    pc_height VARCHAR(20) NULL COMMENT 'PC 높이 (px, vh)',
    mobile_height VARCHAR(20) NULL COMMENT 'Mobile 높이 (px, vh)',
    pc_margin VARCHAR(50) NULL COMMENT 'PC 외부여백 (예: 40px 0)',
    mobile_margin VARCHAR(50) NULL COMMENT 'Mobile 외부여백',
    pc_padding VARCHAR(50) NULL COMMENT 'PC 내부여백 (예: 25px 10px 20px 25px)',
    mobile_padding VARCHAR(50) NULL COMMENT 'Mobile 내부여백',

    -- 배경 (JSON)
    background_config JSON NULL COMMENT '배경 설정 {
        "color": "#f5f5f5",
        "image": "/uploads/bg.jpg",
        "position": "center center",
        "size": "cover",
        "repeat": "no-repeat",
        "attachment": "fixed",
        "opacity": 0.8
    }',

    -- 관리
    sort_order INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '정렬 순서',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_domain_id (domain_id),
    INDEX idx_page_id (page_id),
    INDEX idx_position (position),
    INDEX idx_position_menu (position_menu),
    INDEX idx_sort_order (sort_order),
    INDEX idx_is_active (is_active),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
    FOREIGN KEY (page_id) REFERENCES block_pages(page_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='블록 행 (한 줄)';

-- ====================================
-- 3. block_columns (칸)
-- ====================================
CREATE TABLE IF NOT EXISTS block_columns (
    column_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '칸 ID',
    row_id BIGINT UNSIGNED NOT NULL COMMENT '행 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    -- 칸 정보
    column_index TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '칸 순서 (0, 1, 2, 3)',
    width VARCHAR(20) NULL COMMENT '칸 너비 (25%, 50%, 100% 등)',

    -- 여백
    pc_padding VARCHAR(50) NULL COMMENT 'PC 내부여백',
    mobile_padding VARCHAR(50) NULL COMMENT 'Mobile 내부여백',

    -- 배경 (JSON)
    background_config JSON NULL COMMENT '배경 설정 {
        "color": "#ffffff",
        "image": "/uploads/col-bg.jpg",
        "position": "center",
        "size": "cover",
        "repeat": "no-repeat",
        "attachment": "scroll",
        "opacity": 1
    }',

    -- 테두리 (JSON)
    border_config JSON NULL COMMENT '테두리 설정 {
        "width": "1px",
        "color": "#e5e5e5",
        "radius": "8px",
        "style": "solid"
    }',

    -- 제목/문구 (JSON)
    title_config JSON NULL COMMENT '제목 설정 {
        "show": true,
        "text": "최신 게시글",
        "color": "#000000",
        "pc_size": "16",
        "mobile_size": "14",
        "position": "left",
        "pc_image": "/uploads/title-pc.jpg",
        "mobile_image": "/uploads/title-mo.jpg",
        "more_link": true,
        "more_url": "/board/notice",
        "copytext": "새로운 소식을 확인하세요",
        "copytext_color": "#666666",
        "copytext_pc_size": "14",
        "copytext_mobile_size": "12",
        "copytext_position": "bottom"
    }',

    -- 콘텐츠
    content_type VARCHAR(50) NULL COMMENT '콘텐츠 타입 (board, banner, product, html 등)',
    content_kind VARCHAR(20) NOT NULL DEFAULT 'CORE' COMMENT '콘텐츠 종류 (CORE, PLUGIN, PACKAGE)',
    content_skin VARCHAR(50) NULL COMMENT '스킨',
    content_config JSON NULL COMMENT '콘텐츠 설정 {
        "pc_count": 5, "mo_count": 4,
        "pc_style": "list", "mo_style": "list",
        "pc_cols": 4, "mo_cols": 2,
        "aos": "fade-up",
        "include_path": "...", "html": "...",
        "video_type": "youtube", "video_url": "...", "autoplay": true, "muted": true
    }',
    content_items JSON NULL COMMENT '출력 데이터 (게시판ID[], 이미지[{pc_image,mo_image,link_url,link_win}])',

    -- 관리
    sort_order INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '정렬 순서',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_row_id (row_id),
    INDEX idx_domain_id (domain_id),
    INDEX idx_column_index (column_index),
    INDEX idx_content_type (content_type),
    INDEX idx_sort_order (sort_order),
    INDEX idx_is_active (is_active),
    FOREIGN KEY (row_id) REFERENCES block_rows(row_id) ON DELETE CASCADE,
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='블록 칸 (컬럼)';

-- ====================================
-- 초기 데이터
-- ====================================
--
-- 초기 데이터는 웹 설치 과정에서 생성됩니다.
-- install/index.php에서 처리
--
-- 설치 시 생성될 기본 구조:
-- 1. 기본 페이지 생성 (홈, 소개, 이용약관, 개인정보처리방침)
-- 2. 메인 페이지용 기본 블록 행 생성
--
