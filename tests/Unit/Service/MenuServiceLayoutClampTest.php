<?php

namespace Tests\Unit\Service;

use Mublo\Entity\Menu\MenuItem;
use Mublo\Infrastructure\Code\CodeGenerator;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Menu\MenuItemRepository;
use Mublo\Repository\Menu\MenuTreeRepository;
use Mublo\Service\Menu\MenuService;
use PHPUnit\Framework\TestCase;

/**
 * 레이아웃 오버라이드 저장 경계 검증.
 *
 * 관리자 폼은 고정 드롭다운이지만, 조작된 요청이 garbage·범위 밖 값을 보낼 수 있다.
 * Service 는 저장 전에 유효 범위(layout_type 1~4, sidebar width 1~2000)만 통과시키고
 * 나머지는 NULL(상속)로 떨궈, renderer 가 받기 전에 데이터를 깨끗하게 만든다.
 */
class MenuServiceLayoutClampTest extends TestCase
{
    /**
     * createItem 이 itemRepository->create() 에 넘기는 insertData 를 캡처한다.
     */
    private function captureInsert(array $formData): array
    {
        $captured = [];

        $itemRepo = $this->createMock(MenuItemRepository::class);
        $itemRepo->method('create')->willReturnCallback(function (array $data) use (&$captured): int {
            $captured = $data;
            return 1;
        });

        $codeGen = $this->createMock(CodeGenerator::class);
        $codeGen->method('generate')->willReturn('testcode');

        $service = new MenuService(
            $itemRepo,
            $this->createMock(MenuTreeRepository::class),
            $codeGen,
            null
        );

        $service->createItem(1, ['label' => '테스트'] + $formData);

        return $captured;
    }

    public function testValidLayoutTypeIsStored(): void
    {
        $this->assertSame(3, $this->captureInsert(['layout_type' => '3'])['layout_type']);
    }

    public function testGarbageLayoutTypeBecomesNull(): void
    {
        $this->assertNull($this->captureInsert(['layout_type' => 'abc'])['layout_type']);
    }

    public function testNonIntegerFormatLayoutTypeBecomesNull(): void
    {
        // (int) 캐스트라면 '1abc'→1, '3.9'→3 으로 조용히 통과하지만, 엄격 파싱은 상속(NULL)로 떨군다.
        $this->assertNull($this->captureInsert(['layout_type' => '1abc'])['layout_type']);
        $this->assertNull($this->captureInsert(['layout_type' => '3.9'])['layout_type']);
    }

    public function testOutOfRangeLayoutTypeBecomesNull(): void
    {
        // 0(전체보다 작음)·999(양쪽보다 큼) 모두 유효 범위 밖 → 상속(NULL)
        $this->assertNull($this->captureInsert(['layout_type' => '0'])['layout_type']);
        $this->assertNull($this->captureInsert(['layout_type' => '999'])['layout_type']);
    }

    public function testEmptyLayoutTypeStaysInherit(): void
    {
        $this->assertNull($this->captureInsert(['layout_type' => ''])['layout_type']);
    }

    public function testSidebarWidthRange(): void
    {
        $this->assertSame(320, $this->captureInsert(['sidebar_left_width' => '320'])['sidebar_left_width']);
        // 0 이하·상한 초과는 상속(NULL)
        $this->assertNull($this->captureInsert(['sidebar_left_width' => '0'])['sidebar_left_width']);
        $this->assertNull($this->captureInsert(['sidebar_left_width' => '99999'])['sidebar_left_width']);
    }

    public function testSidebarMobileNormalizedToZeroOrOne(): void
    {
        $this->assertSame(1, $this->captureInsert(['sidebar_left_mobile' => '1'])['sidebar_left_mobile']);
        $this->assertSame(0, $this->captureInsert(['sidebar_left_mobile' => '0'])['sidebar_left_mobile']);
        $this->assertNull($this->captureInsert(['sidebar_left_mobile' => ''])['sidebar_left_mobile']);
    }

    public function testSidebarMobileRejectsNonBinary(): void
    {
        // 정확히 0/1 만 허용 — '2'→1 로 뭉개거나 'abc'→0 으로 저장하지 않고 상속(NULL).
        $this->assertNull($this->captureInsert(['sidebar_left_mobile' => '2'])['sidebar_left_mobile']);
        $this->assertNull($this->captureInsert(['sidebar_left_mobile' => 'abc'])['sidebar_left_mobile']);
    }

    /**
     * 목록 인라인 편집(bulkUpdateItems)이 item_id=1(도메인 1 소유)에 대해
     * itemRepository->updateInDomain() 에 넘기는 updateData 를 캡처한다.
     */
    private function captureBulkUpdate(string $layoutValue): array
    {
        $captured = [];

        $db = $this->createMock(Database::class);
        // 트랜잭션 콜백을 그대로 실행
        $db->method('transaction')->willReturnCallback(fn (callable $cb) => $cb());

        $itemRepo = $this->createMock(MenuItemRepository::class);
        $itemRepo->method('getDb')->willReturn($db);
        // item 1 은 도메인 1 소유 → 소유권 사전 검증 통과
        $itemRepo->method('findOwnedItemIds')->willReturn([1]);
        $itemRepo->method('updateInDomain')->willReturnCallback(
            function (int $id, int $domainId, array $data) use (&$captured): int {
                $captured = $data;
                return 1;
            }
        );

        $service = new MenuService(
            $itemRepo,
            $this->createMock(MenuTreeRepository::class),
            $this->createMock(CodeGenerator::class),
            null
        );

        $service->bulkUpdateItems(1, [1], ['layout_type' => [1 => $layoutValue]]);

        return $captured;
    }

    /**
     * 소유권 사전 검증 결과(findOwnedItemIds)를 지정해 bulkUpdateItems 를 실행한다.
     * updateInDomain 호출 여부를 함께 반환해, 거부 시 아무 행도 건드리지 않았음을 검증한다.
     *
     * @return array{result: \Mublo\Core\Result\Result, updated: bool}
     */
    private function runBulkWithOwnership(int $domainId, array $requestIds, array $ownedIds): array
    {
        $updated = false;

        $db = $this->createMock(Database::class);
        $db->method('transaction')->willReturnCallback(fn (callable $cb) => $cb());

        $itemRepo = $this->createMock(MenuItemRepository::class);
        $itemRepo->method('getDb')->willReturn($db);
        $itemRepo->method('findOwnedItemIds')->willReturn($ownedIds);
        $itemRepo->method('updateInDomain')->willReturnCallback(
            function () use (&$updated): int {
                $updated = true;
                return 1;
            }
        );

        $service = new MenuService(
            $itemRepo,
            $this->createMock(MenuTreeRepository::class),
            $this->createMock(CodeGenerator::class),
            null
        );

        // 모든 요청 아이템에 대해 값 하나씩 준다(변경거리 확보).
        $fieldData = ['is_active' => []];
        foreach ($requestIds as $id) {
            $fieldData['is_active'][(int) $id] = '0';
        }

        $result = $service->bulkUpdateItems($domainId, $requestIds, $fieldData);

        return ['result' => $result, 'updated' => $updated];
    }

    public function testBulkUpdateStoresValidLayout(): void
    {
        $this->assertSame(2, $this->captureBulkUpdate('2')['layout_type']);
    }

    public function testBulkUpdateEmptyLayoutBecomesInheritNull(): void
    {
        // 목록 select 에서 '상속'(빈값) 선택 → NULL 저장(0 아님)
        $captured = $this->captureBulkUpdate('');
        $this->assertArrayHasKey('layout_type', $captured);
        $this->assertNull($captured['layout_type']);
    }

    public function testBulkUpdateOutOfRangeLayoutBecomesNull(): void
    {
        $this->assertNull($this->captureBulkUpdate('999')['layout_type']);
    }

    // ---------------------------------------------------------------------
    // 도메인 소유권 경계 (IDOR 방어)
    // ---------------------------------------------------------------------

    public function testBulkUpdateRejectsForeignDomainItem(): void
    {
        // 도메인 1 관리자가 다른 도메인 소유 item(5)을 crafted 요청 → 소유 목록에 없음.
        $outcome = $this->runBulkWithOwnership(1, [5], []);

        $this->assertTrue($outcome['result']->isFailure());
        $this->assertFalse($outcome['updated'], '거부 시 어떤 행도 갱신하면 안 된다');
    }

    public function testBulkUpdateRejectsMixedDomains(): void
    {
        // 요청 [1,2] 중 1만 이 도메인 소유 → 부분 수정도 하지 않고 전체 거부.
        $outcome = $this->runBulkWithOwnership(1, [1, 2], [1]);

        $this->assertTrue($outcome['result']->isFailure());
        $this->assertFalse($outcome['updated']);
    }

    public function testBulkUpdateRejectsNonexistentId(): void
    {
        // 존재하지 않는 ID → 소유 목록에 없음 → 거부.
        $outcome = $this->runBulkWithOwnership(1, [999999], []);

        $this->assertTrue($outcome['result']->isFailure());
        $this->assertFalse($outcome['updated']);
    }

    public function testBulkUpdateProceedsWhenAllOwned(): void
    {
        // 요청 [1,2] 가 모두 이 도메인 소유 → 정상 진행.
        $outcome = $this->runBulkWithOwnership(1, [1, 2], [1, 2]);

        $this->assertTrue($outcome['result']->isSuccess());
        $this->assertTrue($outcome['updated']);
    }

    /**
     * updateItem 이 itemRepository->update() 에 넘기는 updateData 를 캡처한다.
     */
    private function captureUpdate(array $formData): array
    {
        $captured = [];

        // updateItem 은 menu_items 수정과 menu_tree 경로명 재작성을 한 트랜잭션으로 묶는다.
        // 더블도 그 경계를 흉내내야 콜백 안의 update() 가 실제로 실행된다.
        $db = $this->createMock(Database::class);
        $db->method('transaction')->willReturnCallback(fn (callable $cb) => $cb());

        $itemRepo = $this->createMock(MenuItemRepository::class);
        $itemRepo->method('getDb')->willReturn($db);
        $itemRepo->method('find')->willReturn(
            MenuItem::fromArray(['item_id' => 1, 'domain_id' => 1, 'label' => '기존'])
        );
        $itemRepo->method('update')->willReturnCallback(function (int $id, array $data) use (&$captured): int {
            $captured = $data;
            return 1;
        });

        $service = new MenuService(
            $itemRepo,
            $this->createMock(MenuTreeRepository::class),
            $this->createMock(CodeGenerator::class),
            null
        );

        $service->updateItem(1, $formData, null);

        return $captured;
    }

    public function testUpdateStoresValidLayout(): void
    {
        $this->assertSame(4, $this->captureUpdate(['layout_type' => '4'])['layout_type']);
    }

    public function testUpdateRejectsNonIntegerFormat(): void
    {
        $this->assertNull($this->captureUpdate(['layout_type' => '1abc'])['layout_type']);
        $this->assertNull($this->captureUpdate(['layout_type' => '3.9'])['layout_type']);
    }

    public function testUpdateEmptyLayoutBecomesInheritNull(): void
    {
        $captured = $this->captureUpdate(['layout_type' => '']);
        $this->assertArrayHasKey('layout_type', $captured);
        $this->assertNull($captured['layout_type']);
    }

    public function testUpdateSidebarMobileRejectsNonBinary(): void
    {
        $this->assertNull($this->captureUpdate(['sidebar_left_mobile' => '2'])['sidebar_left_mobile']);
    }
}
