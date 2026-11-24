-- 블록 변경 revision 자원 ID를 공개 site-editor 계약(block_row:{id})과 맞춘다.
-- 031이 이미 적용된 설치의 기존 행도 보존한다. 부분 적용으로 새 ID가 이미 있으면
-- 새 ID 행을 기준으로 삼고, 그렇지 않으면 기존 행의 키만 제자리에서 바꾼다.
DELETE legacy
FROM `site_resource_revisions` AS legacy
INNER JOIN `site_resource_revisions` AS current_row
    ON current_row.`domain_id` = legacy.`domain_id`
   AND current_row.`resource_id` = CONCAT('block_row:', SUBSTRING(legacy.`resource_id`, 7))
WHERE legacy.`resource_type` = 'block'
  AND legacy.`resource_id` LIKE 'block:%';

UPDATE `site_resource_revisions`
SET `resource_id` = CONCAT('block_row:', SUBSTRING(`resource_id`, 7))
WHERE `resource_type` = 'block'
  AND `resource_id` LIKE 'block:%';
