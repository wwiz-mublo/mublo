-- 회원 작성자 스냅샷에서 로그인 아이디가 공개되지 않도록 현재 공개 표시명으로 정리한다.
-- 닉네임이 없으면 public_id 기반의 안정적인 익명 표시명을 사용한다.

UPDATE board_articles a
INNER JOIN members m
    ON m.member_id = a.member_id
   AND m.domain_id = a.domain_id
SET a.author_name = CASE
    WHEN NULLIF(TRIM(m.nickname), '') IS NOT NULL THEN TRIM(m.nickname)
    ELSE CONCAT('회원 ', LEFT(m.public_id, 12))
END
WHERE a.member_id IS NOT NULL;

UPDATE board_comments c
INNER JOIN members m
    ON m.member_id = c.member_id
   AND m.domain_id = c.domain_id
SET c.author_name = CASE
    WHEN NULLIF(TRIM(m.nickname), '') IS NOT NULL THEN TRIM(m.nickname)
    ELSE CONCAT('회원 ', LEFT(m.public_id, 12))
END
WHERE c.member_id IS NOT NULL;
