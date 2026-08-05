-- 외부 SNS 폐기 실패를 행에 기록해 재시도 대상으로 남긴다.
--
-- 회원 탈퇴는 로컬 커밋으로 확정되고, 제공자 폐기는 그 뒤에 시도한다.
-- 폐기가 실패했다고 탈퇴를 되돌릴 수는 없으므로, 실패한 연결은 행을 지우지 않고
-- 여기 표시만 남긴다 — 재시도에 필요한 access/refresh 토큰이 같은 행에 들어 있다.
-- 관리자는 SNS 연동 내역의 '폐기 실패' 탭에서 이 행을 찾아 다시 해제할 수 있다.
ALTER TABLE plugin_sns_login_accounts
    ADD COLUMN revoke_failed_at DATETIME NULL DEFAULT NULL COMMENT '제공자 폐기 실패 시각 (NULL이면 정상)' AFTER linked_at,
    ADD COLUMN revoke_failure_reason VARCHAR(255) NULL DEFAULT NULL COMMENT '폐기 실패 사유 (운영자 안내용)' AFTER revoke_failed_at;
