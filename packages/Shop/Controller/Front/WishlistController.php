<?php

namespace Mublo\Packages\Shop\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Packages\Shop\Service\WishlistService;
use Mublo\Packages\Shop\Service\ShopConfigService;

class WishlistController
{
    private WishlistService $wishlistService;
    private AuthContextInterface $authService;
    private ShopConfigService $shopConfigService;

    public function __construct(
        WishlistService $wishlistService,
        AuthContextInterface $authService,
        ShopConfigService $shopConfigService
    ) {
        $this->wishlistService = $wishlistService;
        $this->authService = $authService;
        $this->shopConfigService = $shopConfigService;
    }

    /**
     * 찜 토글 (AJAX)
     */
    public function toggle(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        if ($this->authService->guest()) {
            return JsonResponse::error('로그인이 필요합니다.', null, 401);
        }

        $request = $context->getRequest();
        $memberId = $this->authService->id() ?? 0;
        $goodsId = (int) ($request->json('goods_id') ?? 0);

        if ($goodsId <= 0) {
            return JsonResponse::error('상품 정보가 없습니다.');
        }

        $result = $this->wishlistService->toggle($domainId, $memberId, $goodsId);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 찜 목록 페이지
     */
    public function index(array $params, Context $context): ViewResponse|RedirectResponse
    {
        if ($this->authService->guest()) {
            return RedirectResponse::to('/login');
        }

        $memberId = $this->authService->id() ?? 0;
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $page = max(1, (int) ($request->get('page') ?? 1));

        // perPage 12: 그리드 2·3·4열에 모두 정합 (반응형 마지막 행 빈칸 방지)
        $data = $this->wishlistService->getMemberWishlist($domainId, $memberId, $page, 12);
        $pagination = $data['pagination'];
        $pagination['pageNums'] = 10;

        return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Wishlist/Index'))
            ->withData([
                'items' => $data['items'],
                'pagination' => $pagination,
            ]);
    }
}
