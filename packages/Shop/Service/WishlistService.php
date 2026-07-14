<?php

namespace Mublo\Packages\Shop\Service;

use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Repository\WishlistRepository;
use Mublo\Packages\Shop\Repository\ProductRepository;

class WishlistService
{
    private WishlistRepository $wishlistRepository;

    public function __construct(
        WishlistRepository $wishlistRepository,
        private ProductRepository $productRepository,
    )
    {
        $this->wishlistRepository = $wishlistRepository;
    }

    public function toggle(int $domainId, int $memberId, int $goodsId): Result
    {
        if ($this->productRepository->findInDomain($domainId, $goodsId) === null) {
            return Result::failure('상품을 찾을 수 없습니다.');
        }

        $existing = $this->wishlistRepository->findInDomain($domainId, $memberId, $goodsId);

        if ($existing) {
            $this->wishlistRepository->deleteInDomain($domainId, $memberId, $goodsId);
            return Result::success('찜이 취소되었습니다.', ['wishlisted' => false]);
        }

        $this->wishlistRepository->create($memberId, $goodsId);
        return Result::success('찜에 추가되었습니다.', ['wishlisted' => true]);
    }

    public function isWishlisted(int $domainId, int $memberId, int $goodsId): bool
    {
        return $this->wishlistRepository->findInDomain($domainId, $memberId, $goodsId) !== null;
    }

    public function getMemberWishlist(int $domainId, int $memberId, int $page = 1, int $perPage = 20): array
    {
        return $this->wishlistRepository->getMemberWishlist($domainId, $memberId, $page, $perPage);
    }

    public function countByGoodsId(int $goodsId): int
    {
        return $this->wishlistRepository->countByGoodsId($goodsId);
    }

    public function countByGoodsIds(array $goodsIds): array
    {
        return $this->wishlistRepository->countByGoodsIds($goodsIds);
    }

    public function getMemberGoodsIds(int $domainId, int $memberId): array
    {
        return $this->wishlistRepository->getMemberGoodsIds($domainId, $memberId);
    }

    /**
     * 관리자 전체 찜 목록 조회
     */
    public function getAdminList(int $domainId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        return $this->wishlistRepository->getAdminList($domainId, $filters, $page, $perPage);
    }

    /**
     * 관리자: 찜 항목 강제 삭제
     */
    public function adminRemove(int $domainId, int $memberId, int $goodsId): bool
    {
        return $this->wishlistRepository->deleteInDomain($domainId, $memberId, $goodsId) > 0;
    }
}
