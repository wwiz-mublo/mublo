<?php

namespace Tests\Shop\Integration;

use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Tests\Integration\DatabaseTestCase;

/**
 * 상품 옵션 생성을 실 DB 로 검증한다.
 *
 * 세 메서드 모두 소유 ID 를 첫 인자로 받고 배열에는 넣지 않는다. 호출부가 이
 * 규칙을 어기면 인자 하나가 통째로 밀린다. 저장된 컬럼까지 확인해야 같은 종류가
 * 다시 통과하지 않는다.
 */
class ProductOptionRepositoryTest extends DatabaseTestCase
{
    private ProductOptionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('shop_product_options', '
            option_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            goods_id BIGINT UNSIGNED NOT NULL,
            option_name VARCHAR(50) NOT NULL,
            option_type VARCHAR(10) NOT NULL DEFAULT "BASIC",
            is_required TINYINT(1) NOT NULL DEFAULT 1,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        ');

        $this->createTable('shop_product_option_values', '
            value_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            option_id BIGINT UNSIGNED NOT NULL,
            value_name VARCHAR(50) NOT NULL,
            extra_price INT NOT NULL DEFAULT 0,
            stock_quantity INT NULL DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        ');

        $this->createTable('shop_product_option_combos', '
            combo_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            goods_id BIGINT UNSIGNED NOT NULL,
            combination_key VARCHAR(255) NOT NULL,
            extra_price INT NOT NULL DEFAULT 0,
            stock_quantity INT NULL DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        ');

        $this->repository = new ProductOptionRepository($this->db);
    }

    public function testCreateOptionStoresTheGoodsIdFromTheFirstArgument(): void
    {
        $optionId = $this->repository->createOption(77, [
            'option_name' => '색상',
            'option_type' => 'BASIC',
            'is_required' => 1,
            'sort_order' => 3,
        ]);

        $this->assertGreaterThan(0, $optionId);
        $this->assertSame(
            [['goods_id' => 77, 'option_name' => '색상', 'sort_order' => 3]],
            $this->fetchAll('SELECT goods_id, option_name, sort_order FROM shop_product_options')
        );
    }

    public function testCreateValueStoresTheOptionIdFromTheFirstArgument(): void
    {
        $optionId = $this->repository->createOption(77, ['option_name' => '색상']);

        $valueId = $this->repository->createValue($optionId, [
            'value_name' => '빨강',
            'extra_price' => 500,
            'stock_quantity' => 0,
            'is_active' => 1,
            'sort_order' => 1,
        ]);

        $this->assertGreaterThan(0, $valueId);
        $this->assertSame(
            [['option_id' => $optionId, 'value_name' => '빨강', 'extra_price' => 500]],
            $this->fetchAll('SELECT option_id, value_name, extra_price FROM shop_product_option_values')
        );
    }

    public function testCreateComboStoresTheGoodsIdFromTheFirstArgument(): void
    {
        $comboId = $this->repository->createCombo(77, [
            'combination_key' => '빨강/XL',
            'extra_price' => 0,
            'stock_quantity' => 0,
            'is_active' => 1,
        ]);

        $this->assertGreaterThan(0, $comboId);
        $this->assertSame(
            [['goods_id' => 77, 'combination_key' => '빨강/XL']],
            $this->fetchAll('SELECT goods_id, combination_key FROM shop_product_option_combos')
        );
    }
}
