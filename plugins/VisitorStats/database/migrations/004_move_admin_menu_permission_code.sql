-- VisitorStats: 관리자 메뉴 권한 코드 이전
-- 방문자 통계 메뉴가 사이트 관리(002) 하위에서 플러그인 1단 메뉴로 옮겨지며
-- 메뉴 코드가 002_P_VisitorStats_001 → P_VisitorStats_001 로 바뀌었다.
-- 회원 등급별 차단 설정을 새 코드로 옮겨, 업그레이드 뒤에도 차단이 유지되게 한다.

-- 새 코드에 이미 설정이 있으면(업그레이드 뒤 다시 설정한 경우) 그 설정을 둔다
UPDATE IGNORE member_level_denied_menus
SET menu_code = 'P_VisitorStats_001'
WHERE menu_code = '002_P_VisitorStats_001';

-- 위에서 옮기지 못한(새 코드와 겹친) 옛 코드 행은 더 이상 쓰이지 않는다
DELETE FROM member_level_denied_menus
WHERE menu_code = '002_P_VisitorStats_001';
