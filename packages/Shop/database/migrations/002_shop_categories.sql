-- ====================================
-- Shop Package - 카테고리 (2테이블 패턴)
-- ====================================
--
-- 설계 철학 (Core 메뉴 시스템과 동일):
-- 1. shop_category_items: 카테고리 정의 풀
-- 2. shop_category_tree: 계층 구조 (path_code 기반)
-- 3. 같은 카테고리가 트리의 여러 위치에 배치 가능
-- 4. path_code로 하위 카테고리 일괄 조회 가능
--
-- [쿼리 패턴]
-- 1. 전체 트리 조회:
--    SELECT ct.*, ci.name, ci.image
--    FROM shop_category_tree ct
--    JOIN shop_category_items ci ON ct.domain_id = ci.domain_id AND ct.category_code = ci.category_code
--    WHERE ct.domain_id = ? AND ci.is_active = 1
--    ORDER BY ct.depth, ct.sort_order
--
-- 2. 특정 카테고리 + 모든 하위 상품 조회:
--    SELECT g.* FROM shop_products g
--    JOIN shop_category_tree ct ON g.domain_id = ct.domain_id AND g.category_code = ct.category_code
--    WHERE ct.domain_id = ? AND ct.path_code LIKE 'abc12345>%'
--
-- 3. 브레드크럼:
--    SELECT path_name FROM shop_category_tree WHERE node_id = ?
--    결과: "의류>상의>티셔츠"
--

-- 1. shop_category_items (카테고리 정의 풀)
CREATE TABLE IF NOT EXISTS shop_category_items (
    category_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '카테고리 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    category_code VARCHAR(20) NOT NULL COMMENT '카테고리 코드 (랜덤 8자)',

    -- 카테고리 정보
    name VARCHAR(55) NOT NULL COMMENT '카테고리명',
    description VARCHAR(255) NULL COMMENT '카테고리 설명',
    image VARCHAR(255) NULL COMMENT '카테고리 이미지',

    -- 설정
    allow_member_level TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '접근 허용 회원 레벨 (0=전체)',
    allow_coupon TINYINT(1) NOT NULL DEFAULT 1 COMMENT '쿠폰 사용 허용',
    is_adult TINYINT(1) NOT NULL DEFAULT 0 COMMENT '성인인증 필요',

    -- 관리
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '사용 여부',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    UNIQUE KEY uk_domain_code (domain_id, category_code),
    INDEX idx_domain_active (domain_id, is_active),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='카테고리 정의';

-- 2. shop_category_tree (카테고리 트리 구조)
-- path_code: 카테고리코드를 >로 연결 (예: xK9mL3nR>mN7kP2xQ)
-- path_name: 카테고리명을 >로 연결 (예: 의류>상의>티셔츠)
CREATE TABLE IF NOT EXISTS shop_category_tree (
    node_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '노드 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    category_code VARCHAR(20) NOT NULL COMMENT '카테고리 코드 (shop_category_items.category_code 참조)',

    -- 경로 정보
    path_code VARCHAR(255) NOT NULL COMMENT '경로 코드 (코드>코드>코드)',
    path_name VARCHAR(500) NOT NULL COMMENT '경로명 (이름>이름>이름, 브레드크럼용)',
    parent_code VARCHAR(255) NULL COMMENT '부모 path_code (NULL: 루트)',
    depth TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '깊이 (1: 루트, 2: 1단계 자식...)',

    -- 정렬
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '같은 부모 내 정렬순서',

    INDEX idx_domain (domain_id),
    INDEX idx_path (domain_id, path_code),
    INDEX idx_parent (domain_id, parent_code, sort_order),
    INDEX idx_category_code (domain_id, category_code),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='카테고리 트리 구조';
