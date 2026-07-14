<?php

namespace Tests\Shop\Unit\Service;

use Tests\Shop\TestCase;
use Mublo\Packages\Shop\Service\ShipmentService;
use Mublo\Packages\Shop\Repository\ShipmentRepository;

class ShipmentBulkTest extends TestCase
{
    private function service(): array
    {
        $repo = $this->createMock(ShipmentRepository::class);
        $repo->method('getActiveCompanies')->willReturn([
            ['company_id' => 1, 'company_name' => 'CJ대한통운'],
            ['company_id' => 2, 'company_name' => '한진택배'],
        ]);
        // create는 성공 시 shipment_id 반환
        $repo->method('create')->willReturn(10);

        return [new ShipmentService($repo), $repo];
    }

    public function testRegistersValidRowsAndMapsCourier(): void
    {
        [$service, $repo] = $this->service();

        // 유효 행은 registerShipment→create 호출, company_id 매핑 검증
        $repo->expects($this->once())
            ->method('create')
            ->with($this->callback(function (array $data): bool {
                return $data['order_no'] === 'ORD1'
                    && $data['invoice_no'] === '123456'
                    && $data['company_id'] === 1; // CJ대한통운 → 1
            }))
            ->willReturn(10);

        $rows = [
            ['order_no' => 'ORD1', 'courier' => 'CJ대한통운', 'invoice_no' => '123456'],
        ];
        $result = $service->bulkRegisterFromRows($rows, ['ORD1']);

        $this->assertSame(1, $result['success']);
        $this->assertSame([], $result['failed']);
    }

    public function testRejectsInvalidRows(): void
    {
        [$service] = $this->service();

        $rows = [
            ['order_no' => '',      'courier' => '한진택배', 'invoice_no' => '111'],   // 주문번호 없음
            ['order_no' => 'ORDX',  'courier' => '한진택배', 'invoice_no' => '222'],   // 도메인 밖(미검증)
            ['order_no' => 'ORD1',  'courier' => '한진택배', 'invoice_no' => ''],      // 송장번호 없음
            ['order_no' => '',      'courier' => '',        'invoice_no' => ''],       // 빈 행 → skip
        ];
        $result = $service->bulkRegisterFromRows($rows, ['ORD1']);

        $this->assertSame(0, $result['success']);
        $reasons = array_column($result['failed'], 'reason');
        $this->assertContains('주문번호 없음', $reasons);
        $this->assertContains('존재하지 않거나 접근할 수 없는 주문', $reasons);
        $this->assertContains('송장번호 없음', $reasons);
        $this->assertCount(3, $result['failed']); // 빈 행은 제외
    }

    public function testUnknownCourierStillRegistersWithNullCompany(): void
    {
        [$service, $repo] = $this->service();

        $repo->expects($this->once())
            ->method('create')
            ->with($this->callback(fn(array $d): bool => $d['company_id'] === null))
            ->willReturn(10);

        $result = $service->bulkRegisterFromRows(
            [['order_no' => 'ORD1', 'courier' => '없는택배', 'invoice_no' => '999']],
            ['ORD1']
        );

        $this->assertSame(1, $result['success']);
    }
}
