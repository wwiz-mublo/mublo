-- 회원을 클라이언트 경계에서 가리킬 때 사용하는 비순차 공개 식별자.
-- 내부 FK/조인/권한 판정은 계속 member_id를 사용한다.

ALTER TABLE `members`
    ADD COLUMN `public_id` CHAR(22) NULL COMMENT '공개 식별자 (화면 노출용)' AFTER `member_id`;

-- MySQL 5.7/MariaDB 호환 백필. 신규 회원은 PHP random_bytes()로 생성한다.
UPDATE `members`
    SET `public_id` = SUBSTRING(SHA2(CONCAT(UUID(), RAND(), `member_id`), 256), 1, 22)
    WHERE `public_id` IS NULL;

ALTER TABLE `members`
    MODIFY `public_id` CHAR(22) NOT NULL COMMENT '공개 식별자 (화면 노출용)',
    ADD UNIQUE KEY `uk_member_public_id` (`public_id`);
