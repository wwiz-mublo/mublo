-- ====================================
-- Shop Package - '기타' 택배사
-- ====================================
--
-- 택배사 목록은 시드가 전부이고 관리자가 추가할 화면이 없다. 그래서 지역 택배·용달처럼
-- 목록에 없는 곳으로 보내면 송장을 남길 방법이 없었다(택배사는 필수 입력이다).
-- 추적 URL 없이 접수만 되는 항목을 하나 둔다 — tracking_url 이 NULL 을 허용하는 것은
-- 애초에 추적이 안 되는 배송을 예상한 스키마다.
--
-- 실제 택배사명은 송장 메모에 적는다.

-- FROM 절이 필요하다. MariaDB 10.3(지원 하한)은 FROM 없는 SELECT 에 WHERE 를 붙이면
-- 문법 오류를 낸다 — MySQL 5.7·8.4 와 MariaDB 10.11 이상은 받아준다. 지우지 말 것.
-- FROM DUAL 대신 파생 테이블을 쓴다: DUAL 은 MySQL 8.0.34 에서 폐기 예고되었다.
INSERT INTO shop_delivery_companies (delivery_method, company_name, tracking_url, callcenter)
SELECT 'COURIER', '기타', NULL, NULL
FROM (SELECT 1) AS placeholder
WHERE NOT EXISTS (
    SELECT 1 FROM shop_delivery_companies WHERE company_name = '기타'
);
