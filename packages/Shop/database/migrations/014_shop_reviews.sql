-- ====================================
-- Shop Package - 상품 후기
-- ====================================

CREATE TABLE IF NOT EXISTS shop_reviews (
    review_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '후기 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    goods_id BIGINT UNSIGNED NOT NULL COMMENT '상품 ID',
    order_no VARCHAR(20) NOT NULL COMMENT '주문번호',
    order_detail_id BIGINT UNSIGNED NOT NULL COMMENT '주문 상세 ID',
    member_id BIGINT UNSIGNED NOT NULL COMMENT '회원 ID',

    -- 후기 내용
    review_type ENUM('TEXT', 'PHOTO') NOT NULL DEFAULT 'TEXT' COMMENT '후기 유형',
    rating TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '평점 (1~5)',
    content TEXT NOT NULL COMMENT '후기 내용',

    -- 이미지
    image1 VARCHAR(255) NULL COMMENT '이미지 1',
    image2 VARCHAR(255) NULL COMMENT '이미지 2',
    image3 VARCHAR(255) NULL COMMENT '이미지 3',

    -- 적립금
    point_amount INT NOT NULL DEFAULT 0 COMMENT '적립금',
    point_issued TINYINT(1) NOT NULL DEFAULT 0 COMMENT '적립금 지급 여부',

    -- 관리
    is_best TINYINT(1) NOT NULL DEFAULT 0 COMMENT '베스트 후기',
    is_visible TINYINT(1) NOT NULL DEFAULT 1 COMMENT '노출 여부',
    admin_reply TEXT NULL COMMENT '관리자 답변',
    admin_reply_at DATETIME NULL COMMENT '관리자 답변일',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    UNIQUE KEY uk_order_detail (order_detail_id),
    INDEX idx_domain (domain_id),
    INDEX idx_goods (goods_id),
    INDEX idx_member (member_id),
    INDEX idx_rating (rating),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
    FOREIGN KEY (goods_id) REFERENCES shop_products(goods_id) ON DELETE CASCADE,
    FOREIGN KEY (order_detail_id) REFERENCES shop_order_details(order_detail_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품 후기';
