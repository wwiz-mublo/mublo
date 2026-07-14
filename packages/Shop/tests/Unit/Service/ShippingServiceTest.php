<?php
/**
 * packages/Shop/tests/Unit/Service/ShippingServiceTest.php
 *
 * ShippingService 단위 테스트
 *
 * ShippingRepository를 Mock으로 대체하여 배송 템플릿 비즈니스 로직만 테스트합니다.
 *
 * 검증 항목:
 * - getList() — 도메인별 템플릿 목록
 * - getTemplate() — 존재/미존재 처리
 * - create() — 이름 필수 검증, 데이터 정규화(JSON/int/bool 필드)
 * - update() — 미존재 처리, 정규화
 * - delete() — 미존재 처리
 * - getDeliveryCompanies() — 택배사 목록
 */

namespace Tests\Shop\Unit\Service;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Service\ShippingService;
use Mublo\Packages\Shop\Repository\ShippingRepository;
use Mublo\Packages\Shop\Entity\ShippingTemplate;

class ShippingServiceTest extends TestCase
{
    private ShippingRepository $shippingRepo;
    private ShippingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shippingRepo = $this->createMock(ShippingRepository::class);
        $this->service      = new ShippingService($this->shippingRepo);
    }

    /**
     * 테스트용 ShippingTemplate 생성 헬퍼
     *
     * ShippingTemplate은 final 클래스라 Mock 불가 → fromArray()로 실제 객체 생성
     */
    private function makeTemplate(array $overrides = []): ShippingTemplate
    {
        return ShippingTemplate::fromArray(array_merge([
            'shipping_id'     => 1,
            'domain_id'       => 1,
            'name'            => '기본 배송',
            'shipping_method' => 'COND',
            'basic_cost'      => 3000,
            'free_threshold'  => 50000,
            'is_active'       => true,
            'created_at'      => '2025-01-01 00:00:00',
        ], $overrides));
    }

    // =========================================================
    // getList()
    // =========================================================

    public function testGetListReturnsSuccessWithItems(): void
    {
        $templates = [$this->makeTemplate(), $this->makeTemplate(['shipping_id' => 2])];
        $this->shippingRepo->method('getList')->willReturn($templates);

        $result = $this->service->getList(1);

        $this->assertTrue($result->isSuccess());
        $this->assertCount(2, $result->get('items'));
    }

    public function testGetListReturnsEmptyArrayWhenNoTemplates(): void
    {
        $this->shippingRepo->method('getList')->willReturn([]);

        $result = $this->service->getList(1);

        $this->assertTrue($result->isSuccess());
        $this->assertSame([], $result->get('items'));
    }

    // =========================================================
    // getTemplate()
    // =========================================================

    public function testGetTemplateReturnsSuccessWhenFound(): void
    {
        $this->shippingRepo->method('findInDomain')->willReturn($this->makeTemplate());

        $result = $this->service->getTemplate(1, 1);

        $this->assertTrue($result->isSuccess());
        $this->assertArrayHasKey('shipping_id', $result->get('template'));
    }

    public function testGetTemplateFailsWhenNotFound(): void
    {
        $this->shippingRepo->method('findInDomain')->willReturn(null);

        $result = $this->service->getTemplate(1, 999);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('찾을 수 없', $result->getMessage());
    }

    // =========================================================
    // create()
    // =========================================================

    public function testCreateFailsWhenNameIsEmpty(): void
    {
        $result = $this->service->create(1, ['name' => '', 'shipping_method' => 'FREE']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('이름', $result->getMessage());
    }

    public function testCreateFailsWhenNameIsMissing(): void
    {
        $result = $this->service->create(1, ['shipping_method' => 'FREE']);

        $this->assertTrue($result->isFailure());
    }

    public function testCreateSuccessReturnsShippingId(): void
    {
        $this->shippingRepo->method('create')->willReturn(10);

        $result = $this->service->create(1, [
            'name'            => '일반 배송',
            'shipping_method' => 'COND',
            'basic_cost'      => '3000',
            'free_threshold'  => '50000',
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(10, $result->get('shipping_id'));
    }

    public function testCreateSetsDomainId(): void
    {
        $capturedData = null;
        $this->shippingRepo
            ->method('create')
            ->willReturnCallback(function (array $data) use (&$capturedData) {
                $capturedData = $data;
                return 1;
            });

        $this->service->create(5, ['name' => '테스트 배송']);

        $this->assertSame(5, $capturedData['domain_id']);
    }

    public function testCreateNormalizesIntegerFields(): void
    {
        $capturedData = null;
        $this->shippingRepo
            ->method('create')
            ->willReturnCallback(function (array $data) use (&$capturedData) {
                $capturedData = $data;
                return 1;
            });

        $this->service->create(1, [
            'name'          => '테스트',
            'basic_cost'    => '3000',   // 문자열로 전달
            'free_threshold'=> '50000',
        ]);

        // 정수 필드는 int로 정규화
        $this->assertIsInt($capturedData['basic_cost']);
        $this->assertIsInt($capturedData['free_threshold']);
        $this->assertSame(3000, $capturedData['basic_cost']);
        $this->assertSame(50000, $capturedData['free_threshold']);
    }

    public function testCreateEncodesPriceRangesAsJson(): void
    {
        $capturedData = null;
        $this->shippingRepo
            ->method('create')
            ->willReturnCallback(function (array $data) use (&$capturedData) {
                $capturedData = $data;
                return 1;
            });

        $ranges = [
            ['min' => 0, 'max' => 9999, 'cost' => 3000],
            ['min' => 10000, 'max' => 99999, 'cost' => 2000],
        ];

        $this->service->create(1, ['name' => '금액별 배송', 'price_ranges' => $ranges]);

        // 배열이 JSON 문자열로 저장되는지 확인
        $this->assertIsString($capturedData['price_ranges']);
        $decoded = json_decode($capturedData['price_ranges'], true);
        $this->assertCount(2, $decoded);
    }

    public function testCreateNormalizesBooleanFields(): void
    {
        $capturedData = null;
        $this->shippingRepo
            ->method('create')
            ->willReturnCallback(function (array $data) use (&$capturedData) {
                $capturedData = $data;
                return 1;
            });

        $this->service->create(1, [
            'name'               => '테스트',
            'extra_cost_enabled' => true,
            'is_active'          => false,
        ]);

        // 불린 필드는 0/1로 저장
        $this->assertSame(1, $capturedData['extra_cost_enabled']);
        $this->assertSame(0, $capturedData['is_active']);
    }

    public function testCreateFailsWhenRepositoryReturnsNull(): void
    {
        $this->shippingRepo->method('create')->willReturn(null);

        $result = $this->service->create(1, ['name' => '테스트 배송']);

        $this->assertTrue($result->isFailure());
    }

    // =========================================================
    // update()
    // =========================================================

    public function testUpdateFailsWhenTemplateNotFound(): void
    {
        $this->shippingRepo->method('findInDomain')->willReturn(null);

        $result = $this->service->update(1, 999, ['name' => '수정된 이름']);

        $this->assertTrue($result->isFailure());
        $this->assertStringContainsString('찾을 수 없', $result->getMessage());
    }

    public function testUpdateSucceeds(): void
    {
        $this->shippingRepo->method('findInDomain')->willReturn($this->makeTemplate());
        $this->shippingRepo->method('updateInDomain')->willReturn(true);

        $result = $this->service->update(1, 1, ['name' => '수정된 배송', 'basic_cost' => '4000']);

        $this->assertTrue($result->isSuccess());
    }

    // =========================================================
    // delete()
    // =========================================================

    public function testDeleteFailsWhenTemplateNotFound(): void
    {
        $this->shippingRepo->method('findInDomain')->willReturn(null);

        $result = $this->service->delete(1, 999);

        $this->assertTrue($result->isFailure());
    }

    public function testDeleteSucceeds(): void
    {
        $this->shippingRepo->method('findInDomain')->willReturn($this->makeTemplate());
        $this->shippingRepo->method('deleteInDomain')->willReturn(true);

        $result = $this->service->delete(1, 1);

        $this->assertTrue($result->isSuccess());
    }

    public function testDeleteFailsWhenRepositoryFails(): void
    {
        $this->shippingRepo->method('findInDomain')->willReturn($this->makeTemplate());
        $this->shippingRepo->method('deleteInDomain')->willReturn(false);

        $result = $this->service->delete(1, 1);

        $this->assertTrue($result->isFailure());
    }

    // =========================================================
    // getDeliveryCompanies()
    // =========================================================

    public function testGetDeliveryCompaniesReturnsSuccessWithCompanies(): void
    {
        $companies = [
            ['id' => 1, 'name' => 'CJ대한통운'],
            ['id' => 2, 'name' => '한진택배'],
        ];
        $this->shippingRepo->method('getDeliveryCompanies')->willReturn($companies);

        $result = $this->service->getDeliveryCompanies();

        $this->assertTrue($result->isSuccess());
        $this->assertCount(2, $result->get('companies'));
    }
}
