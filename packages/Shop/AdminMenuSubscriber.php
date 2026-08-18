<?php
declare(strict_types=1);
namespace Mublo\Packages\Shop;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

/**
 * Shop 패키지 관리자 메뉴 Subscriber
 *
 * 패키지 그룹(package)에 쇼핑몰 관련 메뉴 추가
 */
class AdminMenuSubscriber implements EventSubscriberInterface
{
    /**
     * 패키지 정보 (code prefix용)
     */
    public const PACKAGE_NAME = 'Shop';

    /**
     * 구독할 이벤트 목록
     */
    public static function getSubscribedEvents(): array
    {
        return [
            AdminMenuBuildingEvent::class => 'onAdminMenuBuilding',
        ];
    }

    /**
     * 관리자 메뉴 빌드 시 호출
     *
     * 코드에는 prefix 없이 순수 코드만 지정합니다.
     * 자동으로 K_Shop_{code} 형식으로 변환됩니다.
     */
    public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
    {
        // 소스 정보 설정 (code prefix 적용을 위해)
        $event->setSource('package', self::PACKAGE_NAME);

        // 패키지 그룹에 메뉴 추가 (아이콘은 manifest.json 단일 소스 — null 자동 해석)
        $event->addPackageMenu('Mublo Shop', null, [
            [
                'label' => '대시보드',
                'url' => '/admin/shop/dashboard',
                'code' => '000',
            ],
            [
                'label' => '쇼핑몰 설정',
                'url' => '/admin/shop/config',
                'code' => '001',
            ],
            [
                'label' => '카테고리 관리',
                'url' => '/admin/shop/categories',
                'code' => '002',
            ],
            [
                'label' => '옵션 프리셋',
                'url' => '/admin/shop/options',
                'code' => '003',
            ],
            [
                'label' => '상품정보 템플릿',
                'url' => '/admin/shop/info-templates',
                'code' => '009',
            ],
            [
                'label' => '상품 관리',
                'url' => '/admin/shop/products',
                'code' => '004',
            ],
            [
                'label' => '주문 관리',
                'url' => '/admin/shop/orders',
                'code' => '005',
            ],
            [
                'label' => '반품·교환 관리',
                'url' => '/admin/shop/claims',
                'code' => '016',
            ],
            [
                'label' => '상태·알림 설정',
                'url' => '/admin/shop/order-states',
                'code' => '006',
            ],
            [
                'label' => '배송 템플릿',
                'url' => '/admin/shop/shipping',
                'code' => '008',
            ],
            [
                'label' => '쿠폰 관리',
                'url' => '/admin/shop/coupons',
                'code' => '007',
            ],
            [
                'label' => '구매후기',
                'url' => '/admin/shop/reviews',
                'code' => '010',
            ],
            [
                'label' => '상품문의',
                'url' => '/admin/shop/inquiries',
                'code' => '011',
            ],
            [
                'label' => '찜한상품',
                'url' => '/admin/shop/wishlists',
                'code' => '015',
            ],
            [
                'label' => '기획전 관리',
                'url' => '/admin/shop/exhibitions',
                'code' => '014',
            ],
            [
                'label' => '가격비교 사이트',
                'url' => '/admin/shop/price-compare',
                'code' => '017',
            ],
        ]);
    }
}
