-- FAQ 플러그인 설정 (도메인별 프론트 스킨)
-- 스킨 설정은 플러그인이 소유한다 — 기존 domain_configs.extra_config['faq_skin'] 을 여기로 이관.
CREATE TABLE IF NOT EXISTS plugin_faq_configs (
    config_id INT AUTO_INCREMENT PRIMARY KEY,
    domain_id INT NOT NULL COMMENT '도메인 ID',
    skin_name VARCHAR(50) NOT NULL DEFAULT 'basic' COMMENT '프론트 스킨',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_domain (domain_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 기존 extra_config.faq_skin 값을 plugin_faq_configs 로 이관 (선택값 보존)
INSERT INTO plugin_faq_configs (domain_id, skin_name)
SELECT domain_id, JSON_UNQUOTE(JSON_EXTRACT(extra_config, '$.faq_skin'))
FROM domain_configs
WHERE JSON_EXTRACT(extra_config, '$.faq_skin') IS NOT NULL
ON DUPLICATE KEY UPDATE skin_name = VALUES(skin_name);

-- 이관 후 코어 테이블에서 leak 된 키 제거
UPDATE domain_configs
SET extra_config = JSON_REMOVE(extra_config, '$.faq_skin')
WHERE JSON_EXTRACT(extra_config, '$.faq_skin') IS NOT NULL;
