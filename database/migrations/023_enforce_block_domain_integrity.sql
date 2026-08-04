-- 블록 부모/자식 참조가 같은 domain_id 안에서만 이어지도록 DB가 직접 강제한다.
-- 기존 단일 FK는 하위 호환과 삭제 CASCADE를 위해 유지하고 복합 FK를 추가한다.
-- 복합 FK 추가가 실패하면 기존 데이터에 교차 도메인 참조가 있다는 뜻이므로
-- 데이터를 임의 이동/삭제하지 않고 마이그레이션을 중단해 운영자가 확인하게 한다.

ALTER TABLE `block_pages`
    ADD UNIQUE KEY `uq_block_pages_id_domain` (`page_id`, `domain_id`);

ALTER TABLE `block_rows`
    ADD UNIQUE KEY `uq_block_rows_id_domain` (`row_id`, `domain_id`);

ALTER TABLE `block_rows`
    ADD KEY `idx_block_rows_page_domain` (`page_id`, `domain_id`);

ALTER TABLE `block_columns`
    ADD KEY `idx_block_columns_row_domain` (`row_id`, `domain_id`);

ALTER TABLE `block_rows`
    ADD CONSTRAINT `fk_block_rows_page_domain`
        FOREIGN KEY (`page_id`, `domain_id`)
        REFERENCES `block_pages` (`page_id`, `domain_id`) ON DELETE CASCADE;

ALTER TABLE `block_columns`
    ADD CONSTRAINT `fk_block_columns_row_domain`
        FOREIGN KEY (`row_id`, `domain_id`)
        REFERENCES `block_rows` (`row_id`, `domain_id`) ON DELETE CASCADE;
