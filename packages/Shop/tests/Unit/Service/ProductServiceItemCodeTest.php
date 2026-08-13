<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Packages\Shop\Repository\CategoryRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Service\ProductService;
use PHPUnit\Framework\TestCase;

/**
 * 상품코드(item_code) 생성 규칙
 *
 * item_code 는 도메인 무관 전역 유니크라, 충돌하면 상품 생성이 통째로 실패하고
 * 원인을 알 수 없는 메시지만 남는다. 그날의 마지막 번호를 이어 가는지,
 * 이미 쓰인 번호를 비켜 가는지 본다.
 */
class ProductServiceItemCodeTest extends TestCase
{
    private function service(ProductRepository $products): ProductService
    {
        return new ProductService(
            $products,
            $this->createMock(ProductOptionRepository::class),
            $this->createMock(CategoryRepository::class),
            new PriceCalculator()
        );
    }

    private function todayPrefix(): string
    {
        return 'G-' . date('Ymd') . '-';
    }

    public function testFirstCodeOfTheDayStartsAtOne(): void
    {
        $products = $this->createMock(ProductRepository::class);
        $products->method('maxItemCodeWithPrefix')->willReturn(null);
        $products->method('itemCodeExists')->willReturn(false);

        $this->assertSame($this->todayPrefix() . '0001', $this->service($products)->generateItemCode());
    }

    public function testContinuesFromLastSequenceOfTheDay(): void
    {
        $products = $this->createMock(ProductRepository::class);
        $products->method('maxItemCodeWithPrefix')->willReturn($this->todayPrefix() . '0041');
        $products->method('itemCodeExists')->willReturn(false);

        $this->assertSame($this->todayPrefix() . '0042', $this->service($products)->generateItemCode());
    }

    public function testSkipsCodesThatAreAlreadyTaken(): void
    {
        $prefix = $this->todayPrefix();

        $products = $this->createMock(ProductRepository::class);
        $products->method('maxItemCodeWithPrefix')->willReturn($prefix . '0007');
        // 0008·0009 는 이미 쓰였다고 답한다
        $products->method('itemCodeExists')->willReturnCallback(
            fn(string $code): bool => in_array($code, [$prefix . '0008', $prefix . '0009'], true)
        );

        $this->assertSame($prefix . '0010', $this->service($products)->generateItemCode());
    }

    public function testFallsBackToOneWhenLastCodeIsNotNumeric(): void
    {
        // 옛 난수 코드나 손으로 넣은 코드가 섞여 꼬리를 숫자로 읽을 수 없는 경우
        $products = $this->createMock(ProductRepository::class);
        $products->method('maxItemCodeWithPrefix')->willReturn($this->todayPrefix() . 'ABCD');
        $products->method('itemCodeExists')->willReturn(false);

        $this->assertSame($this->todayPrefix() . '0001', $this->service($products)->generateItemCode());
    }

    public function testSequenceGrowsBeyondFourDigits(): void
    {
        $products = $this->createMock(ProductRepository::class);
        $products->method('maxItemCodeWithPrefix')->willReturn($this->todayPrefix() . '9999');
        $products->method('itemCodeExists')->willReturn(false);

        $this->assertSame($this->todayPrefix() . '10000', $this->service($products)->generateItemCode());
    }

    // =========================================================
    // 직접 입력한 상품코드의 중복 검사
    // =========================================================

    public function testCreateRejectsDuplicateItemCodeWithAClearMessage(): void
    {
        // 다른 상품(도메인 무관)이 이미 쓰는 코드
        $products = $this->createMock(ProductRepository::class);
        $products->method('findGoodsIdByItemCode')->with('SHIRT-001')->willReturn(42);
        // 저장 단계까지 가지 않아야 한다 — 유니크 위반으로 롤백되면 원인이 안 보인다
        $products->expects($this->never())->method('create');

        $result = $this->service($products)->create(1, [
            'goods_name' => '셔츠',
            'display_price' => 10000,
            'item_code' => 'SHIRT-001',
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('이미 사용 중인 상품코드', $result->getMessage());
        // 어느 도메인이 쓰는지는 알려주지 않는다
        $this->assertStringNotContainsString('도메인', $result->getMessage());
    }

    public function testCreateAcceptsUnusedItemCode(): void
    {
        $products = $this->createMock(ProductRepository::class);
        $products->method('findGoodsIdByItemCode')->willReturn(null);
        $products->method('create')->willReturn(0); // 이후 단계는 관심 밖

        $result = $this->service($products)->create(1, [
            'goods_name' => '셔츠',
            'display_price' => 10000,
            'item_code' => 'SHIRT-002',
        ]);

        $this->assertStringNotContainsString('상품코드', $result->getMessage());
    }

    public function testUpdateAllowsKeepingOwnItemCode(): void
    {
        // 자기 자신이 쓰던 코드를 그대로 두고 저장하는 것은 충돌이 아니다
        $products = $this->createMock(ProductRepository::class);
        $products->method('findInDomain')->willReturn(
            \Mublo\Packages\Shop\Entity\Product::fromArray(['goods_id' => 42, 'domain_id' => 1])
        );
        $products->method('findGoodsIdByItemCode')->with('SHIRT-001')->willReturn(42);

        $result = $this->service($products)->update(42, ['item_code' => 'SHIRT-001'], 1);

        $this->assertStringNotContainsString('이미 사용 중인 상품코드', $result->getMessage());
    }

    public function testUpdateRejectsItemCodeOwnedByAnotherProduct(): void
    {
        $products = $this->createMock(ProductRepository::class);
        $products->method('findInDomain')->willReturn(
            \Mublo\Packages\Shop\Entity\Product::fromArray(['goods_id' => 42, 'domain_id' => 1])
        );
        $products->method('findGoodsIdByItemCode')->with('SHIRT-001')->willReturn(7);
        $products->expects($this->never())->method('updateInDomain');

        $result = $this->service($products)->update(42, ['item_code' => 'SHIRT-001'], 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('이미 사용 중인 상품코드', $result->getMessage());
    }

    public function testBlankItemCodeIsNotTreatedAsDuplicate(): void
    {
        // 비워 두면 자동 생성 대상이므로 중복 검사를 하지 않는다
        $products = $this->createMock(ProductRepository::class);
        $products->expects($this->never())->method('findGoodsIdByItemCode');
        $products->method('findInDomain')->willReturn(
            \Mublo\Packages\Shop\Entity\Product::fromArray(['goods_id' => 42, 'domain_id' => 1])
        );

        $this->service($products)->update(42, ['item_code' => '   '], 1);
    }
}
