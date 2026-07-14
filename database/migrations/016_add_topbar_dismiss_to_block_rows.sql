-- topbar 블록 "보지 않기"(관리자 지정 기간 동안 숨김) 기능
-- dismissible : "보지 않기" 버튼 표시 여부 (topbar 행에서만 의미)
-- dismiss_hours : 방문자가 버튼을 누르면 유지되는 숨김 시간(hours). 24=1일.
-- 문장을 분리해야 부분 적용 상태에서도 MigrationRunner가 중복 컬럼만 건너뛰고
-- 아직 없는 나머지 컬럼을 계속 추가할 수 있다. MySQL/MariaDB 공통 문법만 사용한다.
ALTER TABLE `block_rows`
    ADD COLUMN `dismissible` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'topbar "보지 않기" 버튼 사용' AFTER `is_active`;

ALTER TABLE `block_rows`
    ADD COLUMN `dismiss_hours` INT NOT NULL DEFAULT 24 COMMENT '보지 않기 유지 시간(hours)' AFTER `dismissible`;
