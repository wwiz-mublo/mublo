-- Shop Package - 상품정보제공고시 및 현행(2023-01-01 시행) 40개 품목

CREATE TABLE IF NOT EXISTS shop_product_notice_templates (
    template_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type_code VARCHAR(50) NOT NULL COMMENT '품목 코드',
    revision VARCHAR(20) NOT NULL COMMENT '고시 양식 버전',
    name VARCHAR(100) NOT NULL COMMENT '품목명',
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '고시 품목 순서',
    effective_from DATE NOT NULL COMMENT '시행일',
    source_title VARCHAR(255) NOT NULL DEFAULT '' COMMENT '근거 고시',
    is_current TINYINT(1) NOT NULL DEFAULT 0 COMMENT '신규 상품 기본 양식',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_notice_template_revision (type_code, revision),
    INDEX idx_notice_template_current (is_current, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품정보제공고시 품목 양식';

-- 개발 중 구 034를 실행한 DB에서 이력만 제거하고 다시 실행해도 기존 입력값을
-- 보존하면서 최종 스키마로 수렴한다. 신규 설치에서는 중복 컬럼 오류를 러너가 무시한다.
ALTER TABLE shop_product_notice_templates
    ADD COLUMN sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER name;

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
('clothing','2023-01-01','의류','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('shoes','2023-01-01','구두/신발','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('bag','2023-01-01','가방','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('fashion_accessory','2023-01-01','패션잡화(모자/벨트/액세서리 등)','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('bedding','2023-01-01','침구류/커튼','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('furniture','2023-01-01','가구(침대/소파/싱크대/DIY제품 등)','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('image_appliance','2023-01-01','영상가전(TV류)','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('home_appliance','2023-01-01','가정용 전기제품(냉장고/세탁기/식기세척기/전자레인지 등)','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('seasonal_appliance','2023-01-01','계절가전(에어컨/온풍기 등)','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('office_appliance','2023-01-01','사무용기기(컴퓨터/노트북/프린터 등)','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('optical_device','2023-01-01','광학기기(디지털카메라/캠코더 등)','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('small_electronics','2023-01-01','소형전자(MP3/전자사전 등)','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('portable_communication','2023-01-01','휴대형 통신기기(휴대폰/태블릿 등)','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('navigation','2023-01-01','내비게이션','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('car_accessory','2023-01-01','자동차용품(자동차부품/기타 자동차용품 등)','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('medical_device','2023-01-01','의료기기','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('kitchenware','2023-01-01','주방용품','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('cosmetics','2023-01-01','화장품','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('jewelry','2023-01-01','귀금속/보석/시계류','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('agricultural_food','2023-01-01','농수축산물','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('processed_food','2023-01-01','가공식품','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('health_functional_food','2023-01-01','건강기능식품','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('children_product','2023-01-01','어린이제품','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('musical_instrument','2023-01-01','악기','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('sports_goods','2023-01-01','스포츠용품','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('book','2023-01-01','서적','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('accommodation','2023-01-01','호텔/펜션 예약','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('travel_package','2023-01-01','여행패키지','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('airline_ticket','2023-01-01','항공권','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('car_rental','2023-01-01','자동차 대여 서비스(렌터카)','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('rental_appliance','2023-01-01','물품대여 서비스(정수기/비데/공기청정기 등)','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('rental_goods','2023-01-01','물품대여 서비스(서적/유아용품/행사용품 등)','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('digital_content','2023-01-01','디지털 콘텐츠(음원/게임/인터넷강의 등)','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('gift_card','2023-01-01','상품권/쿠폰','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('mobile_coupon','2023-01-01','모바일 쿠폰','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('movie_performance','2023-01-01','영화/공연','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('household_chemical','2023-01-01','생활화학제품','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('biocidal_product','2023-01-01','살생물제품','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('other_service','2023-01-01','기타 용역','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1),
('other_goods','2023-01-01','기타 재화','2023-01-01','전자상거래 등에서의 상품 등의 정보제공에 관한 고시(공정거래위원회고시 제2022-15호)',1)
ON DUPLICATE KEY UPDATE name=VALUES(name), source_title=VALUES(source_title), is_current=VALUES(is_current);

UPDATE shop_product_notice_templates SET sort_order = CASE type_code
WHEN 'clothing' THEN 1 WHEN 'shoes' THEN 2 WHEN 'bag' THEN 3 WHEN 'fashion_accessory' THEN 4
WHEN 'bedding' THEN 5 WHEN 'furniture' THEN 6 WHEN 'image_appliance' THEN 7 WHEN 'home_appliance' THEN 8
WHEN 'seasonal_appliance' THEN 9 WHEN 'office_appliance' THEN 10 WHEN 'optical_device' THEN 11 WHEN 'small_electronics' THEN 12
WHEN 'portable_communication' THEN 13 WHEN 'navigation' THEN 14 WHEN 'car_accessory' THEN 15 WHEN 'medical_device' THEN 16
WHEN 'kitchenware' THEN 17 WHEN 'cosmetics' THEN 18 WHEN 'jewelry' THEN 19 WHEN 'agricultural_food' THEN 20
WHEN 'processed_food' THEN 21 WHEN 'health_functional_food' THEN 22 WHEN 'children_product' THEN 23 WHEN 'musical_instrument' THEN 24
WHEN 'sports_goods' THEN 25 WHEN 'book' THEN 26 WHEN 'accommodation' THEN 27 WHEN 'travel_package' THEN 28
WHEN 'airline_ticket' THEN 29 WHEN 'car_rental' THEN 30 WHEN 'rental_appliance' THEN 31 WHEN 'rental_goods' THEN 32
WHEN 'digital_content' THEN 33 WHEN 'gift_card' THEN 34 WHEN 'mobile_coupon' THEN 35 WHEN 'movie_performance' THEN 36
WHEN 'household_chemical' THEN 37 WHEN 'biocidal_product' THEN 38 WHEN 'other_service' THEN 39 WHEN 'other_goods' THEN 40
ELSE sort_order END
WHERE revision='2023-01-01';

-- 아래 항목명은 현행 고시의 품목별 표를 그대로 화면에 제공한다.
INSERT INTO shop_product_notice_template_fields (template_id, field_code, label, help_text, sort_order)
SELECT t.template_id, f.field_code, f.label, '', f.sort_order
FROM shop_product_notice_templates t
JOIN (
SELECT 'bag' c,'item_type' field_code,'종류' label,10 sort_order UNION ALL SELECT 'bag','material','소재',20 UNION ALL SELECT 'bag','color','색상',30 UNION ALL SELECT 'bag','size','크기',40 UNION ALL SELECT 'bag','manufacturer','제조자/수입자',50 UNION ALL SELECT 'bag','country','제조국',60 UNION ALL SELECT 'bag','care','취급 시 주의사항',70 UNION ALL SELECT 'bag','warranty','품질보증기준',80 UNION ALL SELECT 'bag','service','A/S 책임자와 전화번호',90 UNION ALL
SELECT 'furniture','refurbished','재공급(리퍼브) 가구의 재공급 사유 및 하자 부위',90 UNION ALL
SELECT 'image_appliance','name_model','품명 및 모델명',10 UNION ALL SELECT 'image_appliance','kc','KC 인증정보',20 UNION ALL SELECT 'image_appliance','rated','정격전압 및 소비전력',30 UNION ALL SELECT 'image_appliance','energy','에너지소비효율등급',40 UNION ALL SELECT 'image_appliance','released','동일모델의 출시년월',50 UNION ALL SELECT 'image_appliance','manufacturer','제조자/수입자',60 UNION ALL SELECT 'image_appliance','country','제조국',70 UNION ALL SELECT 'image_appliance','size','크기 및 형태',80 UNION ALL SELECT 'image_appliance','screen','화면사양',90 UNION ALL SELECT 'image_appliance','install','추가설치비용',100 UNION ALL SELECT 'image_appliance','warranty','품질보증기준',110 UNION ALL SELECT 'image_appliance','service','A/S 책임자와 전화번호',120 UNION ALL
SELECT 'home_appliance','name_model','품명 및 모델명',10 UNION ALL SELECT 'home_appliance','kc','KC 인증정보',20 UNION ALL SELECT 'home_appliance','rated','정격전압 및 소비전력',30 UNION ALL SELECT 'home_appliance','energy','에너지소비효율등급',40 UNION ALL SELECT 'home_appliance','released','동일모델의 출시년월',50 UNION ALL SELECT 'home_appliance','manufacturer','제조자/수입자',60 UNION ALL SELECT 'home_appliance','country','제조국',70 UNION ALL SELECT 'home_appliance','size','크기/용량/형태',80 UNION ALL SELECT 'home_appliance','install','추가설치비용',90 UNION ALL SELECT 'home_appliance','warranty','품질보증기준',100 UNION ALL SELECT 'home_appliance','service','A/S 책임자와 전화번호',110 UNION ALL
SELECT 'seasonal_appliance','name_model','품명 및 모델명',10 UNION ALL SELECT 'seasonal_appliance','kc','KC 인증정보',20 UNION ALL SELECT 'seasonal_appliance','rated','정격전압 및 소비전력',30 UNION ALL SELECT 'seasonal_appliance','energy','에너지소비효율등급',40 UNION ALL SELECT 'seasonal_appliance','released','동일모델의 출시년월',50 UNION ALL SELECT 'seasonal_appliance','manufacturer','제조자/수입자',60 UNION ALL SELECT 'seasonal_appliance','country','제조국',70 UNION ALL SELECT 'seasonal_appliance','size','크기 및 형태(실외기 포함)',80 UNION ALL SELECT 'seasonal_appliance','cooling','냉난방면적',90 UNION ALL SELECT 'seasonal_appliance','install','추가설치비용',100 UNION ALL SELECT 'seasonal_appliance','warranty','품질보증기준',110 UNION ALL SELECT 'seasonal_appliance','service','A/S 책임자와 전화번호',120 UNION ALL
SELECT 'office_appliance','name_model','품명 및 모델명',10 UNION ALL SELECT 'office_appliance','kc','KC 인증정보',20 UNION ALL SELECT 'office_appliance','rated','정격전압 및 소비전력',30 UNION ALL SELECT 'office_appliance','energy','에너지소비효율등급',40 UNION ALL SELECT 'office_appliance','released','동일모델의 출시년월',50 UNION ALL SELECT 'office_appliance','manufacturer','제조자/수입자',60 UNION ALL SELECT 'office_appliance','country','제조국',70 UNION ALL SELECT 'office_appliance','size_weight','크기 및 무게',80 UNION ALL SELECT 'office_appliance','spec','주요 사양',90 UNION ALL SELECT 'office_appliance','warranty','품질보증기준',100 UNION ALL SELECT 'office_appliance','service','A/S 책임자와 전화번호',110 UNION ALL
SELECT 'optical_device','name_model','품명 및 모델명',10 UNION ALL SELECT 'optical_device','kc','KC 인증정보',20 UNION ALL SELECT 'optical_device','rated','정격전압 및 소비전력',30 UNION ALL SELECT 'optical_device','manufacturer','제조자/수입자',40 UNION ALL SELECT 'optical_device','country','제조국',50 UNION ALL SELECT 'optical_device','size_weight','크기 및 무게',60 UNION ALL SELECT 'optical_device','spec','주요 사양',70 UNION ALL SELECT 'optical_device','warranty','품질보증기준',80 UNION ALL SELECT 'optical_device','service','A/S 책임자와 전화번호',90 UNION ALL
SELECT 'small_electronics','name_model','품명 및 모델명',10 UNION ALL SELECT 'small_electronics','kc','KC 인증정보',20 UNION ALL SELECT 'small_electronics','rated','정격전압 및 소비전력',30 UNION ALL SELECT 'small_electronics','released','동일모델의 출시년월',40 UNION ALL SELECT 'small_electronics','manufacturer','제조자/수입자',50 UNION ALL SELECT 'small_electronics','country','제조국',60 UNION ALL SELECT 'small_electronics','size_weight','크기 및 무게',70 UNION ALL SELECT 'small_electronics','spec','주요 사양',80 UNION ALL SELECT 'small_electronics','warranty','품질보증기준',90 UNION ALL SELECT 'small_electronics','service','A/S 책임자와 전화번호',100 UNION ALL
SELECT 'portable_communication','name_model','품명 및 모델명',10 UNION ALL SELECT 'portable_communication','kc','KC 인증정보',20 UNION ALL SELECT 'portable_communication','released','동일모델의 출시년월',30 UNION ALL SELECT 'portable_communication','manufacturer','제조자/수입자',40 UNION ALL SELECT 'portable_communication','country','제조국',50 UNION ALL SELECT 'portable_communication','size_weight','크기 및 무게',60 UNION ALL SELECT 'portable_communication','carrier','이동통신 가입조건',70 UNION ALL SELECT 'portable_communication','spec','주요 사양',80 UNION ALL SELECT 'portable_communication','warranty','품질보증기준',90 UNION ALL SELECT 'portable_communication','service','A/S 책임자와 전화번호',100 UNION ALL
SELECT 'navigation','name_model','품명 및 모델명',10 UNION ALL SELECT 'navigation','kc','KC 인증정보',20 UNION ALL SELECT 'navigation','rated','정격전압 및 소비전력',30 UNION ALL SELECT 'navigation','released','동일모델의 출시년월',40 UNION ALL SELECT 'navigation','manufacturer','제조자/수입자',50 UNION ALL SELECT 'navigation','country','제조국',60 UNION ALL SELECT 'navigation','size_weight','크기 및 무게',70 UNION ALL SELECT 'navigation','spec','주요 사양',80 UNION ALL SELECT 'navigation','map_update','맵 업데이트 비용 및 무상기간',90 UNION ALL SELECT 'navigation','warranty','품질보증기준',100 UNION ALL SELECT 'navigation','service','A/S 책임자와 전화번호',110 UNION ALL
SELECT 'car_accessory','name_model','품명 및 모델명',10 UNION ALL SELECT 'car_accessory','released','동일모델의 출시년월',20 UNION ALL SELECT 'car_accessory','kc','KC 인증정보',30 UNION ALL SELECT 'car_accessory','manufacturer','제조자/수입자',40 UNION ALL SELECT 'car_accessory','country','제조국',50 UNION ALL SELECT 'car_accessory','size','크기',60 UNION ALL SELECT 'car_accessory','vehicle','적용 차종',70 UNION ALL SELECT 'car_accessory','care','제품사용으로 인한 위험 및 유의사항',80 UNION ALL SELECT 'car_accessory','warranty','품질보증기준',90 UNION ALL SELECT 'car_accessory','inspection','검사합격증 번호',100 UNION ALL SELECT 'car_accessory','service','A/S 책임자와 전화번호',110 UNION ALL
SELECT 'medical_device','name_model','품명 및 모델명',10 UNION ALL SELECT 'medical_device','license','허가·인증·신고번호',20 UNION ALL SELECT 'medical_device','rated','정격전압 및 소비전력',30 UNION ALL SELECT 'medical_device','released','동일모델의 출시년월',40 UNION ALL SELECT 'medical_device','manufacturer','제조자/수입자',50 UNION ALL SELECT 'medical_device','country','제조국',60 UNION ALL SELECT 'medical_device','purpose','제품의 사용목적 및 사용방법',70 UNION ALL SELECT 'medical_device','care','취급 시 주의사항',80 UNION ALL SELECT 'medical_device','warranty','품질보증기준',90 UNION ALL SELECT 'medical_device','service','A/S 책임자와 전화번호',100 UNION ALL
SELECT 'kitchenware','name_model','품명 및 모델명',10 UNION ALL SELECT 'kitchenware','material','재질',20 UNION ALL SELECT 'kitchenware','components','구성품',30 UNION ALL SELECT 'kitchenware','size','크기',40 UNION ALL SELECT 'kitchenware','released','동일모델의 출시년월',50 UNION ALL SELECT 'kitchenware','manufacturer','제조자/수입자',60 UNION ALL SELECT 'kitchenware','country','제조국',70 UNION ALL SELECT 'kitchenware','import_notice','수입 기구·용기의 수입신고 여부',80 UNION ALL SELECT 'kitchenware','warranty','품질보증기준',90 UNION ALL SELECT 'kitchenware','service','A/S 책임자와 전화번호',100 UNION ALL
SELECT 'cosmetics','capacity','내용물의 용량 또는 중량',10 UNION ALL SELECT 'cosmetics','spec','제품 주요 사양',20 UNION ALL SELECT 'cosmetics','expiry','사용기한 또는 개봉 후 사용기간',30 UNION ALL SELECT 'cosmetics','usage','사용방법',40 UNION ALL SELECT 'cosmetics','business','화장품제조업자/책임판매업자/맞춤형화장품판매업자',50 UNION ALL SELECT 'cosmetics','country','제조국',60 UNION ALL SELECT 'cosmetics','ingredients','화장품법에 따라 기재·표시하여야 하는 모든 성분',70 UNION ALL SELECT 'cosmetics','functional','기능성 화장품 심사 여부',80 UNION ALL SELECT 'cosmetics','care','사용할 때의 주의사항',90 UNION ALL SELECT 'cosmetics','warranty','품질보증기준',100 UNION ALL SELECT 'cosmetics','service','소비자 상담 관련 전화번호',110 UNION ALL
SELECT 'jewelry','material','소재/순도/밴드재질',10 UNION ALL SELECT 'jewelry','weight','중량',20 UNION ALL SELECT 'jewelry','manufacturer','제조자/수입자',30 UNION ALL SELECT 'jewelry','country','제조국',40 UNION ALL SELECT 'jewelry','size','치수',50 UNION ALL SELECT 'jewelry','care','착용 시 주의사항',60 UNION ALL SELECT 'jewelry','spec','주요 사양',70 UNION ALL SELECT 'jewelry','warranty_service','보증서 제공 여부',80 UNION ALL SELECT 'jewelry','warranty','품질보증기준',90 UNION ALL SELECT 'jewelry','service','A/S 책임자와 전화번호',100 UNION ALL
SELECT 'agricultural_food','name','품목 또는 명칭',10 UNION ALL SELECT 'agricultural_food','capacity','포장단위별 내용물의 용량/수량/크기',20 UNION ALL SELECT 'agricultural_food','producer','생산자/수입자',30 UNION ALL SELECT 'agricultural_food','origin','원산지',40 UNION ALL SELECT 'agricultural_food','manufactured','제조연월일/소비기한/품질유지기한',50 UNION ALL SELECT 'agricultural_food','grade','세부 품목군별 표시사항',60 UNION ALL SELECT 'agricultural_food','components','상품구성',70 UNION ALL SELECT 'agricultural_food','storage','보관방법 또는 취급방법',80 UNION ALL SELECT 'agricultural_food','care','소비자 안전을 위한 주의사항',90 UNION ALL SELECT 'agricultural_food','service','소비자 상담 관련 전화번호',100 UNION ALL
SELECT 'processed_food','labeling','식품표시광고법에 따른 표시사항',10 UNION ALL SELECT 'processed_food','import_notice','수입식품의 수입신고 여부',20 UNION ALL SELECT 'processed_food','service','소비자 상담 관련 전화번호',30 UNION ALL
SELECT 'health_functional_food','labeling','식품표시광고법 및 건강기능식품법에 따른 표시사항',10 UNION ALL SELECT 'health_functional_food','genetic','유전자변형건강기능식품 표시',20 UNION ALL SELECT 'health_functional_food','import_notice','수입 건강기능식품의 수입신고 여부',30 UNION ALL SELECT 'health_functional_food','service','소비자 상담 관련 전화번호',40 UNION ALL
SELECT 'children_product','name_model','품명 및 모델명',10 UNION ALL SELECT 'children_product','kc','KC 인증정보',20 UNION ALL SELECT 'children_product','size_weight','크기/중량',30 UNION ALL SELECT 'children_product','color','색상',40 UNION ALL SELECT 'children_product','material','재질',50 UNION ALL SELECT 'children_product','age','사용연령 또는 권장사용연령',60 UNION ALL SELECT 'children_product','limit','크기·체중의 한계',70 UNION ALL SELECT 'children_product','released','동일모델의 출시년월',80 UNION ALL SELECT 'children_product','manufacturer','제조자/수입자',90 UNION ALL SELECT 'children_product','country','제조국',100 UNION ALL SELECT 'children_product','care','취급방법 및 취급 시 주의사항/안전표시',110 UNION ALL SELECT 'children_product','warranty','품질보증기준',120 UNION ALL SELECT 'children_product','service','A/S 책임자와 전화번호',130 UNION ALL
SELECT 'musical_instrument','name_model','품명 및 모델명',10 UNION ALL SELECT 'musical_instrument','size','크기',20 UNION ALL SELECT 'musical_instrument','color','색상',30 UNION ALL SELECT 'musical_instrument','material','재질',40 UNION ALL SELECT 'musical_instrument','components','제품 구성',50 UNION ALL SELECT 'musical_instrument','released','동일모델의 출시년월',60 UNION ALL SELECT 'musical_instrument','manufacturer','제조자/수입자',70 UNION ALL SELECT 'musical_instrument','country','제조국',80 UNION ALL SELECT 'musical_instrument','spec','상품별 세부 사양',90 UNION ALL SELECT 'musical_instrument','warranty','품질보증기준',100 UNION ALL SELECT 'musical_instrument','service','A/S 책임자와 전화번호',110 UNION ALL
SELECT 'sports_goods','name_model','품명 및 모델명',10 UNION ALL SELECT 'sports_goods','kc','KC 인증정보',20 UNION ALL SELECT 'sports_goods','size_weight','크기 및 중량',30 UNION ALL SELECT 'sports_goods','color','색상',40 UNION ALL SELECT 'sports_goods','material','재질',50 UNION ALL SELECT 'sports_goods','components','제품 구성',60 UNION ALL SELECT 'sports_goods','released','동일모델의 출시년월',70 UNION ALL SELECT 'sports_goods','manufacturer','제조자/수입자',80 UNION ALL SELECT 'sports_goods','country','제조국',90 UNION ALL SELECT 'sports_goods','spec','상품별 세부 사양',100 UNION ALL SELECT 'sports_goods','warranty','품질보증기준',110 UNION ALL SELECT 'sports_goods','service','A/S 책임자와 전화번호',120 UNION ALL
SELECT 'book','title','도서명',10 UNION ALL SELECT 'book','author','저자/출판사',20 UNION ALL SELECT 'book','size','크기',30 UNION ALL SELECT 'book','pages','쪽수',40 UNION ALL SELECT 'book','components','제품 구성',50 UNION ALL SELECT 'book','published','발행일',60 UNION ALL SELECT 'book','summary','목차 또는 책 소개',70 UNION ALL
SELECT 'accommodation','country','국가 또는 지역명',10 UNION ALL SELECT 'accommodation','grade','숙소 형태',20 UNION ALL SELECT 'accommodation','room','등급/객실 타입',30 UNION ALL SELECT 'accommodation','cost','사용가능 인원 및 인원 추가 비용',40 UNION ALL SELECT 'accommodation','facilities','부대시설/제공 서비스',50 UNION ALL SELECT 'accommodation','cancel','취소 규정',60 UNION ALL SELECT 'accommodation','contact','예약 담당 연락처',70 UNION ALL
SELECT 'travel_package','agency','여행사',10 UNION ALL SELECT 'travel_package','certificate','이용항공편',20 UNION ALL SELECT 'travel_package','period','여행기간 및 일정',30 UNION ALL SELECT 'travel_package','minimum','총 예정 인원/출발 가능 인원',40 UNION ALL SELECT 'travel_package','hotel','숙박정보',50 UNION ALL SELECT 'travel_package','price','여행상품 가격',60 UNION ALL SELECT 'travel_package','optional','선택경비 유무 및 세부 내용',70 UNION ALL SELECT 'travel_package','cancel','취소 규정',80 UNION ALL SELECT 'travel_package','warning','해외여행 시 외교부 지정 여행경보단계',90 UNION ALL SELECT 'travel_package','contact','예약 담당 연락처',100 UNION ALL
SELECT 'airline_ticket','round_trip','왕복/편도 여부',10 UNION ALL SELECT 'airline_ticket','validity','유효기간',20 UNION ALL SELECT 'airline_ticket','restriction','제한사항',30 UNION ALL SELECT 'airline_ticket','ticketing','티켓수령방법',40 UNION ALL SELECT 'airline_ticket','seat','좌석종류',50 UNION ALL SELECT 'airline_ticket','excluded','가격에 포함되지 않은 내용 및 금액',60 UNION ALL SELECT 'airline_ticket','cancel','취소 규정',70 UNION ALL SELECT 'airline_ticket','contact','예약 담당 연락처',80 UNION ALL
SELECT 'car_rental','vehicle','차종',10 UNION ALL SELECT 'car_rental','ownership','소유권 이전 조건',20 UNION ALL SELECT 'car_rental','option_cost','추가 선택 시 비용',30 UNION ALL SELECT 'car_rental','contact','차량 반환 시 연락처',40 UNION ALL SELECT 'car_rental','breakdown','고장·훼손 시 소비자 책임',50 UNION ALL SELECT 'car_rental','cancel','예약 취소 또는 중도 해약 시 환불 기준',60 UNION ALL SELECT 'car_rental','service','소비자 상담 관련 전화번호',70 UNION ALL
SELECT 'rental_appliance','name_model','품명 및 모델명',10 UNION ALL SELECT 'rental_appliance','ownership','소유권 이전 조건',20 UNION ALL SELECT 'rental_appliance','maintenance','유지보수 조건',30 UNION ALL SELECT 'rental_appliance','damage','고장·분실·훼손 시 소비자 책임',40 UNION ALL SELECT 'rental_appliance','cancel','중도 해약 시 환불 기준',50 UNION ALL SELECT 'rental_appliance','spec','제품 사양',60 UNION ALL SELECT 'rental_appliance','service','소비자 상담 관련 전화번호',70 UNION ALL
SELECT 'rental_goods','name_model','품명 및 모델명',10 UNION ALL SELECT 'rental_goods','ownership','소유권 이전 조건',20 UNION ALL SELECT 'rental_goods','damage','상품의 고장·분실·훼손 시 소비자 책임',30 UNION ALL SELECT 'rental_goods','cancel','중도 해약 시 환불 기준',40 UNION ALL SELECT 'rental_goods','service','소비자 상담 관련 전화번호',50 UNION ALL
SELECT 'digital_content','provider','제작자 또는 공급자',10 UNION ALL SELECT 'digital_content','conditions','이용조건/이용기간',20 UNION ALL SELECT 'digital_content','method','상품 제공 방식',30 UNION ALL SELECT 'digital_content','requirements','최소 시스템 사양/필수 소프트웨어',40 UNION ALL SELECT 'digital_content','withdrawal','청약철회 또는 계약 해제·해지에 따른 효과',50 UNION ALL SELECT 'digital_content','service','소비자 상담 관련 전화번호',60 UNION ALL
SELECT 'gift_card','issuer','발행자',10 UNION ALL SELECT 'gift_card','conditions','유효기간/이용조건',20 UNION ALL SELECT 'gift_card','stores','이용 가능 매장',30 UNION ALL SELECT 'gift_card','refund','잔액 환급 조건',40 UNION ALL SELECT 'gift_card','service','소비자 상담 관련 전화번호',50 UNION ALL
SELECT 'mobile_coupon','issuer','발행자',10 UNION ALL SELECT 'mobile_coupon','conditions','유효기간/이용조건',20 UNION ALL SELECT 'mobile_coupon','stores','이용 가능 매장',30 UNION ALL SELECT 'mobile_coupon','refund','환불조건 및 방법',40 UNION ALL SELECT 'mobile_coupon','service','소비자 상담 관련 전화번호',50 UNION ALL
SELECT 'movie_performance','organizer','주최 또는 기획',10 UNION ALL SELECT 'movie_performance','cast','주연',20 UNION ALL SELECT 'movie_performance','rating','관람등급',30 UNION ALL SELECT 'movie_performance','duration','상영·공연시간',40 UNION ALL SELECT 'movie_performance','venue','상영·공연장소',50 UNION ALL SELECT 'movie_performance','cancel_time','예매 취소 조건',60 UNION ALL SELECT 'movie_performance','refund','취소·환불방법',70 UNION ALL SELECT 'movie_performance','service','소비자 상담 관련 전화번호',80 UNION ALL
SELECT 'household_chemical','labeling','화학제품안전법 및 안전확인대상생활화학제품 표시기준에 따른 표시사항',10 UNION ALL SELECT 'household_chemical','service','소비자 상담 관련 전화번호',20 UNION ALL
SELECT 'biocidal_product','labeling','살생물제법 및 살생물제품 표시 규정에 따른 표시사항',10 UNION ALL SELECT 'biocidal_product','service','소비자 상담 관련 전화번호',20 UNION ALL
SELECT 'other_service','provider','서비스 제공 사업자',10 UNION ALL SELECT 'other_service','license','법에 의한 인증·허가 사항',20 UNION ALL SELECT 'other_service','conditions','이용조건',30 UNION ALL SELECT 'other_service','cancel','취소·중도해약·해지 조건 및 환불기준',40 UNION ALL SELECT 'other_service','refund','취소·환불방법',50 UNION ALL SELECT 'other_service','service','소비자 상담 관련 전화번호',60
) f ON f.c=t.type_code
WHERE t.revision='2023-01-01'
ON DUPLICATE KEY UPDATE label=VALUES(label), sort_order=VALUES(sort_order);
