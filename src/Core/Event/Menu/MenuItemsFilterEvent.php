<?php
declare(strict_types=1);

namespace Mublo\Core\Event\Menu;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\AbstractEvent;

/**
 * 프론트 메뉴 아이템 필터 이벤트
 *
 * 프론트 메뉴 fetch 시점에 패키지/플러그인이 컨텍스트(브랜드샵, 파트너 사이트 등)에 따라
 * 메뉴 아이템을 제외할 수 있도록 한다. 관리자 메뉴 관리 화면에는 영향 없음.
 *
 * 발행: MenuService::applyFrontItemsFilter() — 아래 네 fetch 메서드의 반환 직전.
 * 관리자 요청(Context::isFront() 가 false)에서는 발행하지 않는다. 유틸/푸터/마이페이지
 * 저장이 "포함 목록 전체 교체"라, 감춰진 항목이 빠진 채 저장되면 노출 설정이 지워지기 때문이다.
 *
 * scope:
 *   - 'tree'    : 메인 트리 (getTree / getTreeHierarchy)
 *   - 'utility' : 유틸리티 메뉴 (getUtilityMenus)
 *   - 'footer'  : 푸터 메뉴 (getFooterMenus)
 *   - 'mypage'  : 마이페이지 메뉴 (getMypageMenus)
 *
 * 아이템 구조는 fetch 메서드에 따라 다르며 (트리 노드는 menu_code/url/parent_code/...
 * 유틸/푸터/마이페이지는 item_id/url/...), 구독자는 url 등 공통 키만 안전하게 참조해야 한다.
 *
 * 사용 예 (Subscriber):
 *   if ($event->getContext()?->getSiteOverride('rental.brand_code')) {
 *       $items = array_values(array_filter($event->getItems(),
 *           fn($i) => !str_starts_with($i['url'] ?? '', '/rental/brand/')
 *       ));
 *       $event->setItems($items);
 *   }
 */
class MenuItemsFilterEvent extends AbstractEvent
{
    /** 메인 트리 (getTree / getTreeHierarchy) */
    public const SCOPE_TREE = 'tree';

    /** 유틸리티 메뉴 (getUtilityMenus) */
    public const SCOPE_UTILITY = 'utility';

    /** 푸터 메뉴 (getFooterMenus) */
    public const SCOPE_FOOTER = 'footer';

    /** 마이페이지 메뉴 (getMypageMenus) */
    public const SCOPE_MYPAGE = 'mypage';

    public function __construct(
        private readonly int $domainId,
        private readonly string $scope,
        private array $items,
        private readonly ?Context $context = null
    ) {}

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getItems(): array
    {
        return $this->items;
    }

    public function getContext(): ?Context
    {
        return $this->context;
    }

    public function setItems(array $items): void
    {
        $this->items = $items;
    }
}
