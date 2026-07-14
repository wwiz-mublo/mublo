-- 메뉴 아이템별 레이아웃 오버라이드
-- 메뉴 중심(스킨 렌더) 페이지가 도메인 기본 레이아웃 대신 자체 레이아웃을 쓰도록 한다.
-- 모두 NULL 허용 = 상속(도메인 기본을 따름). 블록 페이지는 자체 layout_type 을 가지므로
-- 이 오버라이드의 영향을 받지 않는다(FrontViewRenderer 우선순위: 블록페이지 > 메뉴 > 스킨힌트 > 도메인).
--
-- 문장을 분리해야 부분 적용 상태에서도 MigrationRunner 가 중복 컬럼만 건너뛰고
-- 아직 없는 나머지 컬럼을 계속 추가할 수 있다. MySQL/MariaDB 공통 문법만 사용한다.
ALTER TABLE `menu_items`
    ADD COLUMN `layout_type` TINYINT UNSIGNED NULL COMMENT '레이아웃 오버라이드 (NULL=상속, 1:전체 2:좌 3:우 4:양쪽)' AFTER `required_permission`;

ALTER TABLE `menu_items`
    ADD COLUMN `sidebar_left_width` SMALLINT UNSIGNED NULL COMMENT '좌측 사이드바 너비 (NULL=사이트 기본)' AFTER `layout_type`;

ALTER TABLE `menu_items`
    ADD COLUMN `sidebar_left_mobile` TINYINT(1) NULL COMMENT '좌측 사이드바 모바일 출력 (NULL=사이트 기본)' AFTER `sidebar_left_width`;

ALTER TABLE `menu_items`
    ADD COLUMN `sidebar_right_width` SMALLINT UNSIGNED NULL COMMENT '우측 사이드바 너비 (NULL=사이트 기본)' AFTER `sidebar_left_mobile`;

ALTER TABLE `menu_items`
    ADD COLUMN `sidebar_right_mobile` TINYINT(1) NULL COMMENT '우측 사이드바 모바일 출력 (NULL=사이트 기본)' AFTER `sidebar_right_width`;
