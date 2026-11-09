-- VisitorStats: 방문자 지역 정보 컬럼 추가 (MaxMind GeoLite2, 선택 기능)
ALTER TABLE `plugin_visitor_logs`
    ADD COLUMN `country_code` VARCHAR(2)   NOT NULL DEFAULT '' AFTER `ip_address`,
    ADD COLUMN `city`         VARCHAR(100) NOT NULL DEFAULT '' AFTER `country_code`;

-- 국가별 집계용 인덱스
ALTER TABLE `plugin_visitor_logs`
    ADD KEY `idx_domain_country` (`domain_id`, `country_code`, `visit_date`);
