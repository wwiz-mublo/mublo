<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Core\Event\EventDispatcher;
use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\Shop\Entity\Product;
use Mublo\Packages\Shop\Event\ProductChangedEvent;
use Mublo\Packages\Shop\Repository\CategoryRepository;
use Mublo\Packages\Shop\Repository\ProductOptionRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Service\PriceCalculator;
use Mublo\Packages\Shop\Service\ProductService;
use PHPUnit\Framework\TestCase;

/**
 * 커밋 이후 이벤트 실패가 업무 실패로 둔갑하지 않는지 (회귀)
 *
 * 예전에는 commit() 과 dispatch() 가 한 try 안에 있어서, 리스너가 터지면 catch 가
 * 이미 커밋된 트랜잭션에 rollBack() 을 걸었다. PDO 는 활성 트랜잭션이 없으면
 * "There is no active transaction" 을 던지므로 catch 안에서 다시 예외가 나 500 이 됐다.
 * 상품은 저장돼 있는데 화면은 실패였다.
 */
class ProductServicePostCommitEventTest extends TestCase
{
    public function testDeleteSucceedsWhenListenerThrows(): void
    {
        $dispatcher = new EventDispatcher(rethrowListenerFailures: true); // 개발 환경 설정
        $dispatcher->addListener(ProductChangedEvent::class, function (): void {
            throw new \RuntimeException('캐시 무효화 실패');
        });

        [$service, $products] = $this->service($dispatcher);
        $products->method('findInDomain')->with(10, 7)->willReturn($this->product());

        $result = $service->delete(7, 10);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('상품이 삭제되었습니다.', $result->getMessage());
    }

    /**
     * 운영 환경(rethrowListenerFailures=false)도 안전하지 않다 —
     * EventDispatcher 는 \Error 를 환경과 무관하게 재throw 한다.
     */
    public function testDeleteSucceedsWhenListenerRaisesErrorInProductionMode(): void
    {
        $dispatcher = new EventDispatcher(rethrowListenerFailures: false); // 운영 환경 설정
        $dispatcher->addListener(ProductChangedEvent::class, function (): void {
            throw new \TypeError('리스너 타입 오류');
        });

        [$service, $products] = $this->service($dispatcher);
        $products->method('findInDomain')->with(10, 7)->willReturn($this->product());

        $result = $service->delete(7, 10);

        $this->assertTrue($result->isSuccess());
    }

    public function testUpdateSucceedsWhenListenerThrows(): void
    {
        $dispatcher = new EventDispatcher(rethrowListenerFailures: true);
        $dispatcher->addListener(ProductChangedEvent::class, function (): void {
            throw new \RuntimeException('캐시 무효화 실패');
        });

        [$service, $products] = $this->service($dispatcher);
        $products->method('findInDomain')->with(10, 7)->willReturn($this->product());

        $result = $service->update(7, ['goods_name' => 'Changed'], 10);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('상품이 수정되었습니다.', $result->getMessage());
    }

    /**
     * 트랜잭션 실패는 여전히 롤백 + 실패 반환이어야 한다 (가드가 롤백을 삼키면 안 됨)
     */
    public function testDeleteRollsBackAndFailsWhenTransactionThrows(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('beginTransaction')->willReturn(true);
        $db->method('inTransaction')->willReturn(true); // 실패 시점엔 트랜잭션이 열려 있다
        $db->expects($this->once())->method('rollBack')->willReturn(true);
        $db->expects($this->never())->method('commit');

        $products = $this->createMock(ProductRepository::class);
        $products->method('getDb')->willReturn($db);
        $products->method('findInDomain')->with(10, 7)->willReturn($this->product());

        $options = $this->createMock(ProductOptionRepository::class);
        $options->method('deleteProductOptions')->willThrowException(new \RuntimeException('DB 오류'));

        $service = new ProductService(
            $products,
            $options,
            $this->createMock(CategoryRepository::class),
            $this->createMock(PriceCalculator::class)
        );

        $result = $service->delete(7, 10);

        $this->assertTrue($result->isFailure());
        $this->assertSame('상품 삭제 중 오류가 발생했습니다.', $result->getMessage());
    }

    private function product(): Product
    {
        return Product::fromArray([
            'goods_id' => 7,
            'domain_id' => 10,
            'goods_name' => '테스트 상품',
            'created_at' => '2026-01-01 00:00:00',
        ]);
    }

    /**
     * @return array{ProductService, ProductRepository, ProductOptionRepository, Database}
     */
    private function service(EventDispatcher $dispatcher): array
    {
        $db = $this->createMock(Database::class);
        $db->method('beginTransaction')->willReturn(true);
        $db->method('commit')->willReturn(true);
        // 커밋 이후에는 열린 트랜잭션이 없다 — 실 PDO 와 동일하게 롤백 대상이 없는 상태로 두고,
        // rollBack() 도 PDO 와 같이 예외를 던지게 해 "catch 안에서 다시 터지는" 경로를 재현한다.
        $db->method('inTransaction')->willReturn(false);
        $db->method('rollBack')->willThrowException(new \PDOException('There is no active transaction'));

        $products = $this->createMock(ProductRepository::class);
        $products->method('getDb')->willReturn($db);

        $options = $this->createMock(ProductOptionRepository::class);
        $categories = $this->createMock(CategoryRepository::class);
        $prices = $this->createMock(PriceCalculator::class);

        return [
            new ProductService($products, $options, $categories, $prices, $dispatcher),
            $products,
            $options,
            $db,
        ];
    }
}
