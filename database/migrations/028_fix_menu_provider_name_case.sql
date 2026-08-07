-- menu_items.provider_name 의 대소문자를 확장 디렉터리 이름으로 되돌린다.
--
-- MenuService::ensureItem() 이 provider_name 을 strtolower() 로 정규화하고 있었다.
-- 그 경로로 만들어진 항목은 'Faq' 대신 'faq' 로 저장됐다.
--
-- MySQL/MariaDB 의 기본 콜레이션(utf8mb4_*_ci)이 대소문자를 구분하지 않아 DB 안의
-- 조회는 전부 정상 동작했고, 그래서 오래 드러나지 않았다. 깨진 곳은 값이 DB 밖으로
-- 나가 비교되는 두 자리다.
--
--   1) 관리자 메뉴 수정 폼 — 저장된 이름을 활성 확장 목록과 JS 에서 === 로 맞춰
--      제공자명 select 를 선택한다. 'Faq' === 'faq' 가 false 라 아무것도 선택되지
--      않았다.
--   2) 마이페이지 사이드바 아이콘 — provider_name 으로 manifest.json 경로를 만든다.
--      리눅스 파일시스템은 대소문자를 구분하므로 plugins/faq/manifest.json 을 찾지
--      못하고 폴백 아이콘으로 떨어졌다.
--
-- WHERE 절의 비교는 콜레이션 덕에 대소문자를 가리지 않으므로, 이미 올바른 행에는
-- 같은 값을 다시 쓸 뿐이라 무해하다.
--
-- 번들 확장만 다룬다. 이름을 아는 대상이 이것뿐이다. 서드파티 확장이 이 경로로
-- 메뉴를 심었다면 관리자 화면에서 제공자명을 다시 선택해 저장하면 정정된다.

UPDATE `menu_items` SET `provider_name` = 'Board' WHERE `provider_type` = 'package' AND `provider_name` = 'Board';
UPDATE `menu_items` SET `provider_name` = 'Shop'  WHERE `provider_type` = 'package' AND `provider_name` = 'Shop';

UPDATE `menu_items` SET `provider_name` = 'Banner'       WHERE `provider_type` = 'plugin' AND `provider_name` = 'Banner';
UPDATE `menu_items` SET `provider_name` = 'EmailNotify'  WHERE `provider_type` = 'plugin' AND `provider_name` = 'EmailNotify';
UPDATE `menu_items` SET `provider_name` = 'Faq'          WHERE `provider_type` = 'plugin' AND `provider_name` = 'Faq';
UPDATE `menu_items` SET `provider_name` = 'Manual'       WHERE `provider_type` = 'plugin' AND `provider_name` = 'Manual';
UPDATE `menu_items` SET `provider_name` = 'MemberPoint'  WHERE `provider_type` = 'plugin' AND `provider_name` = 'MemberPoint';
UPDATE `menu_items` SET `provider_name` = 'PayApp'       WHERE `provider_type` = 'plugin' AND `provider_name` = 'PayApp';
UPDATE `menu_items` SET `provider_name` = 'Popup'        WHERE `provider_type` = 'plugin' AND `provider_name` = 'Popup';
UPDATE `menu_items` SET `provider_name` = 'Qna'          WHERE `provider_type` = 'plugin' AND `provider_name` = 'Qna';
UPDATE `menu_items` SET `provider_name` = 'SendonSms'    WHERE `provider_type` = 'plugin' AND `provider_name` = 'SendonSms';
UPDATE `menu_items` SET `provider_name` = 'SendonTalk'   WHERE `provider_type` = 'plugin' AND `provider_name` = 'SendonTalk';
UPDATE `menu_items` SET `provider_name` = 'SnsLogin'     WHERE `provider_type` = 'plugin' AND `provider_name` = 'SnsLogin';
UPDATE `menu_items` SET `provider_name` = 'Survey'       WHERE `provider_type` = 'plugin' AND `provider_name` = 'Survey';
UPDATE `menu_items` SET `provider_name` = 'TestPay'      WHERE `provider_type` = 'plugin' AND `provider_name` = 'TestPay';
UPDATE `menu_items` SET `provider_name` = 'VisitorStats' WHERE `provider_type` = 'plugin' AND `provider_name` = 'VisitorStats';
UPDATE `menu_items` SET `provider_name` = 'Widget'       WHERE `provider_type` = 'plugin' AND `provider_name` = 'Widget';
UPDATE `menu_items` SET `provider_name` = 'Board/BoardReport' WHERE `provider_type` = 'plugin' AND `provider_name` = 'Board/BoardReport';
