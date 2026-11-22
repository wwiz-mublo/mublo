-- 블록 변경 revision 자원 ID를 공개 site-editor 계약(block_row:{id})과 맞춘다.
-- 031이 이미 적용된 설치의 기존 행도 보존하며, 충돌 시 더 최신 revision 행을 남긴다.
INSERT INTO `site_resource_revisions`
    (`domain_id`, `resource_id`, `resource_type`, `revision`, `fingerprint`, `updated_at`)
SELECT
    `domain_id`,
    CONCAT('block_row:', SUBSTRING(`resource_id`, 7)),
    `resource_type`,
    `revision`,
    `fingerprint`,
    `updated_at`
FROM `site_resource_revisions`
WHERE `resource_type` = 'block'
  AND `resource_id` LIKE 'block:%'
ON DUPLICATE KEY UPDATE
    `fingerprint` = IF(VALUES(`revision`) >= `revision`, VALUES(`fingerprint`), `fingerprint`),
    `updated_at` = IF(VALUES(`revision`) >= `revision`, VALUES(`updated_at`), `updated_at`),
    `revision` = GREATEST(`revision`, VALUES(`revision`));

DELETE FROM `site_resource_revisions`
WHERE `resource_type` = 'block'
  AND `resource_id` LIKE 'block:%';
