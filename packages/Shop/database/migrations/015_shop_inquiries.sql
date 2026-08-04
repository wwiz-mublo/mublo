-- ====================================
-- Shop Package - 상품 문의
-- ====================================

CREATE TABLE IF NOT EXISTS shop_inquiries (
    inquiry_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '문의 ID',
    domain_id BIGINT UNSIGNED NOT NULL COMMENT '도메인 ID',
    goods_id BIGINT UNSIGNED NOT NULL COMMENT '상품 ID',
    member_id BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '회원 ID (0=비회원)',

    -- 문의 내용
    inquiry_type ENUM('PRODUCT', 'STOCK', 'DELIVERY', 'OTHER') NOT NULL DEFAULT 'PRODUCT' COMMENT '문의 유형',
    title VARCHAR(100) NOT NULL COMMENT '제목',
    content TEXT NOT NULL COMMENT '내용',
    is_secret TINYINT(1) NOT NULL DEFAULT 0 COMMENT '비밀글 여부',

    -- 답변
    reply TEXT NULL COMMENT '답변 내용',
    reply_staff_id BIGINT UNSIGNED NULL COMMENT '답변 담당자 ID',
    replied_at DATETIME NULL COMMENT '답변일',

    -- 상태
    inquiry_status ENUM('WAITING', 'REPLIED', 'CLOSED') NOT NULL DEFAULT 'WAITING' COMMENT '상태',
    is_visible TINYINT(1) NOT NULL DEFAULT 1 COMMENT '노출 여부',
    author_name VARCHAR(50) NULL COMMENT '작성자명',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '수정일',

    INDEX idx_domain (domain_id),
    INDEX idx_goods (goods_id),
    INDEX idx_member (member_id),
    INDEX idx_status (inquiry_status),
    FOREIGN KEY (domain_id) REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
    FOREIGN KEY (goods_id) REFERENCES shop_products(goods_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상품 문의';
