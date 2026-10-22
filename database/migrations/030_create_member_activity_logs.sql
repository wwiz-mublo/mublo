-- 회원 활동 기록 (회원이 본 페이지)
--
-- 사이트 설정 '회원 활동 기록'을 켠 사이트에서만 쌓인다 (기본 꺼짐).
-- PageViewedEvent 를 MemberActivitySubscriber 가 받아 로그인 회원의 페이지 조회만 남긴다.
-- IP·User-Agent 는 담지 않는다 — 관리자가 회원의 관심사를 보는 용도라 주소·제목·시각이면 충분하다.
-- 보관 기간(member_activity_days)이 지난 행은 기록할 때 가끔 지우고, 탈퇴하면 그 회원의 행을 바로 지운다.

CREATE TABLE IF NOT EXISTS member_activity_logs (
    activity_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '사이트 도메인 ID',
    member_id BIGINT UNSIGNED NOT NULL COMMENT '회원 ID',
    page_type VARCHAR(30) NOT NULL DEFAULT 'other' COMMENT 'PageViewedEvent 페이지 유형',
    page_url VARCHAR(500) NOT NULL COMMENT '본 페이지 (경로+쿼리)',
    page_title VARCHAR(200) NOT NULL DEFAULT '' COMMENT '페이지 제목',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '본 시각',

    INDEX idx_member_recent (domain_id, member_id, activity_id),
    INDEX idx_domain_created (domain_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='회원 활동 기록';
