<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Menu;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Menu\MenuItemsFilterEvent;
use Mublo\Core\Http\Request;
use Mublo\Infrastructure\Code\CodeGenerator;
use Mublo\Repository\Menu\MenuItemRepository;
use Mublo\Repository\Menu\MenuTreeRepository;
use Mublo\Service\Menu\MenuService;
use PHPUnit\Framework\TestCase;

/**
 * 프론트 메뉴 필터 확장점의 배선 검증.
 *
 * 이 이벤트는 클래스만 있고 발행 지점이 없어 구독자가 한 번도 실행되지 않던 이력이 있다.
 * 배선 자체를 테스트로 고정한다 — 프론트에서는 발행되고, 관리자에서는 발행되지 않는다.
 *
 * 관리자에서 발행하지 않는 것이 핵심이다. 유틸리티·푸터·마이페이지 저장은 "포함 목록
 * 전체 교체"라, 표시용 필터로 감춰진 항목이 관리 화면 목록에서 빠진 채 저장되면
 * 그 항목의 노출 설정이 지워진다.
 */
class MenuItemsFilterEventTest extends TestCase
{
    /** 유틸리티·푸터·마이페이지 fetch 의 행 모양 */
    private const ROWS = [
        ['item_id' => 1, 'url' => '/company'],
        ['item_id' => 2, 'url' => '/rental/brand/sk'],
    ];

    /** 트리 fetch 의 행 모양 — 계층 조립에 path_code/parent_code 가 필요하다 */
    private const TREE_ROWS = [
        ['menu_code' => 'A', 'path_code' => 'A', 'parent_code' => null, 'url' => '/company'],
        ['menu_code' => 'B', 'path_code' => 'B', 'parent_code' => null, 'url' => '/rental/brand/sk'],
    ];

    private function context(bool $isAdmin): Context
    {
        $context = new Context(new Request('GET', $isAdmin ? '/admin/menu' : '/'));
        $context->setAdmin($isAdmin);

        return $context;
    }

    /**
     * @return array{0: MenuService, 1: \ArrayObject<int, string>} 서비스와 발행된 scope 기록
     */
    private function service(?Context $context): array
    {
        $itemRepo = $this->createMock(MenuItemRepository::class);
        $itemRepo->method('findUtilityMenus')->willReturn(self::ROWS);
        $itemRepo->method('findFooterMenus')->willReturn(self::ROWS);
        $itemRepo->method('findMypageMenus')->willReturn(self::ROWS);

        $treeRepo = $this->createMock(MenuTreeRepository::class);
        $treeRepo->method('findTreeWithItems')->willReturn(self::TREE_ROWS);

        /** @var \ArrayObject<int, string> $seenScopes */
        $seenScopes = new \ArrayObject();
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            MenuItemsFilterEvent::class,
            function (MenuItemsFilterEvent $event) use ($seenScopes): void {
                $seenScopes[] = $event->getScope();
                $event->setItems(array_values(array_filter(
                    $event->getItems(),
                    static fn(array $item): bool => !str_starts_with((string) $item['url'], '/rental/brand/')
                )));
            }
        );

        $service = new MenuService(
            $itemRepo,
            $treeRepo,
            $this->createMock(CodeGenerator::class),
            null,
            $dispatcher,
            $context
        );

        return [$service, $seenScopes];
    }

    public function testFrontFetchesAreFiltered(): void
    {
        [$service, $seenScopes] = $this->service($this->context(false));

        $this->assertSame([self::TREE_ROWS[0]], $service->getTree(1));
        $this->assertSame([['item_id' => 1, 'url' => '/company']], $service->getUtilityMenus(1));
        $this->assertSame([['item_id' => 1, 'url' => '/company']], $service->getFooterMenus(1));
        $this->assertSame([['item_id' => 1, 'url' => '/company']], $service->getMypageMenus(1));

        $this->assertSame(['tree', 'utility', 'footer', 'mypage'], $seenScopes->getArrayCopy());
    }

    public function testHierarchyInheritsTheTreeFilter(): void
    {
        [$service, $seenScopes] = $this->service($this->context(false));

        $hierarchy = $service->getTreeHierarchy(1);

        $this->assertCount(1, $hierarchy);
        $this->assertSame(['tree'], $seenScopes->getArrayCopy());
    }

    public function testAdminFetchesAreNotFiltered(): void
    {
        [$service, $seenScopes] = $this->service($this->context(true));

        $this->assertSame(self::ROWS, $service->getUtilityMenus(1));
        $this->assertSame(self::ROWS, $service->getFooterMenus(1));
        $this->assertSame(self::ROWS, $service->getMypageMenus(1));
        $this->assertSame(self::TREE_ROWS, $service->getTree(1));

        $this->assertSame([], $seenScopes->getArrayCopy());
    }

    public function testMissingContextOrDispatcherIsNotAnError(): void
    {
        [$serviceWithoutContext] = $this->service(null);
        $this->assertSame(self::ROWS, $serviceWithoutContext->getUtilityMenus(1));

        $bare = new MenuService(
            $this->createMock(MenuItemRepository::class),
            $this->createMock(MenuTreeRepository::class),
            $this->createMock(CodeGenerator::class)
        );
        $this->assertSame([], $bare->getUtilityMenus(1));
    }
}
