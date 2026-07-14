<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Packages\Shop\Repository\OrderFieldRepository;
use Mublo\Packages\Shop\Service\OrderFieldService;
use Mublo\Service\Member\FieldEncryptionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrderFieldServiceBoundaryTest extends TestCase
{
    private OrderFieldRepository&MockObject $repository;
    private OrderFieldService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(OrderFieldRepository::class);
        $this->service = new OrderFieldService(
            $this->repository,
            $this->createMock(FieldEncryptionService::class)
        );
    }

    public function testRequiredFieldCannotBeBypassedWithEmptyPayload(): void
    {
        $this->repository->method('findActiveByDomain')->with(7)->willReturn([
            $this->field(11, required: true),
        ]);

        $result = $this->service->validateValues(7, []);

        self::assertTrue($result->isFailure());
        self::assertStringContainsString('필수 입력', $result->getMessage());
    }

    public function testUnknownOrAdminOnlyFieldIsRejected(): void
    {
        $this->repository->method('findActiveByDomain')->with(7)->willReturn([
            $this->field(11),
        ]);

        $result = $this->service->validateValues(7, [99 => 'forged']);

        self::assertTrue($result->isFailure());
        self::assertStringContainsString('유효하지 않은', $result->getMessage());
    }

    public function testCannotUpdateFieldOwnedByAnotherDomain(): void
    {
        $this->repository->expects(self::once())
            ->method('findFieldInDomain')
            ->with(7, 11)
            ->willReturn(null);
        $this->repository->expects(self::never())->method('updateFieldInDomain');

        $result = $this->service->saveField(7, [
            'field_id' => 11,
            'field_name' => 'delivery_note',
            'field_label' => '배송 메모',
        ]);

        self::assertTrue($result->isFailure());
    }

    public function testCannotDeleteFieldOwnedByAnotherDomain(): void
    {
        $this->repository->expects(self::once())
            ->method('findFieldInDomain')
            ->with(7, 11)
            ->willReturn(null);
        $this->repository->expects(self::never())->method('deleteFieldInDomain');

        self::assertTrue($this->service->deleteField(7, 11)->isFailure());
    }

    public function testFinalSaveBoundaryRejectsUnknownField(): void
    {
        $this->repository->method('findActiveByDomain')->with(7)->willReturn([
            $this->field(11),
        ]);
        $this->repository->expects(self::never())->method('saveValue');

        $result = $this->service->saveValues('ORD-1', 7, [99 => 'forged']);

        self::assertTrue($result->isFailure());
    }

    public function testFrontLookupCarriesDomainAndVisibilityBoundary(): void
    {
        $expected = $this->field(11, type: 'file');
        $this->repository->expects(self::once())
            ->method('findFieldInDomain')
            ->with(7, 11, true)
            ->willReturn($expected);

        self::assertSame($expected, $this->service->getField(7, 11, true));
    }

    private function field(int $id, bool $required = false, string $type = 'text'): array
    {
        return [
            'field_id' => $id,
            'field_name' => 'field_' . $id,
            'field_label' => '필드 ' . $id,
            'field_type' => $type,
            'field_options' => null,
            'field_config' => null,
            'is_encrypted' => 0,
            'is_required' => $required ? 1 : 0,
            'is_active' => 1,
            'is_admin_only' => 0,
        ];
    }
}
