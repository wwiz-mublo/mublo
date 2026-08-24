-- Shop Package - 상품정보제공고시

CREATE TABLE IF NOT EXISTS shop_product_notice_templates (
    template_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type_code VARCHAR(50) NOT NULL COMMENT '품목 코드',
    revision VARCHAR(20) NOT NULL COMMENT '고시 양식 버전',
    name VARCHAR(100) NOT NULL COMMENT '품목명',
    effective_from DATE NOT NULL COMMENT '시행일',
    source_title VARCHAR(255) NOT NULL DEFAULT '' COMMENT '근거 고시',
    is_current TINYINT(1) NOT NULL DEFAULT 0 COMMENT '신규 상품 기본 양식',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_notice_template_revision (type_code, revision),
    INDEX idx_notice_template_current (is_current, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품정보제공고시 품목 양식';

CREATE TABLE IF NOT EXISTS shop_product_notice_template_fields (
    field_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    template_id BIGINT UNSIGNED NOT NULL,
    field_code VARCHAR(50) NOT NULL COMMENT '버전 간 값 승계용 항목 코드',
    label VARCHAR(255) NOT NULL COMMENT '표시 항목명',
    help_text VARCHAR(500) NOT NULL DEFAULT '' COMMENT '입력 안내',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_notice_template_field (template_id, field_code),
    INDEX idx_notice_template_field_sort (template_id, sort_order),
    FOREIGN KEY (template_id) REFERENCES shop_product_notice_templates(template_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품정보제공고시 양식 항목';

CREATE TABLE IF NOT EXISTS shop_product_notices (
    product_notice_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    domain_id BIGINT UNSIGNED NOT NULL,
    goods_id BIGINT UNSIGNED NOT NULL,
    template_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_product_notice (domain_id, goods_id),
    INDEX idx_product_notice_template (template_id),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
    FOREIGN KEY (goods_id) REFERENCES shop_products(goods_id) ON DELETE CASCADE,
    FOREIGN KEY (template_id) REFERENCES shop_product_notice_templates(template_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품별 상품정보제공고시 선택';

CREATE TABLE IF NOT EXISTS shop_product_notice_values (
    value_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_notice_id BIGINT UNSIGNED NOT NULL,
    field_code VARCHAR(50) NOT NULL,
    field_value TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_product_notice_value (product_notice_id, field_code),
    FOREIGN KEY (product_notice_id) REFERENCES shop_product_notices(product_notice_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품별 상품정보제공고시 입력값';

INSERT INTO shop_product_notice_templates
    (type_code, revision, name, effective_from, source_title, is_current)
VALUES
    ('clothing', '2023-01-01', '의류', '2023-01-01', '전자상거래 등에서의 상품 등의 정보제공에 관한 고시', 1),
    ('shoes', '2023-01-01', '구두/신발', '2023-01-01', '전자상거래 등에서의 상품 등의 정보제공에 관한 고시', 1),
    ('bag', '2023-01-01', '가방', '2023-01-01', '전자상거래 등에서의 상품 등의 정보제공에 관한 고시', 1),
    ('fashion_accessory', '2023-01-01', '패션잡화', '2023-01-01', '전자상거래 등에서의 상품 등의 정보제공에 관한 고시', 1),
    ('bedding', '2023-01-01', '침구류/커튼', '2023-01-01', '전자상거래 등에서의 상품 등의 정보제공에 관한 고시', 1),
    ('furniture', '2023-01-01', '가구', '2023-01-01', '전자상거래 등에서의 상품 등의 정보제공에 관한 고시', 1),
    ('other_goods', '2023-01-01', '기타 재화', '2023-01-01', '전자상거래 등에서의 상품 등의 정보제공에 관한 고시', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), source_title = VALUES(source_title);

INSERT INTO shop_product_notice_template_fields (template_id, field_code, label, help_text, sort_order)
SELECT t.template_id, f.field_code, f.label, f.help_text, f.sort_order
FROM shop_product_notice_templates t
JOIN (
    SELECT 'clothing' type_code, 'material' field_code, '제품 소재' label, '섬유의 조성 또는 혼용률' help_text, 10 sort_order UNION ALL
    SELECT 'clothing','color','색상','',20 UNION ALL SELECT 'clothing','size','치수','',30 UNION ALL
    SELECT 'clothing','manufacturer','제조자/수입자','수입품은 수입자를 함께 표기',40 UNION ALL
    SELECT 'clothing','country','제조국','',50 UNION ALL SELECT 'clothing','care','세탁방법 및 취급 시 주의사항','',60 UNION ALL
    SELECT 'clothing','manufactured_at','제조연월','',70 UNION ALL SELECT 'clothing','warranty','품질보증기준','',80 UNION ALL
    SELECT 'clothing','service','A/S 책임자와 전화번호','',90 UNION ALL
    SELECT 'shoes','material','제품 소재','운동화는 겉감과 안감을 구분',10 UNION ALL SELECT 'shoes','color','색상','',20 UNION ALL
    SELECT 'shoes','size','치수','발길이 및 해당하는 경우 굽높이',30 UNION ALL SELECT 'shoes','manufacturer','제조자/수입자','',40 UNION ALL
    SELECT 'shoes','country','제조국','',50 UNION ALL SELECT 'shoes','care','취급 시 주의사항','',60 UNION ALL
    SELECT 'shoes','warranty','품질보증기준','',70 UNION ALL SELECT 'shoes','service','A/S 책임자와 전화번호','',80 UNION ALL
    SELECT 'bag','material','종류 및 소재','',10 UNION ALL SELECT 'bag','color','색상','',20 UNION ALL SELECT 'bag','size','크기','',30 UNION ALL
    SELECT 'bag','manufacturer','제조자/수입자','',40 UNION ALL SELECT 'bag','country','제조국','',50 UNION ALL
    SELECT 'bag','care','취급 시 주의사항','',60 UNION ALL SELECT 'bag','warranty','품질보증기준','',70 UNION ALL SELECT 'bag','service','A/S 책임자와 전화번호','',80 UNION ALL
    SELECT 'fashion_accessory','item_type','종류','',10 UNION ALL SELECT 'fashion_accessory','material','소재','',20 UNION ALL
    SELECT 'fashion_accessory','size','치수','',30 UNION ALL SELECT 'fashion_accessory','manufacturer','제조자/수입자','',40 UNION ALL
    SELECT 'fashion_accessory','country','제조국','',50 UNION ALL SELECT 'fashion_accessory','care','취급 시 주의사항','',60 UNION ALL
    SELECT 'fashion_accessory','warranty','품질보증기준','',70 UNION ALL SELECT 'fashion_accessory','service','A/S 책임자와 전화번호','',80 UNION ALL
    SELECT 'bedding','material','제품 소재','섬유의 조성 또는 혼용률',10 UNION ALL SELECT 'bedding','color','색상','',20 UNION ALL
    SELECT 'bedding','size','치수','',30 UNION ALL SELECT 'bedding','components','제품 구성','',40 UNION ALL
    SELECT 'bedding','manufacturer','제조자/수입자','',50 UNION ALL SELECT 'bedding','country','제조국','',60 UNION ALL
    SELECT 'bedding','care','세탁방법 및 취급 시 주의사항','',70 UNION ALL SELECT 'bedding','warranty','품질보증기준','',80 UNION ALL SELECT 'bedding','service','A/S 책임자와 전화번호','',90 UNION ALL
    SELECT 'furniture','item_name','품명','',10 UNION ALL SELECT 'furniture','certification','KC 인증정보','해당되는 경우',20 UNION ALL
    SELECT 'furniture','color','색상','',30 UNION ALL SELECT 'furniture','components','구성품','',40 UNION ALL SELECT 'furniture','material','주요 소재','',50 UNION ALL
    SELECT 'furniture','manufacturer','제조자/수입자','',60 UNION ALL SELECT 'furniture','country','제조국','',70 UNION ALL
    SELECT 'furniture','size','크기','',80 UNION ALL SELECT 'furniture','delivery','배송·설치 비용','',90 UNION ALL
    SELECT 'furniture','warranty','품질보증기준','',100 UNION ALL SELECT 'furniture','service','A/S 책임자와 전화번호','',110 UNION ALL
    SELECT 'other_goods','item_name','품명 및 모델명','',10 UNION ALL SELECT 'other_goods','certification','인증·허가 사항','해당되는 경우',20 UNION ALL
    SELECT 'other_goods','country','제조국 또는 원산지','',30 UNION ALL SELECT 'other_goods','manufacturer','제조자/수입자','',40 UNION ALL
    SELECT 'other_goods','service','A/S 책임자와 전화번호','',50
) f ON f.type_code = t.type_code
WHERE t.revision = '2023-01-01'
ON DUPLICATE KEY UPDATE label = VALUES(label), help_text = VALUES(help_text), sort_order = VALUES(sort_order);
