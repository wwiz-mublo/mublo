<?php

namespace Tests\Faq\Unit\Api;

use Mublo\Plugin\Faq\Api\FaqProvisioningGateway;
use Mublo\Plugin\Faq\Repository\FaqRepository;
use PHPUnit\Framework\TestCase;

/**
 * 프로비저닝 계약의 공통 규약 두 가지를 확인한다.
 *
 * 1. 멱등 — 같은 키로 다시 부르면 기존 자원을 반환하고 created=false
 * 2. 덮지 않음 — 기존 자원이 있으면 프리셋을 적용하지 않는다
 *    (운영자가 고친 이름을 워커 재시도가 되돌리면 안 된다)
 */
class FaqProvisioningGatewayTest extends TestCase
{
    public function testReturnsExistingCategoryWithoutInsert(): void
    {
        $repo = $this->createMock(FaqRepository::class);
        $repo->method('findCategoryBySlug')->willReturn(['category_id' => 7, 'category_name' => '운영자가 고친 이름']);
        $repo->expects($this->never())->method('insertCategory');

        $result = (new FaqProvisioningGateway($repo))->ensureCategory(1, 'faq', ['category_name' => '자주 묻는 질문']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(7, $result->getData()['category_id']);
        $this->assertFalse($result->getData()['created']);
    }

    public function testCreatesWithProvisioningKeyAsSlug(): void
    {
        $repo = $this->createMock(FaqRepository::class);
        $repo->method('findCategoryBySlug')->willReturn(null);
        $repo->expects($this->once())
            ->method('insertCategory')
            ->with($this->callback(function (array $data): bool {
                return $data['category_slug'] === 'faq'
                    && $data['category_name'] === '자주 묻는 질문'
                    && $data['domain_id'] === 1;
            }))
            ->willReturn(11);

        $result = (new FaqProvisioningGateway($repo))->ensureCategory(1, 'faq', ['category_name' => '자주 묻는 질문']);

        $this->assertSame(11, $result->getData()['category_id']);
        $this->assertTrue($result->getData()['created']);
    }

    public function testFallsBackToKeyWhenNameMissing(): void
    {
        $repo = $this->createMock(FaqRepository::class);
        $repo->method('findCategoryBySlug')->willReturn(null);
        $repo->method('insertCategory')->willReturnCallback(function (array $data): int {
            $this->assertSame('faq', $data['category_name']);
            return 3;
        });

        (new FaqProvisioningGateway($repo))->ensureCategory(1, 'faq');
    }

    /** 동시 호출로 UNIQUE 위반이 나면 먼저 들어간 행을 읽어 같은 결과를 준다. */
    public function testResolvesRaceOnUniqueViolation(): void
    {
        $repo = $this->createMock(FaqRepository::class);
        $repo->method('findCategoryBySlug')->willReturnOnConsecutiveCalls(null, ['category_id' => 42]);
        $repo->method('insertCategory')->willThrowException(new \RuntimeException('Duplicate entry'));

        $result = (new FaqProvisioningGateway($repo))->ensureCategory(1, 'faq');

        $this->assertTrue($result->isSuccess());
        $this->assertSame(42, $result->getData()['category_id']);
        $this->assertFalse($result->getData()['created']);
    }

    public function testRejectsEmptyKey(): void
    {
        $repo = $this->createMock(FaqRepository::class);
        $this->assertFalse((new FaqProvisioningGateway($repo))->ensureCategory(1, '  ')->isSuccess());
    }
}
