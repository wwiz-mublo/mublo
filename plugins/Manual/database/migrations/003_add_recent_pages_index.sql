-- 최근 수정 매뉴얼 블록의 활성 페이지 정렬 조회를 보조한다.
ALTER TABLE `manual_pages`
    ADD INDEX `idx_book_active_updated` (`book_id`, `is_active`, `updated_at`);
