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

INSERT INTO shop_delivery_companies (delivery_method, company_name, tracking_url, callcenter)
SELECT 'COURIER', '기타', NULL, NULL
WHERE NOT EXISTS (
    SELECT 1 FROM shop_delivery_companies WHERE company_name = '기타'
);
