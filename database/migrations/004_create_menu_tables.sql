-- ====================================
-- Mublo Core - 메뉴 시스템
-- ====================================
--
-- 설계 철학:
-- 1. menu_items: 메뉴 정의 풀 (드래그 앤 드롭 소스)
-- 2. menu_tree: 메인 네비게이션 트리 구조
-- 3. 유틸리티/푸터 메뉴: menu_items의 플래그로 관리 (평면 구조)
-- 4. 같은 메뉴가 트리의 여러 위치에 배치 가능
--
-- [Event 발생 시점]
-- Plugin/Package에서 listen할 수 있는 Event 목록
--
-- 1. MenuItemsLoading (메뉴 아이템 로딩 시)
--    - 시점: DB에서 menu_items 조회 후
--    - 용도: 동적 메뉴 추가 (카테고리 목록, 게시판 목록 등)
--    - 예시: 쇼핑몰 카테고리를 메뉴로 동적 추가
--
-- 2. MenuRendering (메뉴 렌더링 전)
--    - 시점: 메뉴 데이터 로드 후, HTML 생성 전
--    - 용도: 메뉴 아이템 필터링, 권한 기반 제외
--
-- 3. MenuRendered (메뉴 렌더링 완료)
--    - 시점: HTML 생성 후, 출력 전
--    - 용도: HTML 후처리, 활성 메뉴 표시
--
-- [메뉴 확장 방식]
-- 1. 정적 방식: 플러그인/패키지 설치 시 menu_items에 INSERT (provider 컬럼으로 구분)
-- 2. 동적 방식: MenuItemsLoading 이벤트에서 런타임에 메뉴 추가
--
-- ====================================

-- 1. menu_items (메뉴 아이템 풀)
-- 도메인별 메뉴 정의. 관리자 UI에서 드래그 앤 드롭 소스로 사용.
CREATE TABLE IF NOT EXISTS menu_items (
    item_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '아이템 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    menu_code VARCHAR(20) NOT NULL COMMENT '메뉴 코드 (랜덤 8자, 유니크)',

    -- 메뉴 정보
    label VARCHAR(100) NOT NULL COMMENT '메뉴명 (한글)',
    url VARCHAR(255) NULL COMMENT '링크 URL (NULL: 클릭 불가 부모 메뉴)',
    icon VARCHAR(50) NULL COMMENT '아이콘 클래스명',
    target ENUM('_self', '_blank') NOT NULL DEFAULT '_self' COMMENT '링크 타겟',

    -- 접근 제어
    visibility ENUM('all', 'guest', 'member') NOT NULL DEFAULT 'all' COMMENT '표시 대상 (all: 모두, guest: 비로그인, member: 로그인)',
    pair_code VARCHAR(30) NULL COMMENT '메뉴 쌍 코드 (같은 값 = 한 묶음, 예: auth, account)',
    min_level TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '최소 회원 레벨 (0: 제한없음, 1~255: 해당 레벨 이상만)',
    required_permission VARCHAR(50) NULL COMMENT '필요 권한 코드 (NULL: 제한없음)',

    -- 디바이스별 표시
    show_on_pc TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'PC에서 표시',
    show_on_mobile TINYINT(1) NOT NULL DEFAULT 1 COMMENT '모바일에서 표시',

    -- 유틸리티/푸터/마이페이지 메뉴 (평면 구조)
    show_in_utility TINYINT(1) NOT NULL DEFAULT 0 COMMENT '유틸리티 메뉴 표시',
    show_in_footer TINYINT(1) NOT NULL DEFAULT 0 COMMENT '푸터 메뉴 표시',
    show_in_mypage TINYINT(1) NOT NULL DEFAULT 0 COMMENT '마이페이지 사이드바 표시',
    utility_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '유틸리티 메뉴 정렬순서',
    footer_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '푸터 메뉴 정렬순서',
    mypage_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '마이페이지 메뉴 정렬순서',
    is_system TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1: 시스템 필수 메뉴 — 관리자 삭제/비활성화 불가',

    -- 제공자 (Plugin/Package 연동)
    provider_type ENUM('core', 'plugin', 'package') NOT NULL DEFAULT 'core' COMMENT '제공자 타입',
    provider_name VARCHAR(50) NULL COMMENT '제공자 이름 (core면 NULL, plugin/package면 이름)',

    -- 관리
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '활성 여부',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    UNIQUE KEY uk_domain_menu (domain_id, menu_code),
    INDEX idx_domain_active (domain_id, is_active),
    INDEX idx_utility (domain_id, show_in_utility, utility_order),
    INDEX idx_footer (domain_id, show_in_footer, footer_order),
    INDEX idx_mypage (domain_id, show_in_mypage, mypage_order),
    INDEX idx_pair_code (domain_id, pair_code),
    INDEX idx_provider (provider_type, provider_name),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='메뉴 아이템 풀 (정의)';

-- 2. menu_tree (메뉴 트리 구조)
-- 메인 네비게이션의 계층 구조. 같은 menu_code가 여러 위치에 배치 가능.
-- path_code: 메뉴코드를 >로 연결 (예: mN7kP2xQ>xK9mL3nR)
-- path_name: 메뉴명을 >로 연결 (예: 홈>회사소개>오시는길)
CREATE TABLE IF NOT EXISTS menu_tree (
    node_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '노드 ID (URL 파라미터로 사용)',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    menu_code VARCHAR(20) NOT NULL COMMENT '메뉴 코드 (menu_items.menu_code 참조)',

    -- 경로 정보
    path_code VARCHAR(255) NOT NULL COMMENT '경로 코드 (메뉴코드>메뉴코드>메뉴코드)',
    path_name VARCHAR(500) NOT NULL COMMENT '경로명 (메뉴명>메뉴명>메뉴명, 브레드크럼 표시용)',
    parent_code VARCHAR(255) NULL COMMENT '부모 path_code (NULL: 루트)',
    depth TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '깊이 (1: 루트, 2: 1단계 자식...)',

    -- 정렬
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '같은 부모 내 정렬순서',

    INDEX idx_domain (domain_id),
    INDEX idx_path (domain_id, path_code),
    INDEX idx_parent (domain_id, parent_code, sort_order),
    INDEX idx_menu_code (domain_id, menu_code),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='메뉴 트리 구조 (메인 네비게이션)';

-- ====================================
-- 사용 예시
-- ====================================
--
-- 1. 메인 메뉴 조회 (1depth만):
--    SELECT mt.*, mi.label, mi.url, mi.icon
--    FROM menu_tree mt
--    JOIN menu_items mi ON mt.domain_id = mi.domain_id AND mt.menu_code = mi.menu_code
--    WHERE mt.domain_id = ? AND mt.depth = 1 AND mi.is_active = 1
--    ORDER BY mt.sort_order
--
-- 2. 유틸리티 메뉴 조회:
--    SELECT * FROM menu_items
--    WHERE domain_id = ? AND show_in_utility = 1 AND is_active = 1
--    ORDER BY utility_order
--
-- 3. 특정 노드의 자식 조회:
--    SELECT mt.*, mi.label, mi.url
--    FROM menu_tree mt
--    JOIN menu_items mi ON mt.domain_id = mi.domain_id AND mt.menu_code = mi.menu_code
--    WHERE mt.domain_id = ? AND mt.parent_code = ? AND mi.is_active = 1
--    ORDER BY mt.sort_order
--
-- 4. 브레드크럼 조회 (node_id로):
--    SELECT path_name FROM menu_tree WHERE node_id = ?
--    결과: "홈>회사소개>오시는길"
--
-- 5. URL ?nav=123 처리:
--    - node_id=123으로 menu_tree 조회
--    - path_name으로 브레드크럼 표시
--    - menu_code로 현재 메뉴 하이라이트
--
-- 6. 플러그인/패키지 메뉴 등록 (설치 시):
--    INSERT INTO menu_items (domain_id, menu_code, label, url, provider_type, provider_name)
--    VALUES (1, 'sHoP1234', '장바구니', '/shop/cart', 'package', 'Shop');
--
-- 7. 플러그인/패키지 삭제 시 메뉴 정리:
--    DELETE FROM menu_items WHERE provider_type = 'package' AND provider_name = 'Shop';
--
-- 8. 특정 타입 전체 조회:
--    SELECT * FROM menu_items WHERE provider_type = 'plugin';
--
-- 9. 동적 메뉴 추가 (이벤트):
--    // ShopMenuSubscriber.php
--    public function onMenuItemsLoading(MenuItemsLoadingEvent $event): void {
--        $categories = $this->categoryService->getAll();
--        foreach ($categories as $cat) {
--            $event->addItem([
--                'menu_code' => 'cat_' . $cat['id'],
--                'label' => $cat['name'],
--                'url' => '/shop/category/' . $cat['id'],
--                'provider_type' => 'package',
--                'provider_name' => 'Shop',
--            ]);
--        }
--    }
--
-- ====================================
-- 초기 데이터
-- ====================================
--
-- 초기 데이터는 웹 설치 과정에서 생성됩니다.
-- install/index.php에서 처리
--
-- 설치 시 생성될 기본 구조:
-- 1. 기본 메뉴 아이템 생성 (홈, 회사소개, 이용약관, 개인정보처리방침 등)
-- 2. 메인 메뉴 트리 구조 생성
-- 3. 유틸리티/푸터 메뉴 플래그 설정
