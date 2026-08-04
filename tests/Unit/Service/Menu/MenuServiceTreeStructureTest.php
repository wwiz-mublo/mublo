<?php

namespace Tests\Unit\Service\Menu;

use Mublo\Entity\Menu\MenuItem;
use Mublo\Infrastructure\Code\CodeGenerator;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Menu\MenuItemRepository;
use Mublo\Repository\Menu\MenuTreeRepository;
use Mublo\Service\Menu\MenuService;
use PHPUnit\Framework\TestCase;

/**
 * 메뉴 트리 쓰기 경로의 구조 방어와 경로명 재작성 검증.
 *
 * 트리는 관리자 드래그앤드롭 UI가 만들지만 서버는 그 UI를 신뢰하지 않는다.
 * 순환(A>B>A)은 path_code 에 같은 코드를 두 번 넣어 라벨 변경 시 어느 자리를
 * 고칠지 결정할 수 없게 만들고, 과도한 깊이는 path_code(VARCHAR 255)를 넘긴다.
 */
class MenuServiceTreeStructureTest extends TestCase
{
    /** 콜백을 그대로 실행하는 트랜잭션 더블. */
    private function db(): Database
    {
        $db = $this->createMock(Database::class);
        $db->method('transaction')->willReturnCallback(fn (callable $cb) => $cb());

        return $db;
    }

    /**
     * @param array<int, array{menu_code:string, label:string}> $items
     * @param array<int, array<string,mixed>> $capturedNodes 생성된 노드가 여기에 쌓인다
     */
    private function service(array $items, array &$capturedNodes): MenuService
    {
        $itemRepo = $this->createMock(MenuItemRepository::class);
        $itemRepo->method('getDb')->willReturn($this->db());
        $itemRepo->method('findByDomain')->willReturn($items);
        $itemRepo->method('findByMenuCode')->willReturnCallback(
            function (int $domainId, string $code) use ($items): ?array {
                foreach ($items as $item) {
                    if ($item['menu_code'] === $code) {
                        return $item;
                    }
                }

                return null;
            }
        );

        $treeRepo = $this->createMock(MenuTreeRepository::class);
        $treeRepo->method('getDb')->willReturn($this->db());
        $treeRepo->method('create')->willReturnCallback(
            function (array $data) use (&$capturedNodes): int {
                $capturedNodes[] = $data;
                return count($capturedNodes);
            }
        );

        return new MenuService(
            $itemRepo,
            $treeRepo,
            $this->createMock(CodeGenerator::class),
            null
        );
    }

    // ────────────────────────────────
    // saveTree 구조 검증
    // ────────────────────────────────

    public function testSaveTreeRejectsCycle(): void
    {
        $nodes = [];
        $service = $this->service(
            [
                ['menu_code' => 'aaa', 'label' => '홈'],
                ['menu_code' => 'bbb', 'label' => '소개'],
            ],
            $nodes
        );

        // 홈 > 소개 > 홈
        $result = $service->saveTree(1, [
            ['menu_code' => 'aaa', 'children' => [
                ['menu_code' => 'bbb', 'children' => [
                    ['menu_code' => 'aaa'],
                ]],
            ]],
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('자기 하위', $result->getMessage());

        // 거부는 저장을 시작하기 전에 일어난다 — 기존 트리를 지우지 않는다.
        $this->assertSame([], $nodes);
    }

    public function testSaveTreeRejectsDirectSelfNesting(): void
    {
        $nodes = [];
        $service = $this->service([['menu_code' => 'aaa', 'label' => '홈']], $nodes);

        $result = $service->saveTree(1, [
            ['menu_code' => 'aaa', 'children' => [['menu_code' => 'aaa']]],
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertSame([], $nodes);
    }

    public function testSaveTreeRejectsExcessiveDepth(): void
    {
        $items = [];
        for ($i = 1; $i <= 12; $i++) {
            $items[] = ['menu_code' => 'm' . $i, 'label' => '메뉴' . $i];
        }

        $nodes = [];
        $service = $this->service($items, $nodes);

        // 12단계 중첩 (상한 10 초과)
        $payload = ['menu_code' => 'm12'];
        for ($i = 11; $i >= 1; $i--) {
            $payload = ['menu_code' => 'm' . $i, 'children' => [$payload]];
        }

        $result = $service->saveTree(1, [$payload]);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('깊이', $result->getMessage());
        $this->assertSame([], $nodes);
    }

    public function testSaveTreeAcceptsMaxDepth(): void
    {
        $items = [];
        for ($i = 1; $i <= 10; $i++) {
            $items[] = ['menu_code' => 'm' . $i, 'label' => '메뉴' . $i];
        }

        $nodes = [];
        $service = $this->service($items, $nodes);

        $payload = ['menu_code' => 'm10'];
        for ($i = 9; $i >= 1; $i--) {
            $payload = ['menu_code' => 'm' . $i, 'children' => [$payload]];
        }

        $result = $service->saveTree(1, [$payload]);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(10, $nodes);
        $this->assertSame(10, $nodes[9]['depth']);
    }

    /**
     * 같은 메뉴가 서로 다른 가지에 놓이는 것은 정상이다 — 막는 것은 자기 조상뿐이다.
     */
    public function testSaveTreeAllowsSameMenuInDifferentBranches(): void
    {
        $nodes = [];
        $service = $this->service(
            [
                ['menu_code' => 'aaa', 'label' => '홈'],
                ['menu_code' => 'bbb', 'label' => '소개'],
                ['menu_code' => 'ccc', 'label' => '문의'],
            ],
            $nodes
        );

        $result = $service->saveTree(1, [
            ['menu_code' => 'aaa', 'children' => [['menu_code' => 'ccc']]],
            ['menu_code' => 'bbb', 'children' => [['menu_code' => 'ccc']]],
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(4, $nodes);
    }

    public function testSaveTreeBuildsPathCodeAndPathName(): void
    {
        $nodes = [];
        $service = $this->service(
            [
                ['menu_code' => 'aaa', 'label' => '홈'],
                ['menu_code' => 'bbb', 'label' => '회사소개'],
                ['menu_code' => 'ccc', 'label' => '오시는길'],
            ],
            $nodes
        );

        $result = $service->saveTree(1, [
            ['menu_code' => 'aaa', 'children' => [
                ['menu_code' => 'bbb', 'children' => [
                    ['menu_code' => 'ccc'],
                ]],
            ]],
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('aaa>bbb>ccc', $nodes[2]['path_code']);
        $this->assertSame('홈>회사소개>오시는길', $nodes[2]['path_name']);
        $this->assertSame('aaa>bbb', $nodes[2]['parent_code']);
        $this->assertSame(3, $nodes[2]['depth']);
    }

    /**
     * 경로명은 재귀 인자로 내려온다 — 방금 INSERT 한 부모를 다시 SELECT 하지 않는다.
     */
    public function testSaveTreeDoesNotRereadInsertedParents(): void
    {
        $nodes = [];

        $itemRepo = $this->createMock(MenuItemRepository::class);
        $itemRepo->method('getDb')->willReturn($this->db());
        $itemRepo->method('findByDomain')->willReturn([
            ['menu_code' => 'aaa', 'label' => '홈'],
            ['menu_code' => 'bbb', 'label' => '소개'],
        ]);

        $treeRepo = $this->createMock(MenuTreeRepository::class);
        $treeRepo->method('getDb')->willReturn($this->db());
        $treeRepo->method('create')->willReturnCallback(
            function (array $data) use (&$nodes): int {
                $nodes[] = $data;
                return count($nodes);
            }
        );
        $treeRepo->expects($this->never())->method('findByPathCode');

        $service = new MenuService(
            $itemRepo,
            $treeRepo,
            $this->createMock(CodeGenerator::class),
            null
        );

        $service->saveTree(1, [
            ['menu_code' => 'aaa', 'children' => [['menu_code' => 'bbb']]],
        ]);

        $this->assertSame('홈>소개', $nodes[1]['path_name']);
    }

    // ────────────────────────────────
    // addToTree 구조 검증
    // ────────────────────────────────

    public function testAddToTreeRejectsSelfAsAncestor(): void
    {
        $itemRepo = $this->createMock(MenuItemRepository::class);
        $itemRepo->method('getDb')->willReturn($this->db());
        $itemRepo->method('findByMenuCode')->willReturn(['menu_code' => 'aaa', 'label' => '홈']);

        // 부모 경로 자체는 실재한다 — 막는 이유는 부재가 아니라 순환이다.
        $treeRepo = $this->createMock(MenuTreeRepository::class);
        $treeRepo->method('getDb')->willReturn($this->db());
        $treeRepo->method('findByPathCode')->willReturn([
            'depth' => 3,
            'path_name' => '소개>홈>문의',
        ]);
        $treeRepo->expects($this->never())->method('create');

        $service = new MenuService(
            $itemRepo,
            $treeRepo,
            $this->createMock(CodeGenerator::class),
            null
        );

        $result = $service->addToTree(1, 'aaa', 'bbb>aaa>ccc');

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('자기 하위', $result->getMessage());
    }

    public function testAddToTreeRejectsDepthOverflow(): void
    {
        $nodes = [];

        $itemRepo = $this->createMock(MenuItemRepository::class);
        $itemRepo->method('getDb')->willReturn($this->db());
        $itemRepo->method('findByMenuCode')->willReturn(['menu_code' => 'zzz', 'label' => '막내']);

        $treeRepo = $this->createMock(MenuTreeRepository::class);
        $treeRepo->method('getDb')->willReturn($this->db());
        $treeRepo->method('findByPathCode')->willReturn([
            'depth' => 10,
            'path_name' => '깊은경로',
        ]);
        $treeRepo->expects($this->never())->method('create');

        $service = new MenuService(
            $itemRepo,
            $treeRepo,
            $this->createMock(CodeGenerator::class),
            null
        );

        $result = $service->addToTree(1, 'zzz', 'parent');

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('깊이', $result->getMessage());
    }

    // ────────────────────────────────
    // 라벨 변경 → 경로명 재작성
    // ────────────────────────────────

    /**
     * @param array<int, array<string,mixed>> $treeNodes
     * @param array<int, array{node_id:int, data:array<string,mixed>}> $updates
     */
    private function serviceForRename(array $treeNodes, array $labels, array &$updates): MenuService
    {
        $items = [];
        foreach ($labels as $code => $label) {
            $items[] = ['menu_code' => $code, 'label' => $label];
        }

        $itemRepo = $this->createMock(MenuItemRepository::class);
        $itemRepo->method('getDb')->willReturn($this->db());
        $itemRepo->method('findByDomain')->willReturn($items);
        $itemRepo->method('find')->willReturn(
            MenuItem::fromArray([
                'item_id' => 1,
                'domain_id' => 1,
                'menu_code' => 'bbb',
                'label' => '옛이름',
            ])
        );
        $itemRepo->method('update')->willReturn(1);

        $treeRepo = $this->createMock(MenuTreeRepository::class);
        $treeRepo->method('getDb')->willReturn($this->db());
        $treeRepo->method('findByDomain')->willReturn($treeNodes);
        $treeRepo->method('update')->willReturnCallback(
            function (int $nodeId, array $data) use (&$updates): int {
                $updates[] = ['node_id' => $nodeId, 'data' => $data];
                return 1;
            }
        );

        return new MenuService(
            $itemRepo,
            $treeRepo,
            $this->createMock(CodeGenerator::class),
            null
        );
    }

    public function testRenamePropagatesToDescendantPathNames(): void
    {
        $updates = [];
        $service = $this->serviceForRename(
            [
                ['node_id' => 1, 'path_code' => 'aaa', 'path_name' => '홈'],
                ['node_id' => 2, 'path_code' => 'aaa>bbb', 'path_name' => '홈>옛이름'],
                ['node_id' => 3, 'path_code' => 'aaa>bbb>ccc', 'path_name' => '홈>옛이름>오시는길'],
            ],
            ['aaa' => '홈', 'bbb' => '새이름', 'ccc' => '오시는길'],
            $updates
        );

        $service->updateItem(1, ['label' => '새이름'], null);

        $this->assertCount(2, $updates);
        $this->assertSame('홈>새이름', $updates[0]['data']['path_name']);
        $this->assertSame('홈>새이름>오시는길', $updates[1]['data']['path_name']);
    }

    public function testRenameSkipsUnrelatedPaths(): void
    {
        $updates = [];
        $service = $this->serviceForRename(
            [
                ['node_id' => 1, 'path_code' => 'aaa', 'path_name' => '홈'],
                ['node_id' => 2, 'path_code' => 'aaa>ccc', 'path_name' => '홈>문의'],
            ],
            ['aaa' => '홈', 'bbb' => '새이름', 'ccc' => '문의'],
            $updates
        );

        $service->updateItem(1, ['label' => '새이름'], null);

        $this->assertSame([], $updates);
    }

    /**
     * 순환은 이제 저장 단계에서 막지만, 그 방어가 생기기 전에 저장된 트리가 남아 있을 수 있다.
     * 조각을 찾아 갈아끼우는 예전 방식은 첫 자리만 고쳐 뒤쪽에 옛 이름을 남겼다.
     * path_code 를 통째로 다시 번역하면 그런 데이터도 한 번에 정리된다.
     */
    public function testRenameFixesLegacyPathWithRepeatedCode(): void
    {
        $updates = [];
        $service = $this->serviceForRename(
            [
                ['node_id' => 1, 'path_code' => 'bbb>aaa>bbb', 'path_name' => '옛이름>홈>옛이름'],
            ],
            ['aaa' => '홈', 'bbb' => '새이름'],
            $updates
        );

        $service->updateItem(1, ['label' => '새이름'], null);

        $this->assertCount(1, $updates);
        $this->assertSame('새이름>홈>새이름', $updates[0]['data']['path_name']);
    }

    /**
     * 아이템이 지워진 잔여 노드는 라벨을 찾을 수 없다. 그 자리는 비우지 않고
     * 기존 조각을 유지한다 — 이름을 잃는 것보다 옛 이름이 남는 편이 낫다.
     */
    public function testRenameKeepsSegmentWhenLabelMissing(): void
    {
        $updates = [];
        $service = $this->serviceForRename(
            [
                ['node_id' => 1, 'path_code' => 'gone>bbb', 'path_name' => '사라진메뉴>옛이름'],
            ],
            ['bbb' => '새이름'],
            $updates
        );

        $service->updateItem(1, ['label' => '새이름'], null);

        $this->assertCount(1, $updates);
        $this->assertSame('사라진메뉴>새이름', $updates[0]['data']['path_name']);
    }
}
