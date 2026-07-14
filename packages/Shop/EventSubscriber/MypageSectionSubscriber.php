<?php

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\Mypage\MypageSectionBuildingEvent;
use Mublo\Packages\Shop\Service\CouponService;
use Mublo\Packages\Shop\Service\InquiryService;
use Mublo\Packages\Shop\Service\OrderService;
use Mublo\Packages\Shop\Service\OrderStateResolver;
use Mublo\Packages\Shop\Service\ReviewService;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Mublo\Packages\Shop\Service\WishlistService;

/**
 * 마이페이지 허브 구독자 (Shop) — /mypage/shop "마이쇼핑".
 *
 * 패키지당 허브 1개(B등급 편입 관례). 코어 셸 안에 Shop 전용 요약 뷰를 렌더한다.
 * 요약본 + 본체(/shop/*)로 가는 통로일 뿐, 상세는 프레임 안에서 그리지 않는다.
 */
class MypageSectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private OrderService       $orderService,
        private OrderStateResolver $orderStateResolver,
        private CouponService      $couponService,
        private WishlistService    $wishlistService,
        private ReviewService      $reviewService,
        private InquiryService     $inquiryService,
        private ShopConfigService  $shopConfigService,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            MypageSectionBuildingEvent::class => 'onMypageSectionBuilding',
        ];
    }

    public function onMypageSectionBuilding(MypageSectionBuildingEvent $event): void
    {
        // viewPath를 콜백으로 넘겨 렌더 시점 domainId로 스킨 해석(코어가 지원). basic 파일단위 폴백.
        $event->setSource('shop')->registerHub(
            '마이쇼핑',
            fn(int $domainId): string => $this->shopConfigService->frontView($domainId, 'Mypage/Index') . '.php',
            function (int $memberId, int $domainId): array {
                // 마이페이지=로그인한 회원 → 전부 member-scoped(소유권 게이트 불필요).
                $orderResult = $this->orderService->getMemberOrders($domainId, $memberId, 1, 5);
                $orderData   = $orderResult->isSuccess()
                    ? $orderResult->getData()
                    : ['items' => [], 'pagination' => ['totalItems' => 0]];

                $couponResult = $this->couponService->getMemberCoupons($memberId, 'available');
                $coupons      = $couponResult->isSuccess() ? ($couponResult->getData()['coupons'] ?? []) : [];

                $wishlist = $this->wishlistService->getMemberWishlist($domainId, $memberId, 1, 1);

                // 후기/문의: "내 목록" 페이지는 없지만 허브에 최근 5건 미리보기 + 총건수 노출
                $reviewList  = $this->reviewService->getList($domainId, ['member_id' => $memberId], 1, 5);
                $inquiryList = $this->inquiryService->getList($domainId, ['member_id' => $memberId], 1, 5);

                return [
                    'recentOrders'    => $orderData['items'] ?? [],
                    'orderCount'      => (int) ($orderData['pagination']['totalItems'] ?? 0),
                    'couponCount'     => count($coupons),
                    'wishlistCount'   => (int) ($wishlist['pagination']['totalItems'] ?? 0),
                    'recentReviews'   => $reviewList['items'] ?? [],
                    'reviewCount'     => (int) ($reviewList['pagination']['totalItems'] ?? 0),
                    'recentInquiries' => $inquiryList['items'] ?? [],
                    'inquiryCount'    => (int) ($inquiryList['pagination']['totalItems'] ?? 0),
                    'allStates'       => $this->orderStateResolver->getAllStates($domainId),
                ];
            }
        );
    }
}
