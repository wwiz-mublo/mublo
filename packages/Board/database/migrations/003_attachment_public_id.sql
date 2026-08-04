-- 첨부파일 다운로드 URL 의 식별자를 연속 정수에서 난수 문자열로 교체한다.
--
-- 기존 URL 은 /board/{board_id}/file/download/{attachment_id} 형태였다. attachment_id 는
-- AUTO_INCREMENT 라 URL 만 보고도 전체 첨부 건수와 증가 속도가 드러나고, 번호를 훑어
-- 존재 여부를 확인할 수 있었다. 다운로드 자체는 읽기 권한 + 다운로드 권한 검사가
-- 막고 있었으므로 취약점은 아니지만, 알려줄 필요가 없는 정보였다.
--
-- 이미지 첨부는 애초에 저장 해시(stored_name) 직링크로 나가므로 이 변경과 무관하다.
-- 첨부 URL 은 저장되지 않고 조회 시점에 생성되므로(BoardArticleRepository), 기존 글의
-- 본문이 깨지지 않는다. 외부에 공유된 다운로드 링크만 끊긴다 — 배포 초기라 감수한다.

-- 1) 컬럼 추가. 백필 전에는 NULL 을 허용해야 기존 행이 들어간다.
ALTER TABLE `board_attachments`
    ADD COLUMN `public_id` CHAR(22) NULL COMMENT '공개 식별자 (URL 노출용)' AFTER `attachment_id`;

-- 2) 기존 행 백필.
--    RANDOM_BYTES 는 MySQL 8.0.17+ / MariaDB 10.10+ 함수라 지원 하한(MySQL 5.7.8)에서
--    쓸 수 없다. 양쪽에 모두 있는 UUID()+RAND()+행 식별자를 SHA2 로 섞어 균등 분포를
--    얻는다. UUID() 와 RAND() 는 행마다 재평가되므로 값이 겹치지 않는다.
--
--    이 백필 값은 '순번을 감춘다'는 목적에는 충분하지만 암호학적 난수는 아니다.
--    신규 업로드는 PHP random_bytes() 로 생성한다(BoardFileService). 백필 대상은
--    변경 이전에 쌓인 행뿐이라 이 차이가 문제되지 않는다.
UPDATE `board_attachments`
    SET `public_id` = SUBSTRING(SHA2(CONCAT(UUID(), RAND(), `attachment_id`), 256), 1, 22)
    WHERE `public_id` IS NULL;

-- 3) NOT NULL + UNIQUE 로 고정. 이 시점에 중복이 있으면 마이그레이션이 실패해야 한다 —
--    조용히 넘어가면 서로 다른 첨부가 같은 URL 을 갖는다.
ALTER TABLE `board_attachments`
    MODIFY `public_id` CHAR(22) NOT NULL COMMENT '공개 식별자 (URL 노출용)',
    ADD UNIQUE KEY `uk_public_id` (`public_id`);
