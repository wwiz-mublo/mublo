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
}
