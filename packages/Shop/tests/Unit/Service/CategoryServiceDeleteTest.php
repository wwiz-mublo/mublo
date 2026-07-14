<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Packages\Shop\Repository\CategoryRepository;
use Mublo\Packages\Shop\Service\CategoryService;
use PHPUnit\Framework\TestCase;

/**
 * 카테고리 삭제 가드
 *
 * deleteItem() 은 트리 사용 여부와 상품 연결을 확인한 뒤에만 삭제한다.
 * 두 확인 모두 존재하지 않는 QueryBuilder::selectRaw() 를 호출하고 있어서,
 * 관리자가 카테고리 삭제를 시도하면 가드 단계에서 fatal 이 났다(삭제는 실행되지 않음).
 * 이 테스트는 가드가 실제로 동작하고 순서가 유지되는지 고정한다.
 */
class CategoryServiceDeleteTest extends TestCase
{
    public function testUpdateRejectsCategoryOutsideCurrentDomain(): void
    {
        $repository = $this->createMock(CategoryRepository::class);
        $repository->expects($this->once())
            ->method('findItemInDomain')
            ->with(7, 99)
            ->willReturn(null);
        $repository->expects($this->never())->method('updateItemInDomain');

        $result = (new CategoryService($repository))->updateItem(7, 99, ['name' => 'Changed']);

        $this->assertTrue($result->isFailure());
    }

    public function testRefusesWhenCategoryIsUsedInTree(): void
    {
        $repository = $this->makeRepository();
        $repository->method('getNodeCountByCode')->willReturn(2);
        $repository->expects($this->never())->method('getProductCount');
        $repository->expects($this->never())->method('deleteItemByCategoryCode');

        $result = (new CategoryService($repository))->deleteItem(7, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('트리에서 사용 중', $result->getMessage());
    }

    public function testRefusesWhenProductsAreLinked(): void
    {
        $repository = $this->makeRepository();
        $repository->method('getNodeCountByCode')->willReturn(0);
        $repository->method('getProductCount')->willReturn(3);
        $repository->expects($this->never())->method('deleteItemByCategoryCode');

        $result = (new CategoryService($repository))->deleteItem(7, 1);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('상품(3개)', $result->getMessage());
    }

    public function testDeletesWhenNoTreeNodeAndNoProduct(): void
    {
        $repository = $this->makeRepository();
        $repository->method('getNodeCountByCode')->willReturn(0);
        $repository->method('getProductCount')->willReturn(0);
        $repository->expects($this->once())
            ->method('deleteItemByCategoryCode')
            ->with(7, 'shoes');

        $result = (new CategoryService($repository))->deleteItem(7, 1);

        $this->assertTrue($result->isSuccess());
    }

    public function testMissingCategoryIsReported(): void
    {
        $repository = $this->createMock(CategoryRepository::class);
        $repository->method('findItemInDomain')->willReturn(null);
        $repository->expects($this->never())->method('getNodeCountByCode');

        $result = (new CategoryService($repository))->deleteItem(7, 999);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('찾을 수 없습니다', $result->getMessage());
    }

    private function makeRepository(): CategoryRepository
    {
        $repository = $this->createMock(CategoryRepository::class);
        $repository->method('findItemInDomain')->with(7, 1)->willReturn([
            'domain_id' => 7,
            'category_code' => 'shoes',
        ]);

        return $repository;
    }
}
