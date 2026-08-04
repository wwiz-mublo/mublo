<?php
declare(strict_types=1);

namespace Mublo\Service\Admin\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

/**
 * ExamplePluginMenuSubscriber
 *
 * 플러그인/패키지에서 관리자 메뉴를 추가하는 방법 예제
 *
 * ========================================================================
 * 파일 위치
 * ========================================================================
 * - Plugin: src/Plugin/{PluginName}/AdminMenuSubscriber.php
 * - Package: src/Packages/{PackageName}/AdminMenuSubscriber.php
 *
 * ========================================================================
 * Code 체계
 * ========================================================================
 * Code는 자동으로 prefix가 적용됩니다:
 * - Core: 숫자만 (001, 002, 003001)
 * - Plugin(MemberPoint): '001' → 'P_MemberPoint_001'
 * - Package(Shop): '001' → 'K_Shop_001'
 *
 * ========================================================================
 * 사용 가능한 메서드
 * ========================================================================
 *
 * 1. addGroup(string $key, string $label, int $priority = 0)
 *    - 새로운 메뉴 그룹 생성
 *    - priority가 높을수록 위에 표시
 *
 *    예시:
 *    $event->addGroup('shop', '쇼핑몰', 80);
 *
 * 2. addMenus(string $groupKey, array $menus)
 *    - 그룹에 메뉴 항목들 추가
 *
 *    예시:
 *    $event->addMenus('shop', [
 *        [
 *            'label' => '상품 관리',
 *            'url' => '#',
 *            'icon' => 'bi-box',
 *            'code' => '001',
 *            'submenu' => [
 *                ['label' => '상품 목록', 'url' => '/admin/products', 'code' => '001001'],
 *                ['label' => '상품 등록', 'url' => '/admin/products/create', 'code' => '001002'],
 *            ],
 *        ],
 *        [
 *            'label' => '주문 관리',
 *            'url' => '/admin/orders',
 *            'icon' => 'bi-receipt',
 *            'code' => '002',
 *        ],
 *    ]);
 *
 * 3. addSubmenuTo(string $parentCode, array $submenu)
 *    - 기존 Core 메뉴의 서브메뉴로 추가
 *
 *    예시: 회원관리(003)에 포인트 내역 서브메뉴 추가
 *    $event->addSubmenuTo('003', [
 *        'label' => '포인트 내역',
 *        'url' => '/admin/member-point/history',
 *        'code' => '003',
 *    ]);
 *
 * 4. insertAfter(string $afterCode, string $groupKey, array $menu)
 *    - 특정 메뉴 뒤에 새 메뉴 삽입
 *
 *    예시: 회원관리(003) 뒤에 쿠폰 관리 메뉴 삽입
 *    $event->insertAfter('003', 'platform', [
 *        'label' => '쿠폰 관리',
 *        'url' => '/admin/coupons',
 *        'icon' => 'bi-ticket-perforated',
 *        'code' => '004',
 *    ]);
 *
 * 5. insertBefore(string $beforeCode, string $groupKey, array $menu)
 *    - 특정 메뉴 앞에 새 메뉴 삽입
 *
 *    예시: 게시판관리(005) 앞에 이벤트 관리 메뉴 삽입
 *    $event->insertBefore('005', 'platform', [
 *        'label' => '이벤트 관리',
 *        'url' => '/admin/events',
 *        'icon' => 'bi-calendar-event',
 *        'code' => '005',
 *    ]);
 *
 * ========================================================================
 * 플러그인 예시 (배너, 팝업, 위젯, 포인트 등)
 * ========================================================================
 * 플러그인은 단일 기능을 제공하며, 주로 Core 메뉴에 서브메뉴를 추가하거나
 * 소규모 독립 메뉴를 추가합니다.
 *
 * ========================================================================
 * 패키지 예시 (쇼핑몰, 렌탈몰, 구인구직 등)
 * ========================================================================
 * 패키지는 복합 기능을 제공하며, 주로 새로운 그룹을 생성하여
 * 독립적인 관리 영역을 구성합니다.
 */
class ExamplePluginMenuSubscriber implements EventSubscriberInterface
{
    /**
     * 구독할 이벤트 목록
     *
     * 키는 이벤트 클래스, 값은 이 클래스의 핸들러 메서드명이다.
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
     * 실제 플러그인/패키지에서는 위 문서의 메서드들을 활용해 메뉴를 추가한다.
     */
    public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
    {
        // 이 파일은 예제입니다.
    }
}
