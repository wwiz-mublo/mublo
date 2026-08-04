<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Packages\Shop\Service\WishlistService;

/**
 * 관리자 찜한상품 관리
 *
 * GET  /admin/shop/wishlists           → 회원·상품 join 목록
 * POST /admin/shop/wishlists/delete    → 단건 삭제 (member_id + goods_id)
 */
class WishlistController
{
    private WishlistService $wishlistService;

    public function __construct(WishlistService $wishlistService)
    {
        $this->wishlistService = $wishlistService;
    }

    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();

        $page = (int) ($request->get('page') ?? 1);
        $perPage = (int) ($request->get('per_page') ?? 20);
        $filters = [
            'keyword' => trim((string) ($request->get('keyword') ?? '')),
        ];

        $data = $this->wishlistService->getAdminList($domainId, $filters, $page, $perPage);

        return ViewResponse::absoluteView(dirname(__DIR__, 2) . '/views/Admin/Wishlist/List')
            ->withData([
                'pageTitle' => '찜한상품',
                'items' => $data['items'],
                'pagination' => $data['pagination'],
                'filters' => $filters,
            ]);
    }

    public function delete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        $memberId = (int) ($request->json('member_id') ?? 0);
        $goodsId = (int) ($request->json('goods_id') ?? 0);

        if ($memberId <= 0 || $goodsId <= 0) {
            return JsonResponse::error('잘못된 요청입니다.');
        }

        $ok = $this->wishlistService->adminRemove($domainId, $memberId, $goodsId);

        return $ok
            ? JsonResponse::success(null, '삭제되었습니다.')
            : JsonResponse::error('삭제할 항목을 찾을 수 없습니다.');
    }

    public function listDelete(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId() ?? 1;
        $request = $context->getRequest();
        // 체크박스 값은 "member_id:goods_id" 형식 (찜은 복합키로 삭제)
        $chk = (array) ($request->input('chk') ?? []);

        $deleted = 0;
        foreach ($chk as $pair) {
            [$memberId, $goodsId] = array_pad(explode(':', (string) $pair, 2), 2, 0);
            $memberId = (int) $memberId;
            $goodsId  = (int) $goodsId;
            if ($memberId > 0 && $goodsId > 0 && $this->wishlistService->adminRemove($domainId, $memberId, $goodsId)) {
                $deleted++;
            }
        }

        if ($deleted === 0) {
            return JsonResponse::error('삭제할 항목을 선택해주세요.');
        }

        return JsonResponse::success(null, "{$deleted}건이 삭제되었습니다.");
    }
}
