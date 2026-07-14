<?php

namespace Tests\Banner\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Mublo\Plugin\Banner\Service\BannerService;
use Mublo\Plugin\Banner\Repository\BannerRepository;
use Mublo\Plugin\Banner\Entity\Banner;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Plugin\Banner\Event\BannerContentChangedEvent;

/**
 * BannerServiceTest
 *
 * 배너 CRUD 서비스 테스트
 */
class BannerServiceTest extends TestCase
{
    private MockObject $repositoryMock;
    private BannerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryMock = $this->createMock(BannerRepository::class);
        // EventDispatcher 없이 생성
        $this->service = new BannerService($this->repositoryMock, null);
    }

    private function makeBannerEntity(array $overrides = []): Banner
    {
        return Banner::fromArray(array_merge([
            'banner_id'    => 1,
            'domain_id'    => 10,
            'title'        => '테스트 배너',
            'pc_image_url' => '/img/banner.jpg',
            'mo_image_url' => null,
            'link_url'     => 'https://example.com',
            'link_target'  => '_self',
            'sort_order'   => 0,
            'is_active'    => 1,
            'start_date'   => null,
            'end_date'     => null,
            'extras'       => null,
            'created_at'   => '2026-01-01 00:00:00',
            'updated_at'   => null,
        ], $overrides));
    }

    // ─── getList ───

    public function testGetListReturnsSuccessResult(): void
    {
        $this->repositoryMock->method('findPaginated')
            ->willReturn(['items' => [], 'total' => 0]);

        $result = $this->service->getList(10);

        $this->assertTrue($result->isSuccess());
        $this->assertSame([], $result->get('items'));
        $this->assertSame(0, $result->get('totalItems'));
    }

    public function testGetListPaginationCalculation(): void
    {
        $this->repositoryMock->method('findPaginated')
            ->willReturn(['items' => [], 'total' => 55]);

        $result = $this->service->getList(10, page: 2, perPage: 20);

        $this->assertSame(55, $result->get('totalItems'));
        $this->assertSame(20, $result->get('perPage'));
        $this->assertSame(2, $result->get('currentPage'));
        $this->assertSame(3, $result->get('totalPages')); // ceil(55/20)
    }

    // ─── getBanner ───

    public function testGetBannerReturnsSuccessWhenFound(): void
    {
        $banner = $this->makeBannerEntity();

        $this->repositoryMock->method('findWithDomain')
            ->with(1, 10)
            ->willReturn($banner);

        $result = $this->service->getBanner(10, 1);

        $this->assertTrue($result->isSuccess());
        $this->assertArrayHasKey('banner', $result->getData());
    }

    public function testGetBannerReturnsFailureWhenNotFound(): void
    {
        $this->repositoryMock->method('findWithDomain')->willReturn(null);

        $result = $this->service->getBanner(10, 999);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('배너', $result->getMessage());
    }

    // ─── create ───

    public function testCreateSucceeds(): void
    {
        $this->repositoryMock->expects($this->once())
            ->method('create')
            ->willReturn(42);

        $result = $this->service->create(10, [
            'title'        => '신규 배너',
            'pc_image_url' => '/img/new.jpg',
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(42, $result->get('banner_id'));
    }

    public function testCreateDispatchesDomainScopedContentChangedEvent(): void
    {
        $this->repositoryMock->method('create')->willReturn(42);
        $dispatcher = new EventDispatcher();
        $domainId = null;
        $dispatcher->addListener(BannerContentChangedEvent::class, function ($event) use (&$domainId): void {
            $domainId = $event->getDomainId();
        });

        $result = (new BannerService($this->repositoryMock, $dispatcher))->create(10, [
            'title' => '신규 배너',
            'pc_image_url' => '/img/new.jpg',
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(10, $domainId);
    }

    public function testCreateFailsWhenTitleEmpty(): void
    {
        $this->repositoryMock->expects($this->never())->method('create');

        $result = $this->service->create(10, [
            'title'        => '',
            'pc_image_url' => '/img/new.jpg',
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('제목', $result->getMessage());
    }

    public function testCreateFailsWhenPcImageEmpty(): void
    {
        $this->repositoryMock->expects($this->never())->method('create');

        $result = $this->service->create(10, [
            'title'        => '배너 제목',
            'pc_image_url' => '',
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('이미지', $result->getMessage());
    }

    public function testCreateFailsWhenRepositoryReturnsNull(): void
    {
        $this->repositoryMock->method('create')->willReturn(null);

        $result = $this->service->create(10, [
            'title'        => '배너 제목',
            'pc_image_url' => '/img/new.jpg',
        ]);

        $this->assertTrue($result->isFailure());
    }

    // ─── update ───

    public function testUpdateSucceeds(): void
    {
        $banner = $this->makeBannerEntity();
        $this->repositoryMock->method('findWithDomain')->willReturn($banner);
        $this->repositoryMock->expects($this->once())->method('updateWithDomain');

        $result = $this->service->update(10, 1, [
            'title'        => '수정된 배너',
            'pc_image_url' => '/img/updated.jpg',
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('수정', $result->getMessage());
    }

    public function testUpdateFailsWhenBannerNotFound(): void
    {
        $this->repositoryMock->method('findWithDomain')->willReturn(null);

        $result = $this->service->update(10, 999, [
            'title'        => '수정',
            'pc_image_url' => '/img/x.jpg',
        ]);

        $this->assertTrue($result->isFailure());
    }

    public function testUpdateFailsWhenTitleEmpty(): void
    {
        $banner = $this->makeBannerEntity();
        $this->repositoryMock->method('findWithDomain')->willReturn($banner);
        $this->repositoryMock->expects($this->never())->method('updateWithDomain');

        $result = $this->service->update(10, 1, [
            'title'        => '',
            'pc_image_url' => '/img/x.jpg',
        ]);

        $this->assertTrue($result->isFailure());
    }

    // ─── delete ───

    public function testDeleteSucceeds(): void
    {
        $banner = $this->makeBannerEntity();
        $this->repositoryMock->method('findWithDomain')->willReturn($banner);
        $this->repositoryMock->expects($this->once())->method('deleteWithDomain');

        $result = $this->service->delete(10, 1);

        $this->assertTrue($result->isSuccess());
        $this->assertStringContainsString('삭제', $result->getMessage());
    }

    public function testDeleteFailsWhenBannerNotFound(): void
    {
        $this->repositoryMock->method('findWithDomain')->willReturn(null);
        $this->repositoryMock->expects($this->never())->method('deleteWithDomain');

        $result = $this->service->delete(10, 999);

        $this->assertTrue($result->isFailure());
    }

    // ─── updateOrder ───

    public function testUpdateOrderCallsRepositoryForEachItem(): void
    {
        $this->repositoryMock->expects($this->exactly(3))
            ->method('updateSortOrder');

        $result = $this->service->updateOrder(10, [
            ['banner_id' => 1, 'sort_order' => 0],
            ['banner_id' => 2, 'sort_order' => 1],
            ['banner_id' => 3, 'sort_order' => 2],
        ]);

        $this->assertTrue($result->isSuccess());
    }

    public function testUpdateOrderSkipsItemsWithZeroId(): void
    {
        $this->repositoryMock->expects($this->once())
            ->method('updateSortOrder');

        $this->service->updateOrder(10, [
            ['banner_id' => 0, 'sort_order' => 0], // 스킵
            ['banner_id' => 5, 'sort_order' => 1], // 처리
        ]);
    }
}
