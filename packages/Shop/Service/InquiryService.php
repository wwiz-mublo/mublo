<?php

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Repository\InquiryRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;

class InquiryService
{
    private InquiryRepository $inquiryRepository;

    private const ALLOWED_FIELDS = [
        'goods_id', 'member_id', 'inquiry_type', 'title', 'content',
        'is_secret', 'author_name',
    ];

    public function __construct(
        InquiryRepository $inquiryRepository,
        private ProductRepository $productRepository,
    )
    {
        $this->inquiryRepository = $inquiryRepository;
    }

    public function getList(int $domainId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        return $this->inquiryRepository->getList($domainId, $filters, $page, $perPage);
    }

    public function getDetail(int $domainId, int $inquiryId): ?array
    {
        return $this->inquiryRepository->findInDomain($domainId, $inquiryId);
    }

    public function countByGoodsId(int $domainId, int $goodsId): int
    {
        return $this->inquiryRepository->countByGoodsId($domainId, $goodsId);
    }

    public function createInquiry(int $domainId, array $data): Result
    {
        $filtered = array_intersect_key($data, array_flip(self::ALLOWED_FIELDS));
        $filtered['domain_id'] = $domainId;
        $filtered['inquiry_status'] = 'WAITING';

        if (empty($filtered['title'])) {
            return Result::failure('제목을 입력해주세요.');
        }

        if ($this->productRepository->findInDomain($domainId, (int) ($filtered['goods_id'] ?? 0)) === null) {
            return Result::failure('상품을 찾을 수 없습니다.');
        }

        $id = $this->inquiryRepository->create($filtered);

        return $id
            ? Result::success('문의가 등록되었습니다.', ['inquiry_id' => $id])
            : Result::failure('문의 등록에 실패했습니다.');
    }

    public function updateInquiry(int $domainId, int $inquiryId, array $data): Result
    {
        $filtered = array_intersect_key($data, array_flip(self::ALLOWED_FIELDS));

        $ok = $this->inquiryRepository->updateInDomain($domainId, $inquiryId, $filtered);
        return $ok
            ? Result::success('문의가 수정되었습니다.')
            : Result::failure('문의 수정에 실패했습니다.');
    }

    public function answer(int $domainId, int $inquiryId, string $reply, int $staffId): Result
    {
        $inquiry = $this->inquiryRepository->findInDomain($domainId, $inquiryId);
        if (!$inquiry) {
            return Result::failure('문의를 찾을 수 없습니다.');
        }

        $isDelete = $reply === '';
        $ok = $this->inquiryRepository->updateInDomain($domainId, $inquiryId, [
            'reply' => $isDelete ? null : $reply,
            'replied_at' => $isDelete ? null : date('Y-m-d H:i:s'),
            'reply_staff_id' => $isDelete ? null : $staffId,
            'inquiry_status' => $isDelete ? 'WAITING' : 'REPLIED',
        ]);

        return $ok
            ? Result::success($isDelete ? '답변이 삭제되었습니다.' : '답변이 등록되었습니다.')
            : Result::failure($isDelete ? '답변 삭제에 실패했습니다.' : '답변 등록에 실패했습니다.');
    }

    public function deleteInquiry(int $domainId, int $inquiryId): Result
    {
        $ok = $this->inquiryRepository->deleteInDomain($domainId, $inquiryId);
        return $ok
            ? Result::success('문의가 삭제되었습니다.')
            : Result::failure('문의 삭제에 실패했습니다.');
    }

    public function batchUpdate(int $domainId, array $items): Result
    {
        if (empty($items)) {
            return Result::failure('수정할 항목이 없습니다.');
        }

        $updated = $this->inquiryRepository->batchUpdateFields($domainId, $items);

        return Result::success("{$updated}건이 수정되었습니다.", ['updated_count' => $updated]);
    }

    public function batchDelete(int $domainId, array $inquiryIds): Result
    {
        if (empty($inquiryIds)) {
            return Result::failure('삭제할 항목이 없습니다.');
        }

        $deleted = $this->inquiryRepository->deleteByIds($domainId, $inquiryIds);

        return Result::success("{$deleted}건이 삭제되었습니다.", ['deleted_count' => $deleted]);
    }
}
