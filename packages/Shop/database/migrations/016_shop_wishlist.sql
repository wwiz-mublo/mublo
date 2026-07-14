-- ====================================
-- Shop Package - 위시리스트
-- ====================================

CREATE TABLE IF NOT EXISTS shop_wishlist (
    wishlist_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY COMMENT '위시리스트 ID',
    member_id BIGINT UNSIGNED NOT NULL COMMENT '회원 ID',
    goods_id BIGINT UNSIGNED NOT NULL COMMENT '상품 ID',

    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '생성일',

    UNIQUE KEY uk_member_goods (member_id, goods_id),
    INDEX idx_member (member_id),
    INDEX idx_goods (goods_id),
    FOREIGN KEY (goods_id) REFERENCES shop_products(goods_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='위시리스트';
