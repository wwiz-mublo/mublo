<?php
/**
 * packages/Shop/tests/Unit/Service/ReviewServiceTest.php
 *
 * ReviewService 단위 테스트
 *
 * ReviewRepository를 Mock으로 대체하여 Service 비즈니스 로직만 격리 테스트합니다.
 * 외부 패키지 개발 참고용 — Mock 패턴 예시.
 *
 * 검증 항목:
 * - createReview() — 빈 내용 검증, 별점 범위 제한(1-5), 성공/실패
 * - updateReview() — 허용 필드만 반영
 * - toggleVisibility() — 리뷰 없음 처리, 토글 동작
 * - deleteReview() — 성공/실패
 * - replyToReview() — 리뷰 없음 처리, 답변 등록
 * - batchUpdate() / batchDelete() — 빈 배열 처리
 */

namespace Tests\Shop\Unit\Service;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Service\ReviewService;
use Mublo\Packages\Shop\Repository\ReviewRepository;

class ReviewServiceTest extends TestCase
{
    private ReviewRepository $reviewRepo;
    private ReviewService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reviewRepo = $this->createMock(ReviewRepository::class);
        $this->service    = new ReviewService($this->reviewRepo);
    }

    // =========================================================
    // createReview()
    // =========================================================

    public function testCreateReviewFailsWhenContentIsEmpty(): void
    {
        $result = $this->service->createReview(1, [
            'content' => '',
            'rating'  => 5,
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('내용', $result->getMessage());
    }

    public function testCreateReviewFailsWhenContentIsMissing(): void
    {
        $result = $this->service->createReview(1, ['rating' => 4]);

        $this->assertTrue($result->isFailure());
    }

    public function testCreateReviewClampsRatingBetween1And5(): void
    {
        // 저장 시 rating이 1~5로 제한되는지 검증
        $capturedData = null;

        $this->reviewRepo
            ->method('create')
            ->willReturnCallback(function (array $data) use (&$capturedData): int {
                $capturedData = $data;
                return 1; // 생성된 ID
            });

        // rating = 0 → 1로 보정
        $this->service->createReview(1, ['content' => '좋아요', 'rating' => 0]);
        $this->assertSame(1, $capturedData['rating']);

        // rating = 10 → 5로 보정
        $this->service->createReview(1, ['content' => '좋아요', 'rating' => 10]);
        $this->assertSame(5, $capturedData['rating']);
    }

    public function testCreateReviewPersistsGuestZeroMember(): void
    {
        // 비회원 후기: member_id=0이 저장 데이터로 전달돼야 한다(표시는 "비회원").
        $captured = null;
        $this->reviewRepo
            ->method('create')
            ->willReturnCallback(function (array $data) use (&$captured): int {
                $captured = $data;
                return 1;
            });

        $this->service->createReview(1, [
            'goods_id'        => 1,
            'order_no'        => '20260101ABC',
            'order_detail_id' => 7,
            'member_id'       => 0,
            'content'         => '비회원 후기입니다.',
            'rating'          => 5,
        ]);

        $this->assertSame(0, (int) $captured['member_id']);
    }

    public function testCreateReviewSuccessReturnsReviewId(): void
    {
        $this->reviewRepo->method('create')->willReturn(42);

        $result = $this->service->createReview(1, [
            'goods_id' => 10,
            'content'  => '정말 좋은 상품입니다.',
            'rating'   => 5,
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(42, $result->get('review_id'));
    }

    public function testCreateReviewFailsWhenRepositoryReturnsZero(): void
    {
        // create()가 int를 반환하므로 0(=실패)으로 시뮬레이션
        $this->reviewRepo->method('create')->willReturn(0);

        $result = $this->service->createReview(1, ['content' => '좋아요', 'rating' => 4]);

        $this->assertTrue($result->isFailure());
    }

    public function testCreateReviewSetsDomainId(): void
    {
        $capturedData = null;
        $this->reviewRepo
            ->method('create')
            ->willReturnCallback(function (array $data) use (&$capturedData): int {
                $capturedData = $data;
                return 1;
            });

        $this->service->createReview(7, ['content' => '좋아요', 'rating' => 3]);

        $this->assertSame(7, $capturedData['domain_id']);
    }

    public function testCreateReviewFiltersDisallowedFields(): void
    {
        // 허용되지 않은 필드(member_password 등)는 저장에서 제거
        $capturedData = null;
        $this->reviewRepo
            ->method('create')
            ->willReturnCallback(function (array $data) use (&$capturedData): int {
                $capturedData = $data;
                return 1;
            });

        $this->service->createReview(1, [
            'content'         => '좋아요',
            'rating'          => 5,
            'member_password' => 'secret', // 허용 안 됨
            'is_visible'      => 1,        // 허용됨
        ]);

        $this->assertArrayNotHasKey('member_password', $capturedData);
        $this->assertArrayHasKey('is_visible', $capturedData);
    }

    // =========================================================
    // updateReview()
    // =========================================================

    public function testUpdateReviewSucceeds(): void
    {
        // update()는 int 반환 — 1(영향받은 행 수)이면 성공
        $this->reviewRepo->method('updateInDomain')->willReturn(1);

        $result = $this->service->updateReview(7, 1, ['content' => '수정된 내용', 'rating' => 4]);

        $this->assertTrue($result->isSuccess());
    }

    public function testUpdateReviewFailsWhenRepositoryFails(): void
    {
        // update()가 0을 반환하면 실패 처리
        $this->reviewRepo->method('updateInDomain')->willReturn(0);

        $result = $this->service->updateReview(7, 1, ['rating' => 3]);

        $this->assertTrue($result->isFailure());
    }

    // =========================================================
    // toggleVisibility()
    // =========================================================

    public function testToggleVisibilityFailsWhenReviewNotFound(): void
    {
        $this->reviewRepo->method('findInDomain')->willReturn(null);

        $result = $this->service->toggleVisibility(7, 999);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('찾을 수 없', $result->getMessage());
    }

    public function testToggleVisibilityHidesVisibleReview(): void
    {
        $this->reviewRepo->method('findInDomain')->willReturn(['review_id' => 1, 'is_visible' => 1]);

        $capturedData = null;
        $this->reviewRepo
            ->method('updateInDomain')
            ->willReturnCallback(function (int $domainId, int $id, array $data) use (&$capturedData): int {
                $capturedData = $data;
                return 1;
            });

        $this->service->toggleVisibility(7, 1);

        // is_visible 1 → 0 으로 토글
        $this->assertSame(0, $capturedData['is_visible']);
    }

    public function testToggleVisibilityShowsHiddenReview(): void
    {
        $this->reviewRepo->method('findInDomain')->willReturn(['review_id' => 1, 'is_visible' => 0]);

        $capturedData = null;
        $this->reviewRepo
            ->method('updateInDomain')
            ->willReturnCallback(function (int $domainId, int $id, array $data) use (&$capturedData): int {
                $capturedData = $data;
                return 1;
            });

        $this->service->toggleVisibility(7, 1);

        // is_visible 0 → 1 으로 토글
        $this->assertSame(1, $capturedData['is_visible']);
    }

    // =========================================================
    // deleteReview()
    // =========================================================

    public function testDeleteReviewSucceeds(): void
    {
        $this->reviewRepo->method('deleteInDomain')->willReturn(1);

        $result = $this->service->deleteReview(7, 1);

        $this->assertTrue($result->isSuccess());
    }

    public function testDeleteReviewFailsWhenRepositoryFails(): void
    {
        $this->reviewRepo->method('deleteInDomain')->willReturn(0);

        $result = $this->service->deleteReview(7, 1);

        $this->assertTrue($result->isFailure());
    }

    // =========================================================
    // replyToReview()
    // =========================================================

    public function testReplyToReviewFailsWhenReviewNotFound(): void
    {
        $this->reviewRepo->method('findInDomain')->willReturn(null);

        $result = $this->service->replyToReview(7, 999, '감사합니다.');

        $this->assertTrue($result->isFailure());
    }

    public function testReplyToReviewSavesReplyContent(): void
    {
        $this->reviewRepo->method('findInDomain')->willReturn(['review_id' => 1, 'content' => '좋아요']);

        $capturedData = null;
        $this->reviewRepo
            ->method('updateInDomain')
            ->willReturnCallback(function (int $domainId, int $id, array $data) use (&$capturedData): int {
                $capturedData = $data;
                return 1;
            });

        $result = $this->service->replyToReview(7, 1, '이용해주셔서 감사합니다.');

        $this->assertTrue($result->isSuccess());
        $this->assertSame('이용해주셔서 감사합니다.', $capturedData['admin_reply']);
        $this->assertArrayHasKey('admin_reply_at', $capturedData);
    }

    // =========================================================
    // batchUpdate() / batchDelete()
    // =========================================================

    public function testBatchUpdateFailsWhenItemsIsEmpty(): void
    {
        $result = $this->service->batchUpdate(7, []);

        $this->assertTrue($result->isFailure());
    }

    public function testBatchUpdateReturnsUpdatedCount(): void
    {
        $this->reviewRepo->method('batchUpdateFields')->willReturn(3);

        $result = $this->service->batchUpdate(7, [
            ['review_id' => 1, 'is_best' => 1],
            ['review_id' => 2, 'is_best' => 1],
            ['review_id' => 3, 'is_best' => 0],
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(3, $result->get('updated_count'));
    }

    public function testBatchDeleteFailsWhenIdsIsEmpty(): void
    {
        $result = $this->service->batchDelete(7, []);

        $this->assertTrue($result->isFailure());
    }

    public function testBatchDeleteReturnsDeletedCount(): void
    {
        $this->reviewRepo->method('deleteByIds')->willReturn(2);

        $result = $this->service->batchDelete(7, [1, 2]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(2, $result->get('deleted_count'));
    }
}
