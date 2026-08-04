-- ============================================================
-- QnA Plugin - 도메인별 플러그인 설정
-- ------------------------------------------------------------
-- 설정은 플러그인이 소유한다 — 코어 domain_configs 에 두지 않는다.
-- 정책 토글을 컬럼으로 노출해, 코드 변경 없이 운영 정책을 바꾼다.
--   require_login   : 문의 작성에 로그인 필요(1). 현재 기본.
--   default_secret  : 신규 문의 비밀글 기본값(1=철저한 비밀글).
--   allow_secret    : 작성자가 공개/비밀 선택 가능 여부(0=강제 비밀).
--   allow_guest     : 비회원 문의 허용(0). 미래 대비.
--   use_category    : 문의 유형(카테고리) 사용 여부(0).
--   notify_email    : 신규 문의/답변 시 운영자 이메일 알림(0, EmailNotify 연동 여지).
--   allow_file      : 문의/답변에 파일 첨부 허용(1). 첨부는 보안 저장소에 저장되고
--                     메타는 qna_posts.meta JSON 에 담긴다(별도 테이블 없음).
--   file_max_mb     : 파일 1개당 최대 크기(MB).
--   file_max_count  : 글 1개당 첨부 개수 상한.
--   file_allowed_ext: 허용 확장자(콤마) — 코어 UploadPolicy allowlist 안에서만 좁힘.
-- ============================================================

CREATE TABLE IF NOT EXISTS `plugin_qna_configs` (
    `config_id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id`      INT UNSIGNED NOT NULL COMMENT '도메인 ID',
    `skin_name`      VARCHAR(50) NOT NULL DEFAULT 'basic' COMMENT '프론트 스킨',
    `require_login`  TINYINT(1) NOT NULL DEFAULT 1 COMMENT '작성 시 로그인 필요',
    `default_secret` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '신규 문의 비밀글 기본값',
    `allow_secret`   TINYINT(1) NOT NULL DEFAULT 1 COMMENT '작성자 공개/비밀 선택 허용',
    `allow_guest`    TINYINT(1) NOT NULL DEFAULT 0 COMMENT '비회원 문의 허용',
    `use_category`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '문의 유형 사용',
    `notify_email`   TINYINT(1) NOT NULL DEFAULT 0 COMMENT '운영자 이메일 알림',
    `allow_file`       TINYINT(1)       NOT NULL DEFAULT 1 COMMENT '파일 첨부 허용',
    `file_max_mb`      TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '파일당 최대 크기(MB)',
    `file_max_count`   TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '글당 첨부 개수 상한',
    `file_allowed_ext` VARCHAR(255)     NOT NULL DEFAULT 'jpg,jpeg,png,gif,pdf,zip,docx,xlsx,pptx,hwp,hwpx' COMMENT '허용 확장자(콤마)',
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`config_id`),
    UNIQUE KEY `uk_domain` (`domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='QnA 플러그인 도메인 설정';
