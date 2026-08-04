-- ============================================================
-- QnA Plugin - 문의 테이블 생성 (유형 + 문의 글)
-- ============================================================

-- ------------------------------------------------------------
-- 문의 유형(카테고리)
-- 현재는 단일 1:1 문의만 사용하지만, 향후 "결제/배송/계정/기타" 등
-- 유형별 분류를 켤 수 있도록 미리 확보한다.
-- (plugin_qna_configs.use_category 로 노출 여부 토글)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `qna_categories` (
    `category_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id`     INT UNSIGNED NOT NULL DEFAULT 1,
    `category_name` VARCHAR(100) NOT NULL COMMENT '유형명',
    `category_slug` VARCHAR(100) NOT NULL COMMENT 'URL/식별용 슬러그',
    `sort_order`    INT NOT NULL DEFAULT 0,
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`category_id`),
    UNIQUE KEY `uk_domain_slug` (`domain_id`, `category_slug`),
    KEY `idx_domain_active_order` (`domain_id`, `is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='QnA 문의 유형';

-- ------------------------------------------------------------
-- 문의 글 통합 테이블 (질문 + 답변 단일 테이블)
-- 한 행 = 하나의 글. 자기참조(parent_id)로 질문과 답변을 한 테이블에 담는다.
--   parent_id IS NULL → 질문(스레드 헤드)
--   parent_id = 질문 post_id → 그 질문에 달린 답변/추가문의
--
-- 운영자 답변을 질문 레코드의 "컬럼"으로 넣지 않고 별도 "행"으로 쌓는
-- 답변글 로직은 그대로 유지된다. depth(계층)는 쓰지 않는 평면 스레드.
--
-- 컬럼 적용 범위:
--   질문 전용 : title, category_id, status, priority, assignee_id,
--               reply_count, last_reply_*, view_count, answered_at, closed_at,
--               author_email, author_phone, password_hash
--   답변 전용 : is_private(운영자 내부 비노출 메모)
--   공통     : member_id, author_type, author_name, content, is_secret,
--               ip_address, meta, created_at, updated_at
--
-- 유연성:
--   member_id 0   → 비회원(게스트) 대비. 현재는 회원만 받음.
--   password_hash → 비회원 비밀글 조회 대비.
--   meta JSON     → 스키마 변경 없이 태그/첨부요약/만족도 등 확장.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `qna_posts` (
    `post_id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id`       INT UNSIGNED NOT NULL DEFAULT 1,
    `parent_id`       BIGINT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL=질문, 값=소속 질문 post_id',
    `category_id`     INT UNSIGNED NULL DEFAULT NULL COMMENT '문의 유형(질문 전용)',

    -- 작성자
    `member_id`       BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '작성 회원 ID (0=비회원)',
    `author_type`     ENUM('member','admin','guest') NOT NULL DEFAULT 'member' COMMENT '작성 주체',
    `author_name`     VARCHAR(50)  NULL DEFAULT NULL COMMENT '작성자명 스냅샷',
    `author_email`    VARCHAR(190) NULL DEFAULT NULL COMMENT '연락 이메일(질문/알림용)',
    `author_phone`    VARCHAR(30)  NULL DEFAULT NULL COMMENT '연락처(질문/알림용)',
    `password_hash`   VARCHAR(255) NULL DEFAULT NULL COMMENT '비회원 비밀글 조회 해시',

    -- 내용
    `title`           VARCHAR(200) NULL DEFAULT NULL COMMENT '제목(질문 전용)',
    `content`         MEDIUMTEXT   NOT NULL COMMENT '본문(에디터 HTML)',
    `is_secret`       TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '비밀글 여부(질문 단위)',
    `is_private`      TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '운영자 내부 비노출 메모(답변 전용)',

    -- 처리 상태 (질문 전용)
    `status`          ENUM('open','answered','closed','hold') NOT NULL DEFAULT 'open' COMMENT '처리 상태',
    `priority`        TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '우선순위(클수록 높음)',
    `assignee_id`     BIGINT UNSIGNED NULL DEFAULT NULL COMMENT '담당 운영자 member_id',

    -- 스레드 집계 (질문 전용, 비정규화)
    `reply_count`     INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '답변/추가글 수',
    `last_reply_at`   DATETIME NULL DEFAULT NULL COMMENT '마지막 글 작성 시각',
    `last_reply_role` ENUM('member','admin','guest') NULL DEFAULT NULL COMMENT '마지막 글 작성 주체',
    `view_count`      INT UNSIGNED NOT NULL DEFAULT 0,

    -- 부가
    `ip_address`      VARCHAR(45) NOT NULL DEFAULT '' COMMENT '작성 IP',
    `meta`            JSON NULL DEFAULT NULL COMMENT '확장 슬롯',

    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `answered_at`     DATETIME NULL DEFAULT NULL COMMENT '최초 운영자 답변 시각(질문)',
    `closed_at`       DATETIME NULL DEFAULT NULL COMMENT '종료 시각(질문)',

    PRIMARY KEY (`post_id`),
    -- (domain, parent) 접두사가 핵심: 질문목록(parent IS NULL) / 스레드조회(parent = head) 모두 커버
    KEY `idx_domain_parent_status` (`domain_id`, `parent_id`, `status`, `post_id`),
    KEY `idx_domain_parent_member` (`domain_id`, `parent_id`, `member_id`, `post_id`),
    KEY `idx_domain_category`      (`domain_id`, `category_id`),
    KEY `idx_assignee`             (`assignee_id`),
    KEY `idx_last_reply`           (`domain_id`, `last_reply_at`),
    CONSTRAINT `fk_qna_post_parent`
        FOREIGN KEY (`parent_id`) REFERENCES `qna_posts`(`post_id`) ON DELETE CASCADE,
    CONSTRAINT `fk_qna_post_category`
        FOREIGN KEY (`category_id`) REFERENCES `qna_categories`(`category_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='QnA 문의 글(질문+답변 통합)';
