-- 페이지 URL은 한 책 안에서 단일 페이지를 가리켜야 한다.
-- 서비스의 중복 검사와 별개로 동시 요청 경합도 DB 제약으로 차단한다.
ALTER TABLE `manual_pages`
    ADD UNIQUE KEY `uk_book_slug` (`book_id`, `slug`);
