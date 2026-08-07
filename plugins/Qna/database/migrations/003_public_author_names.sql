-- 회원이 작성한 질문·답변의 표시명 스냅샷에서 실명과 로그인 아이디를 제거한다.
UPDATE `qna_posts` q
JOIN `members` m ON m.member_id = q.member_id AND m.domain_id = q.domain_id
SET q.author_name = CASE
    WHEN TRIM(COALESCE(m.nickname, '')) <> '' THEN m.nickname
    ELSE CONCAT('회원 ', LEFT(m.public_id, 12))
END
WHERE q.member_id > 0;
