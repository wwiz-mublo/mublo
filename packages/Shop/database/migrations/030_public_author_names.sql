-- 로그인 아이디/실명이 저장된 회원 문의 작성자 스냅샷을 공개 표시명 정책으로 정리한다.
UPDATE `shop_inquiries` q
JOIN `members` m ON m.member_id = q.member_id AND m.domain_id = q.domain_id
SET q.author_name = CASE
    WHEN TRIM(COALESCE(m.nickname, '')) <> '' THEN m.nickname
    ELSE CONCAT('회원 ', LEFT(m.public_id, 12))
END
WHERE q.member_id > 0;
