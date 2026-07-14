<?php

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Repository\ReviewRepository;

class ReviewService
{
    private ReviewRepository $reviewRepository;

    private const ALLOWED_FIELDS = [
        'goods_id', 'order_no', 'order_detail_id', 'member_id',
        'review_type', 'rating', 'content',
        'image1', 'image2', 'image3',
        'is_visible', 'is_best',
    ];

    public function __construct(ReviewRepository $reviewRepository)
    {
        $this->reviewRepository = $reviewRepository;
    }

    public function getList(int $domainId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        return $this->reviewRepository->getList($domainId, $filters, $page, $perPage);
    }

    public function getDetail(int $domainId, int $reviewId): ?array
    {
        return $this->reviewRepository->findInDomain($domainId, $reviewId);
    }

    public function getStatsByGoodsIds(int $domainId, array $goodsIds): array
    {
        return $this->reviewRepository->getStatsByGoodsIds($domainId, $goodsIds);
    }

    public function getByGoodsId(int $domainId, int $goodsId, int $page = 1, int $perPage = 10): array
    {
        return $this->reviewRepository->getByGoodsId($domainId, $goodsId, $page, $perPage);
    }

    public function getAverageRating(int $domainId, int $goodsId): float
    {
        return $this->reviewRepository->getAverageRating($domainId, $goodsId);
    }

    /**
     * 상품 상세 후기 요약 (평균/총계/평점 분포)
     */
    public function getSummary(int $domainId, int $goodsId): array
    {
        $distribution = $this->reviewRepository->getRatingDistribution($domainId, $goodsId);

        return [
            'average'      => $this->reviewRepository->getAverageRating($domainId, $goodsId),
            'total'        => array_sum($distribution),
            'distribution' => $distribution,
        ];
    }

    public function getByOrderDetailId(int $domainId, int $orderDetailId): ?array
    {
        return $this->reviewRepository->findByOrderDetailId($domainId, $orderDetailId);
    }

    /**
     * 주문상세 ID 목록 중 이미 후기가 작성된 것의 맵
     *
     * @param int[] $orderDetailIds
     * @return array<int,int> [order_detail_id => review_id]
     */
    public function getReviewedDetailIds(int $domainId, array $orderDetailIds): array
    {
        return $this->reviewRepository->getReviewedDetailIds($domainId, $orderDetailIds);
    }

    public function createReview(int $domainId, array $data): Result
    {
        $filtered = array_intersect_key($data, array_flip(self::ALLOWED_FIELDS));
        $filtered['domain_id'] = $domainId;

        if (empty($filtered['content'])) {
            return Result::failure('후기 내용을 입력해주세요.');
        }

        $filtered['rating'] = max(1, min(5, (int) ($filtered['rating'] ?? 5)));

        $id = $this->reviewRepository->create($filtered);

        return $id
            ? Result::success('후기가 등록되었습니다.', ['review_id' => $id])
            : Result::failure('후기 등록에 실패했습니다.');
    }

    public function updateReview(int $domainId, int $reviewId, array $data): Result
    {
        $filtered = array_intersect_key($data, array_flip(self::ALLOWED_FIELDS));

        $ok = $this->reviewRepository->updateInDomain($domainId, $reviewId, $filtered);
        return $ok
            ? Result::success('후기가 수정되었습니다.')
            : Result::failure('후기 수정에 실패했습니다.');
    }

    public function toggleVisibility(int $domainId, int $reviewId): Result
    {
        $review = $this->reviewRepository->findInDomain($domainId, $reviewId);
        if (!$review) {
            return Result::failure('후기를 찾을 수 없습니다.');
        }

        $ok = $this->reviewRepository->updateInDomain($domainId, $reviewId, [
            'is_visible' => $review['is_visible'] ? 0 : 1,
        ]);

        return $ok
            ? Result::success('후기 표시 상태가 변경되었습니다.')
            : Result::failure('상태 변경에 실패했습니다.');
    }

    public function deleteReview(int $domainId, int $reviewId): Result
    {
        $ok = $this->reviewRepository->deleteInDomain($domainId, $reviewId);
        return $ok
            ? Result::success('후기가 삭제되었습니다.')
            : Result::failure('후기 삭제에 실패했습니다.');
    }

    public function replyToReview(int $domainId, int $reviewId, string $replyContent): Result
    {
        $review = $this->reviewRepository->findInDomain($domainId, $reviewId);
        if (!$review) {
            return Result::failure('후기를 찾을 수 없습니다.');
        }

        $isDelete = $replyContent === '';
        $ok = $this->reviewRepository->updateInDomain($domainId, $reviewId, [
            'admin_reply' => $isDelete ? null : $replyContent,
            'admin_reply_at' => $isDelete ? null : date('Y-m-d H:i:s'),
        ]);

        return $ok
            ? Result::success($isDelete ? '답변이 삭제되었습니다.' : '답변이 등록되었습니다.')
            : Result::failure($isDelete ? '답변 삭제에 실패했습니다.' : '답변 등록에 실패했습니다.');
    }

    public function batchUpdate(int $domainId, array $items): Result
    {
        if (empty($items)) {
            return Result::failure('수정할 항목이 없습니다.');
        }

        $updated = $this->reviewRepository->batchUpdateFields($domainId, $items);

        return Result::success("{$updated}건이 수정되었습니다.", ['updated_count' => $updated]);
    }

    public function batchDelete(int $domainId, array $reviewIds): Result
    {
        if (empty($reviewIds)) {
            return Result::failure('삭제할 항목이 없습니다.');
        }

        $deleted = $this->reviewRepository->deleteByIds($domainId, $reviewIds);

        return Result::success("{$deleted}건이 삭제되었습니다.", ['deleted_count' => $deleted]);
    }
}
