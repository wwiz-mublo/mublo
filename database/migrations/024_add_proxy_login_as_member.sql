-- 대리 로그인 토큰에 "이 회원으로 로그인" 지정 추가
--
-- 기존 대리 로그인은 항상 domain_configs.member_id(대상 도메인 소유자)로 로그인한다.
-- 자기 도메인의 호스트명을 변경하면 세션 쿠키가 옛 호스트에 묶여 있어 새 호스트에서
-- 로그아웃되므로, 변경을 실행한 그 관리자 계정으로 새 호스트에 재로그인시키는
-- 인계 토큰이 필요하다. NULL이면 기존 동작(소유자 로그인) 그대로다.
--
-- MySQL/MariaDB 공통 문법만 사용한다(중복 컬럼은 MigrationRunner가 건너뛴다).

ALTER TABLE `proxy_login_tokens`
    ADD COLUMN `login_member_id` BIGINT UNSIGNED NULL COMMENT '이 회원으로 로그인 (NULL이면 대상 도메인 소유자)' AFTER `admin_member_id`;
