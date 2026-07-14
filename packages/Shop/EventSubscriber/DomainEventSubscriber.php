<?php

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Core\Event\Domain\DomainCreatedEvent;
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Packages\Shop\Service\ShippingService;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Mublo\Repository\Menu\MenuItemRepository;
use Mublo\Service\Menu\MenuService;

/**
 * 도메인 생성 시 Shop 프론트 메뉴 + 기본 배송 템플릿 자동 시딩
 *
 * 패키지가 활성화된 상태에서 새 도메인이 생성되면
 * install()과 동일한 메뉴/배송 템플릿을 자동으로 등록한다.
 */
class DomainEventSubscriber implements EventSubscriberInterface
{
    /**
     * 기본 배송 템플릿 시드 정의
     *
     * 설치 직후 config 기본값으로 적용되는 것은 '조건부 무료 배송'.
     */
    private const SEED_SHIPPING_TEMPLATES = [
        ['name' => '무료 배송',       'shipping_method' => 'FREE', 'basic_cost' => 0,    'free_threshold' => 0,     'delivery_method' => 'COURIER', 'extra_cost_enabled' => 0, 'is_active' => 1],
        ['name' => '조건부 무료 배송', 'shipping_method' => 'COND', 'basic_cost' => 3000, 'free_threshold' => 50000, 'delivery_method' => 'COURIER', 'extra_cost_enabled' => 0, 'is_active' => 1],
        ['name' => '유료 배송',       'shipping_method' => 'PAID', 'basic_cost' => 3000, 'free_threshold' => 0,     'delivery_method' => 'COURIER', 'extra_cost_enabled' => 0, 'is_active' => 1],
    ];

    /** 설치 직후 config 기본값으로 지정할 시드 템플릿명 */
    private const DEFAULT_SEED_NAME = '조건부 무료 배송';

    private MenuService $menuService;
    private MenuItemRepository $menuItemRepo;
    private ShippingService $shippingService;
    private ShopConfigService $shopConfigService;

    public function __construct(
        MenuService $menuService,
        MenuItemRepository $menuItemRepo,
        ShippingService $shippingService,
        ShopConfigService $shopConfigService
    ) {
        $this->menuService = $menuService;
        $this->menuItemRepo = $menuItemRepo;
        $this->shippingService = $shippingService;
        $this->shopConfigService = $shopConfigService;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DomainCreatedEvent::class => 'onDomainCreated',
        ];
    }

    public function onDomainCreated(DomainCreatedEvent $event): void
    {
        $domainId = $event->getDomainId();

        // 기본 배송 템플릿 시딩 (자체 멱등 — 메뉴 존재 여부와 무관하게 실행)
        self::seedShippingTemplates($this->shippingService, $this->shopConfigService, $domainId);

        // 이미 Shop 메뉴가 존재하면 건너뜀 (중복 방지)
        $existing = $this->menuItemRepo->findByProvider($domainId, 'package', 'Shop');
        if (!empty($existing)) {
            return;
        }

        self::seedMenus($this->menuService, $domainId);
    }

    /**
     * 기본 배송 템플릿 시딩 (install + DomainCreatedEvent 공용)
     *
     * 멱등: 도메인에 배송 템플릿이 이미 하나라도 있으면 아무것도 하지 않는다.
     * 시드 후 기본 배송 템플릿이 미지정(0)이면 '조건부 무료 배송'을 기본값으로 설정.
     */
    public static function seedShippingTemplates(
        ShippingService $shippingService,
        ShopConfigService $shopConfigService,
        int $domainId
    ): void {
        // 마이그레이션 전(테이블 미생성)에 활성화되면 이 조회가 터진다.
        // 테이블이 아직 없으면 시딩을 건너뛴다 — 마이그레이션 실행 직후 재호출되어 정상 시딩됨.
        try {
            $existing = $shippingService->getList($domainId)->get('items', []);
        } catch (\Throwable $e) {
            if (self::isMissingTableError($e)) {
                return;
            }
            throw $e;
        }

        if (!empty($existing)) {
            return;
        }

        $defaultTemplateId = 0;
        foreach (self::SEED_SHIPPING_TEMPLATES as $tpl) {
            $result = $shippingService->create($domainId, $tpl);
            if ($result->isSuccess() && $tpl['name'] === self::DEFAULT_SEED_NAME) {
                $defaultTemplateId = (int) $result->get('shipping_id', 0);
            }
        }

        if ($defaultTemplateId <= 0) {
            return;
        }

        $config = $shopConfigService->getConfig($domainId)->get('config', []);
        if ((int) ($config['default_shipping_template_id'] ?? 0) === 0) {
            $shopConfigService->saveConfig($domainId, ['default_shipping_template_id' => $defaultTemplateId]);
        }
    }

    /**
     * "테이블 없음(SQLSTATE 42S02)" 예외인지 판별.
     *
     * 마이그레이션 전 활성화 시 시딩을 건너뛰기 위한 가드 — 다른 DB 오류는 가리지 않는다.
     */
    private static function isMissingTableError(\Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if ($cur instanceof \PDOException && $cur->getCode() === '42S02') {
                return true;
            }
            if (str_contains($cur->getMessage(), "doesn't exist")) {
                return true;
            }
        }
        return false;
    }

    /**
     * Shop 프론트 메뉴 시딩 (install + DomainCreatedEvent 공용)
     */
    public static function seedMenus(MenuService $menuService, int $domainId): void
    {
        // 프론트 메뉴
        $menuService->createItem($domainId, [
            'label' => '쇼핑',
            'url' => '/shop',
            'provider_type' => 'package',
            'provider_name' => 'Shop',
        ]);

        $menuService->createItem($domainId, [
            'label' => '기획전',
            'url' => '/shop/exhibitions',
            'provider_type' => 'package',
            'provider_name' => 'Shop',
        ]);

        $menuService->createItem($domainId, [
            'label' => '장바구니',
            'url' => '/shop/cart',
            'provider_type' => 'package',
            'provider_name' => 'Shop',
        ]);

        $menuService->createItem($domainId, [
            'label' => '구매후기',
            'url' => '/shop/reviews',
            'provider_type' => 'package',
            'provider_name' => 'Shop',
        ]);

        $menuService->createItem($domainId, [
            'label' => '상품문의',
            'url' => '/shop/inquiries',
            'provider_type' => 'package',
            'provider_name' => 'Shop',
        ]);

        $menuService->createItem($domainId, [
            'label' => '주문내역',
            'url' => '/shop/orders',
            // 비회원 주문조회로 /shop/orders가 회원·비회원 공용이 되어 전체 노출
            'visibility' => 'all',
            'provider_type' => 'package',
            'provider_name' => 'Shop',
        ]);

        $menuService->createItem($domainId, [
            'label' => '할인쿠폰',
            'url' => '/shop/coupons',
            'visibility' => 'member',
            'provider_type' => 'package',
            'provider_name' => 'Shop',
        ]);

        $menuService->createItem($domainId, [
            'label' => '찜한상품',
            'url' => '/shop/wishlist',
            'visibility' => 'member',
            'provider_type' => 'package',
            'provider_name' => 'Shop',
        ]);

        // 마이페이지 허브 (B등급 편입) — /mypage/shop "마이쇼핑". 마이페이지 사이드바 배치용 후보.
        // 마이페이지는 로그인 영역이라 member. (배치는 운영자 몫)
        $menuService->createItem($domainId, [
            'label' => '마이쇼핑',
            'url' => '/mypage/shop',
            'visibility' => 'member',
            'provider_type' => 'package',
            'provider_name' => 'Shop',
        ]);
    }
}
