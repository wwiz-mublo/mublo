-- ====================================
-- Mublo Core - 게시판 시스템
-- ====================================
--
-- [Event 발생 시점]
-- Plugin/Package에서 listen할 수 있는 Event 목록
--
-- 1. ArticleCreating (게시글 작성 전)
--    - 시점: 작성 요청 검증 후, DB 저장 전
--    - 용도: 스팸 필터링, 내용 검증, 자동 태그 추가
--
-- 2. ArticleCreated (게시글 작성 완료)
--    - 시점: board_articles 테이블 INSERT 후
--    - 용도: 포인트 지급, 알림 발송, 검색 인덱싱
--
-- 3. ArticleUpdating (게시글 수정 전)
--    - 시점: 수정 요청 검증 후, DB 업데이트 전
--    - 용도: 수정 권한 검증, 이력 백업
--
-- 4. ArticleUpdated (게시글 수정 완료)
--    - 시점: board_articles 테이블 UPDATE 후
--    - 용도: 수정 이력 기록, 검색 재인덱싱
--
-- 5. ArticleDeleting (게시글 삭제 전)
--    - 시점: 삭제 검증 후, DB 삭제/상태변경 전
--    - 용도: 삭제 권한 검증, 백업 생성
--
-- 6. ArticleDeleted (게시글 삭제 완료)
--    - 시점: board_articles 상태 변경/DELETE 후
--    - 용도: 포인트 회수, 관련 데이터 정리
--
-- 7. ArticleViewed (게시글 조회)
--    - 시점: 게시글 조회 시 (view_count 증가 전)
--    - 용도: 조회 이력 기록, 추천 알고리즘
--
-- 8. CommentCreating (댓글 작성 전)
--    - 시점: 작성 요청 검증 후, DB 저장 전
--    - 용도: 스팸 필터링, 내용 검증
--
-- 9. CommentCreated (댓글 작성 완료)
--    - 시점: board_comments 테이블 INSERT 후
--    - 용도: 포인트 지급, 작성자에게 알림
--
-- 10. CommentUpdating (댓글 수정 전)
--     - 시점: 수정 요청 검증 후, DB 업데이트 전
--     - 용도: 수정 권한 검증
--
-- 11. CommentUpdated (댓글 수정 완료)
--     - 시점: board_comments 테이블 UPDATE 후
--     - 용도: 수정 이력 기록
--
-- 12. CommentDeleting (댓글 삭제 전)
--     - 시점: 삭제 검증 후, DB 삭제/상태변경 전
--     - 용도: 삭제 권한 검증
--
-- 13. CommentDeleted (댓글 삭제 완료)
--     - 시점: board_comments 상태 변경/DELETE 후
--     - 용도: 포인트 회수
--
-- 14. ReactionAdded (반응 추가)
--     - 시점: board_reactions 테이블 INSERT 후
--     - 용도: 포인트 지급, 알림 발송
--
-- 15. ReactionRemoved (반응 제거)
--     - 시점: board_reactions 테이블 DELETE 후
--     - 용도: 포인트 회수
--
-- 16. FileUploaded (파일 업로드 완료)
--     - 시점: board_attachments 테이블 INSERT 후
--     - 용도: 용량 계산, 바이러스 스캔
--
-- 17. FileDownloaded (파일 다운로드)
--     - 시점: 파일 다운로드 시 (download_count 증가 전)
--     - 용도: 포인트 차감, 다운로드 이력 기록
--
-- ====================================

-- 1. board_groups (게시판 그룹)
CREATE TABLE IF NOT EXISTS board_groups (
    group_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '그룹 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    -- 그룹 정보
    group_slug VARCHAR(50) NOT NULL COMMENT '그룹 슬러그 (영문)',
    group_name VARCHAR(100) NOT NULL COMMENT '그룹명',
    group_description VARCHAR(255) NULL COMMENT '그룹 설명',

    -- 권한 (그룹 단위)
    list_level TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '목록 보기 레벨',
    read_level TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '글 읽기 레벨',
    write_level TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '글쓰기 레벨',
    comment_level TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '댓글 쓰기 레벨',
    download_level TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '다운로드 레벨',

    -- 관리
    sort_order INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '정렬 순서',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    UNIQUE KEY uk_domain_slug (domain_id, group_slug),
    INDEX idx_domain_id (domain_id),
    INDEX idx_sort_order (sort_order),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='게시판 그룹';

-- 2. board_configs (게시판 설정)
CREATE TABLE IF NOT EXISTS board_configs (
    board_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '게시판 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    group_id BIGINT UNSIGNED NOT NULL COMMENT '그룹 ID',

    -- 게시판 정보
    board_slug VARCHAR(50) NOT NULL COMMENT '게시판 슬러그 (영문)',
    board_name VARCHAR(100) NOT NULL COMMENT '게시판명',
    board_description TEXT NULL COMMENT '게시판 설명',

    -- 권한 (게시판 단위, 그룹보다 우선)
    list_level TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '목록 보기 레벨',
    read_level TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '글 읽기 레벨',
    write_level TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '글쓰기 레벨',
    comment_level TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '댓글 쓰기 레벨',
    download_level TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '다운로드 레벨',

    -- 레벨별 1일 작성 제한 (JSON: {"level_value": limit, ...}, NULL=전체 무제한)
    daily_write_limit JSON NULL DEFAULT NULL COMMENT '레벨별 1일 글쓰기 제한 JSON',
    daily_comment_limit JSON NULL DEFAULT NULL COMMENT '레벨별 1일 댓글 제한 JSON',

    -- UI 설정
    board_skin VARCHAR(50) NOT NULL DEFAULT 'basic' COMMENT '게시판 스킨',
    board_editor VARCHAR(50) NOT NULL DEFAULT 'mublo-editor' COMMENT '에디터',

    -- 목록 설정
    notice_count TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '공지글 상단 고정 개수',
    per_page TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '페이지당 글 수 (0: domain 기본값 사용)',

    -- 기능 사용 여부
    use_secret TINYINT(1) NOT NULL DEFAULT 0 COMMENT '비밀글 기능 사용',
    is_secret_board TINYINT(1) NOT NULL DEFAULT 0 COMMENT '비밀게시판 여부 (전체 글 비밀글 강제)',
    use_category TINYINT(1) NOT NULL DEFAULT 0 COMMENT '카테고리 사용',
    use_comment TINYINT(1) NOT NULL DEFAULT 1 COMMENT '댓글 사용',
    use_reaction TINYINT(1) NOT NULL DEFAULT 1 COMMENT '반응(좋아요 등) 사용',
    use_link TINYINT(1) NOT NULL DEFAULT 0 COMMENT '링크 사용',
    use_file TINYINT(1) NOT NULL DEFAULT 1 COMMENT '파일 첨부 사용',

    -- 반응(Reaction) 설정
    reaction_config JSON NULL COMMENT '반응 아이콘/명칭 설정 ({"like":{"label":"좋아요","icon":"👍","color":"#3B82F6","enabled":true}})',

    -- 파일 설정
    file_count_limit INT UNSIGNED NOT NULL DEFAULT 2 COMMENT '파일 첨부 개수 제한',
    file_size_limit BIGINT UNSIGNED NOT NULL DEFAULT 2097152 COMMENT '파일 크기 제한 (bytes, 기본 2MB)',
    file_extension_allowed VARCHAR(255) NOT NULL DEFAULT 'jpg,jpeg,png,gif,pdf,zip' COMMENT '허용 확장자',

    -- 테이블 분리 설정
    use_separate_table TINYINT(1) NOT NULL DEFAULT 0 COMMENT '별도 테이블 사용 여부',
    table_name VARCHAR(100) NULL COMMENT '분리된 테이블명 (예: board_articles_notice)',

    -- 관리
    sort_order INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '정렬 순서',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부',
    is_global TINYINT(1) NOT NULL DEFAULT 0 COMMENT '전체 도메인 공통 게시판 (Super 전용)',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    UNIQUE KEY uk_domain_slug (domain_id, board_slug),
    INDEX idx_domain_id (domain_id),
    INDEX idx_group_id (group_id),
    INDEX idx_sort_order (sort_order),
    INDEX idx_is_global (is_global),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
    FOREIGN KEY (group_id) REFERENCES board_groups(group_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='게시판 설정';

-- 3. board_categories (독립 카테고리)
CREATE TABLE IF NOT EXISTS board_categories (
    category_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '카테고리 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    -- 카테고리 정보
    category_name VARCHAR(50) NOT NULL COMMENT '카테고리명',
    category_slug VARCHAR(50) NOT NULL COMMENT '카테고리 슬러그',
    category_description VARCHAR(255) NULL COMMENT '카테고리 설명',

    -- 관리
    sort_order INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '정렬 순서',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    UNIQUE KEY uk_domain_slug (domain_id, category_slug),
    INDEX idx_domain_id (domain_id),
    INDEX idx_sort_order (sort_order),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='독립 카테고리 (여러 게시판에서 재사용 가능)';

-- 4. board_category_mapping (게시판-카테고리 매핑)
CREATE TABLE IF NOT EXISTS board_category_mapping (
    mapping_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '매핑 ID',
    board_id BIGINT UNSIGNED NOT NULL COMMENT '게시판 ID',
    category_id BIGINT UNSIGNED NOT NULL COMMENT '카테고리 ID',

    -- 권한 오버라이드 (NULL: 게시판 설정 따름)
    list_level TINYINT UNSIGNED NULL COMMENT '목록 보기 레벨',
    read_level TINYINT UNSIGNED NULL COMMENT '글 읽기 레벨',
    write_level TINYINT UNSIGNED NULL COMMENT '글쓰기 레벨',
    comment_level TINYINT UNSIGNED NULL COMMENT '댓글 쓰기 레벨',
    download_level TINYINT UNSIGNED NULL COMMENT '다운로드 레벨',

    -- 관리
    sort_order INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '정렬 순서',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',

    UNIQUE KEY uk_board_category (board_id, category_id),
    INDEX idx_board_id (board_id),
    INDEX idx_category_id (category_id),
    INDEX idx_sort_order (sort_order),
    FOREIGN KEY (board_id) REFERENCES board_configs(board_id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES board_categories(category_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='게시판-카테고리 매핑 (다대다 관계)';

-- 5. board_articles (게시글)
CREATE TABLE IF NOT EXISTS board_articles (
    article_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '게시글 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    board_id BIGINT UNSIGNED NOT NULL COMMENT '게시판 ID',
    category_id BIGINT UNSIGNED NULL COMMENT '카테고리 ID',

    -- 작성자 (회원/비회원)
    member_id BIGINT UNSIGNED NULL COMMENT '회원 ID (NULL: 비회원)',
    author_name VARCHAR(50) NULL COMMENT '작성자 이름 (회원: 닉네임, 비회원: 입력값)',
    author_password VARCHAR(255) NULL COMMENT '비회원 비밀번호 (bcrypt, 회원: NULL)',

    -- 게시글 정보
    title VARCHAR(255) NOT NULL COMMENT '제목',
    slug VARCHAR(100) NULL COMMENT '슬러그 (SEO용)',
    content LONGTEXT NOT NULL COMMENT '내용',
    thumbnail VARCHAR(500) NULL COMMENT '썸네일 이미지 경로 (본문 첫 이미지)',

    -- 상태
    is_notice TINYINT(1) NOT NULL DEFAULT 0 COMMENT '공지사항 여부',
    is_secret TINYINT(1) NOT NULL DEFAULT 0 COMMENT '비밀글 여부',
    status ENUM('published', 'draft', 'deleted') NOT NULL DEFAULT 'published' COMMENT '상태',

    -- 권한 (개별 글)
    read_level TINYINT UNSIGNED NULL COMMENT '읽기 레벨 (NULL: 게시판 설정 따름)',
    download_level TINYINT UNSIGNED NULL COMMENT '다운로드 레벨',

    -- 통계
    view_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '조회수',
    comment_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '댓글수',
    reaction_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '총 반응수',

    -- 위치 정보 (선택)
    location_lat DECIMAL(10, 8) NULL COMMENT '위도',
    location_lng DECIMAL(11, 8) NULL COMMENT '경도',

    -- IP
    ip_address VARCHAR(45) NULL COMMENT '작성자 IP (IPv6 지원)',

    -- 관리
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '작성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',
    published_at DATETIME NULL COMMENT '발행일 (예약 발행용)',

    INDEX idx_domain_board (domain_id, board_id),
    INDEX idx_board_category (board_id, category_id),
    INDEX idx_member_id (member_id),
    INDEX idx_status (status),
    INDEX idx_is_notice (is_notice),
    INDEX idx_created_at (created_at),
    INDEX idx_slug (slug),
    INDEX idx_location (location_lat, location_lng),
    INDEX idx_board_list (board_id, status, is_notice, created_at), -- 목록 조회 최적화
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
    FOREIGN KEY (board_id) REFERENCES board_configs(board_id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES board_categories(category_id) ON DELETE SET NULL,
    FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='게시글';

-- 6. board_comments (댓글)
CREATE TABLE IF NOT EXISTS board_comments (
    comment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '댓글 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    board_id BIGINT UNSIGNED NOT NULL COMMENT '게시판 ID',
    article_id BIGINT UNSIGNED NOT NULL COMMENT '게시글 ID',
    parent_id BIGINT UNSIGNED NULL COMMENT '부모 댓글 ID (대댓글)',

    -- 작성자 (회원/비회원)
    member_id BIGINT UNSIGNED NULL COMMENT '회원 ID (NULL: 비회원)',
    author_name VARCHAR(50) NULL COMMENT '작성자 이름 (회원: 닉네임, 비회원: 입력값)',
    author_password VARCHAR(255) NULL COMMENT '비회원 비밀번호 (bcrypt, 회원: NULL)',

    -- 댓글 정보
    content TEXT NOT NULL COMMENT '내용',
    is_secret TINYINT(1) NOT NULL DEFAULT 0 COMMENT '비밀댓글 여부',
    status ENUM('published', 'deleted') NOT NULL DEFAULT 'published' COMMENT '상태',

    -- 통계
    reaction_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '총 반응수',

    -- 계층 구조 (Nested Set 또는 Path)
    depth TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '댓글 깊이 (0: 댓글, 1: 대댓글)',
    path VARCHAR(255) NOT NULL DEFAULT '' COMMENT '댓글 경로 (예: 1/3/12)',

    -- IP
    ip_address VARCHAR(45) NULL COMMENT '작성자 IP (IPv6 지원)',

    -- 관리
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '작성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_article_id (article_id),
    INDEX idx_parent_id (parent_id),
    INDEX idx_member_id (member_id),
    INDEX idx_path (path),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_article_list (article_id, status, path), -- 댓글 목록 조회 최적화
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
    FOREIGN KEY (board_id) REFERENCES board_configs(board_id) ON DELETE CASCADE,
    FOREIGN KEY (article_id) REFERENCES board_articles(article_id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES board_comments(comment_id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='댓글';

-- 7. board_reactions (반응 - 게시글/댓글 통합)
CREATE TABLE IF NOT EXISTS board_reactions (
    reaction_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '반응 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    board_id BIGINT UNSIGNED NOT NULL COMMENT '게시판 ID',

    -- 대상 (게시글 또는 댓글)
    target_type ENUM('article', 'comment') NOT NULL COMMENT '대상 타입',
    target_id BIGINT UNSIGNED NOT NULL COMMENT '대상 ID (article_id 또는 comment_id)',

    -- 반응자
    member_id BIGINT UNSIGNED NOT NULL COMMENT '회원 ID',

    -- 반응 타입
    reaction_type VARCHAR(20) NOT NULL COMMENT '반응 타입 (like, love, wow, sad, angry 등)',

    -- 관리
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',

    UNIQUE KEY uk_target_member (target_type, target_id, member_id),
    INDEX idx_target (target_type, target_id),
    INDEX idx_member_id (member_id),
    INDEX idx_reaction_type (reaction_type),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
    FOREIGN KEY (board_id) REFERENCES board_configs(board_id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='반응 (좋아요, 싫어요 등)';

-- 8. board_attachments (첨부파일)
CREATE TABLE IF NOT EXISTS board_attachments (
    attachment_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '첨부파일 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    board_id BIGINT UNSIGNED NOT NULL COMMENT '게시판 ID',
    article_id BIGINT UNSIGNED NOT NULL COMMENT '게시글 ID',

    -- 파일 정보
    original_name VARCHAR(255) NOT NULL COMMENT '원본 파일명',
    stored_name VARCHAR(255) NOT NULL COMMENT '저장된 파일명 (해시)',
    file_path VARCHAR(500) NOT NULL COMMENT '파일 경로',
    file_size BIGINT UNSIGNED NOT NULL COMMENT '파일 크기 (bytes)',
    file_extension VARCHAR(20) NOT NULL COMMENT '파일 확장자',
    mime_type VARCHAR(100) NOT NULL COMMENT 'MIME 타입',

    -- 이미지 정보 (이미지인 경우)
    is_image TINYINT(1) NOT NULL DEFAULT 0 COMMENT '이미지 여부',
    image_width INT UNSIGNED NULL COMMENT '이미지 가로',
    image_height INT UNSIGNED NULL COMMENT '이미지 세로',
    thumbnail_path VARCHAR(500) NULL COMMENT '썸네일 경로',

    -- 통계
    download_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '다운로드 횟수',

    -- 관리
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '업로드일',

    INDEX idx_article_id (article_id),
    INDEX idx_is_image (is_image),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
    FOREIGN KEY (board_id) REFERENCES board_configs(board_id) ON DELETE CASCADE,
    FOREIGN KEY (article_id) REFERENCES board_articles(article_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='첨부파일';

-- 9. board_links (링크)
CREATE TABLE IF NOT EXISTS board_links (
    link_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '링크 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    board_id BIGINT UNSIGNED NOT NULL COMMENT '게시판 ID',
    article_id BIGINT UNSIGNED NOT NULL COMMENT '게시글 ID',

    -- 링크 정보
    link_url VARCHAR(500) NOT NULL COMMENT '링크 URL',
    link_title VARCHAR(255) NULL COMMENT '링크 제목',
    link_description TEXT NULL COMMENT '링크 설명',
    link_image VARCHAR(500) NULL COMMENT '링크 이미지 (OG 이미지)',

    -- 통계
    click_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '클릭 횟수',

    -- 관리
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',

    INDEX idx_article_id (article_id),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
    FOREIGN KEY (board_id) REFERENCES board_configs(board_id) ON DELETE CASCADE,
    FOREIGN KEY (article_id) REFERENCES board_articles(article_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='링크';

-- 10. board_permissions (관리 권한 - 통합 테이블)
-- 그룹/카테고리/게시판 관리자를 하나의 테이블로 관리
CREATE TABLE IF NOT EXISTS board_permissions (
    permission_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '권한 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',

    -- 대상 (그룹, 카테고리, 게시판)
    target_type ENUM('group', 'category', 'board') NOT NULL COMMENT '대상 타입',
    target_id BIGINT UNSIGNED NOT NULL COMMENT '대상 ID (group_id, category_id, board_id)',

    -- 권한 대상 회원
    member_id BIGINT UNSIGNED NOT NULL COMMENT '회원 ID',

    -- 권한 타입 (확장 가능: admin, moderator, editor 등)
    permission_type VARCHAR(20) NOT NULL DEFAULT 'admin' COMMENT '권한 타입',

    -- 관리
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',

    UNIQUE KEY uk_permission (target_type, target_id, member_id, permission_type),
    INDEX idx_member (member_id),
    INDEX idx_target (target_type, target_id),
    INDEX idx_domain (domain_id),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
    FOREIGN KEY (member_id) REFERENCES members(member_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='게시판 관리 권한 (그룹/카테고리/게시판 통합)';

-- ====================================
-- 초기 데이터
-- ====================================
--
-- 초기 데이터는 웹 설치 과정에서 생성됩니다.
-- install/index.php에서 처리
--
-- 설치 시 생성될 기본 구조:
-- 1. 기본 게시판 그룹 생성 (운영게시판, 커뮤니티게시판)
-- 2. 기본 게시판 생성 (공지사항, 자유게시판)
-- 3. 기본 카테고리 생성 (공지, 일반)
-- 4. 기본 반응 설정 (좋아요, 싫어요)
