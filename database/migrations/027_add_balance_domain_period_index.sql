-- 도메인 + 기간으로 원장을 훑는 두 경로가 함께 쓰는 인덱스.
--
--   1) 관리자 포인트 내역 (BalanceLogRepository::getPaginatedList)
--      목록은 LIMIT 20 이라 원래 빨랐고, 느린 쪽은 같은 요청에서 항상 함께 도는
--      총 건수 COUNT 였다. (domain_id, created_at) 조합이 없어 idx_created 로
--      기간 전체를 훑으며 domain_id 를 행마다 확인했다.
--
--   2) 기간 랭킹 집계 (BalanceRankingRepository)
--
-- amount 를 마지막에 두는 것이 이 인덱스의 핵심이다. 두 경로 모두 SUM(amount)
-- 또는 COUNT 로 끝나므로, amount 가 인덱스에 있으면 실행계획이
-- 'Using index condition' 에서 'Using index' 로 바뀌어 테이블을 아예 읽지 않는다.
-- 빼면 매 행 PK 룩업이 남아 이득의 대부분이 사라진다.
--
-- 실측 (MariaDB 10.11 / balance_logs 100만 행 / 회원 2만 명):
--   관리자 내역 COUNT   110.6ms → 19.5ms
--   기간 랭킹 집계      235.1ms → 125.4ms
--   INSERT (행당)       0.022ms → 0.041ms   (인덱스 총량 +42MB)
--
-- 쓰기 비용은 포인트 적립 1건당 약 20마이크로초다. 원장은 INSERT ONLY 이고
-- 선두가 (domain_id, created_at) 이라 삽입이 각 도메인 구간의 오른쪽 끝에만
-- 떨어지므로 페이지 분할도 적다.

ALTER TABLE balance_logs
    ADD INDEX idx_domain_created_member (domain_id, created_at, member_id, amount);
