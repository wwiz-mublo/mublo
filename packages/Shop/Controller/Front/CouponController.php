<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Packages\Shop\Service\CouponService;
use Mublo\Packages\Shop\Service\ShopConfigService;

/**
 * Front 쿠폰 컨트롤러
 *
 * 라우팅:
 * - GET  /shop/coupons                    → 쿠폰함 페이지
 * - GET  /shop/api/coupons/my            → 내 쿠폰함
 * - GET  /shop/api/coupons/downloadable   → 다운로드 가능 쿠폰
 * - POST /shop/api/coupons/download       → 쿠폰 다운로드
 * - GET  /shop/api/coupons/applicable     → 적용 가능 쿠폰 (체크아웃용)
 * - POST /shop/api/coupons/register       → 프로모션 코드 등록
 */
class CouponController
{
    private CouponService $couponService;
    private AuthContextInterface $authService;
    private ShopConfigService $shopConfigService;

    public function __construct(
        CouponService $couponService,
        AuthContextInterface $authService,
        ShopConfigService $shopConfigService
    ) {
        $this->couponService = $couponService;
        $this->authService = $authService;
        $this->shopConfigService = $shopConfigService;
    }

    /**
     * 쿠폰함 페이지
     *
     * GET /shop/coupons
     */
    public function page(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $member = $this->authService->currentUser();
        if (!$member) {
            return RedirectResponse::to('/login?redirect=/shop/coupons');
        }

        $domainId = $context->getDomainId() ?? 1;

        return ViewResponse::absoluteView($this->shopConfigService->frontView($domainId, 'Coupon/Index'))
            ->withData([
                'pageTitle' => '쿠폰함',
            ]);
    }

    /**
     * 내 쿠폰함 (탭별 목록)
     *
     * GET /shop/api/coupons/my?tab=available|used|expired
     */
    public function myCoupons(array $params, Context $context): JsonResponse
    {
        $member = $this->authService->currentUser();
        if (!$member) {
            return JsonResponse::error('로그인이 필요합니다.', null, 401);
        }

        $tab = (string) $context->getRequest()->query('tab', 'available');
        $result = $this->couponService->getMemberCoupons($member->memberId, $tab);

        return JsonResponse::success($result->getData(), $result->getMessage());
    }

    /**
     * 다운로드 가능 쿠폰 목록
     *
     * GET /shop/api/coupons/downloadable
     */
    public function downloadable(array $params, Context $context): JsonResponse
    {
        $member = $this->authService->currentUser();
        if (!$member) {
            return JsonResponse::error('로그인이 필요합니다.', null, 401);
        }

        $domainId = $context->getDomainId() ?? 1;
        $result = $this->couponService->getDownloadableCoupons($domainId, $member->memberId);

        return JsonResponse::success($result->getData(), $result->getMessage());
    }

    /**
     * 쿠폰 다운로드 (발급)
     *
     * POST /shop/api/coupons/download
     */
    public function download(array $params, Context $context): JsonResponse
    {
        $member = $this->authService->currentUser();
        if (!$member) {
            return JsonResponse::error('로그인이 필요합니다.', null, 401);
        }

        $request = $context->getRequest();
        $couponGroupId = (int) $request->json('coupon_group_id', 0);

        if ($couponGroupId <= 0) {
            return JsonResponse::error('쿠폰 정보가 올바르지 않습니다.');
        }

        $result = $this->couponService->issueCoupon($couponGroupId, $member->memberId);

        if ($result->isSuccess()) {
            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }

    /**
     * 적용 가능 쿠폰 목록 (체크아웃용)
     *
     * GET /shop/api/coupons/applicable?order_amount=50000&shipping_fee=3000
     */
    public function applicable(array $params, Context $context): JsonResponse
    {
        $member = $this->authService->currentUser();
        if (!$member) {
            return JsonResponse::error('로그인이 필요합니다.', null, 401);
        }

        $request = $context->getRequest();
        $orderAmount = (int) $request->query('order_amount', 0);
        $shippingFee = (int) $request->query('shipping_fee', 0);

        if ($orderAmount <= 0) {
            return JsonResponse::error('주문 금액이 필요합니다.');
        }

        $memberLevel = $member->levelValue;
        $result = $this->couponService->getApplicableCoupons(
            $member->memberId,
            $orderAmount,
            $memberLevel,
            $shippingFee
        );

        return JsonResponse::success($result->getData(), $result->getMessage());
    }

    /**
     * 프로모션 코드로 쿠폰 등록
     *
     * POST /shop/api/coupons/register
     */
    public function register(array $params, Context $context): JsonResponse
    {
        $member = $this->authService->currentUser();
        if (!$member) {
            return JsonResponse::error('로그인이 필요합니다.', null, 401);
        }

        $request = $context->getRequest();
        $code = trim((string) $request->json('code', ''));

        if (empty($code)) {
            return JsonResponse::error('프로모션 코드를 입력해주세요.');
        }

        $result = $this->couponService->registerByPromotionCode($code, $member->memberId);

        if ($result->isSuccess()) {
            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage());
    }
}
