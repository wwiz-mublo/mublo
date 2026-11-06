<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Packages\Shop\Repository\ReviewRepository;
use Mublo\Packages\Shop\Service\ReviewService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 쇼핑몰 설정 "후기 관리자 승인 후 공개"(review_require_approval)
 */
class ReviewServiceApprovalTest extends TestCase
{
    private ReviewRepository $repo;
    private ReviewService $service;

    protected function setUp(): void
    {
        $this->repo = $this->createMock(ReviewRepository::class);
        $this->service = new ReviewService($this->repo);
    }

    public function testDefaultKeepsReviewVisibleWithoutPendingFlag(): void
    {
        $this->repo->expects($this->once())->method('create')
            ->with($this->callback(fn(array $data) => !array_key_exists('is_pending', $data) && !array_key_exists('is_visible', $data)))
            ->willReturn(5);

        $result = $this->service->createReview(1, ['content' => '좋아요', 'rating' => 5]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('후기가 등록되었습니다.', $result->getMessage());
    }

    public function testPendingApprovalSavesHiddenPendingReview(): void
    {
        $this->repo->expects($this->once())->method('create')
            ->with($this->callback(fn(array $data) => $data['is_visible'] === 0 && $data['is_pending'] === 1))
            ->willReturn(5);

        $result = $this->service->createReview(1, ['content' => '좋아요', 'is_visible' => 1], pendingApproval: true);

        $this->assertTrue($result->isSuccess());
        $this->assertTrue($result->get('is_pending'));
        $this->assertStringContainsString('승인 후 공개', $result->getMessage());
    }

    public function testApprovePassesIdsToRepository(): void
    {
        $this->repo->expects($this->once())->method('approveByIds')->with(3, [7, 8])->willReturn(2);

        $result = $this->service->approve(3, [7, '8']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(2, $result->get('approved_count'));
    }

    #[DataProvider('invalidIds')]
    public function testApproveRejectsInvalidIds(array $ids): void
    {
        $this->repo->expects($this->never())->method('approveByIds');
        $this->assertTrue($this->service->approve(3, $ids)->isFailure());
    }

    public static function invalidIds(): array
    {
        return [[[]], [[0]], [['x']], [[-1]], [[true]], [[[7]]]];
    }

    public function testCountPendingDelegatesToRepository(): void
    {
        $this->repo->method('countPending')->with(3)->willReturn(4);
        $this->assertSame(4, $this->service->countPending(3));
    }
}
