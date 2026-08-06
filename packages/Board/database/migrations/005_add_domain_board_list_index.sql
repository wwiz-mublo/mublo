-- 일반 게시판 목록과 전체 건수 조회를 위한 도메인 범위 복합 인덱스.
--
-- 기존 idx_board_list는 전역 게시판처럼 domain_id 조건이 없는 조회에 사용한다.
-- 일반 게시판은 domain_id까지 조건에 포함하므로 별도 인덱스를 두어 COUNT의
-- index_merge와 목록 조회의 filesort를 피한다.

ALTER TABLE `board_articles`
    ADD INDEX `idx_domain_board_list`
        (`domain_id`, `board_id`, `status`, `is_notice`, `created_at`);
