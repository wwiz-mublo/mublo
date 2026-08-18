<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Packages\Shop\Enum\ClaimStatus;
use Mublo\Packages\Shop\Service\ClaimStateMachine;
use Tests\Shop\TestCase;

final class ClaimStateMachineTest extends TestCase
{
    public function testDomesticExchangeHappyPathIsExplicit(): void
    {
        $machine = new ClaimStateMachine();
        $path = [
            ClaimStatus::REQUESTED,
            ClaimStatus::ACCEPTED,
            ClaimStatus::COLLECTING,
            ClaimStatus::COLLECTED,
            ClaimStatus::INSPECTING,
            ClaimStatus::READY_TO_SHIP,
            ClaimStatus::RESHIPPING,
            ClaimStatus::COMPLETED,
        ];

        for ($i = 0, $last = count($path) - 1; $i < $last; $i++) {
            $this->assertTrue($machine->canTransition($path[$i]->value, $path[$i + 1]->value));
        }
        $this->assertFalse($machine->canTransition('REQUESTED', 'COMPLETED'));
        $this->assertFalse($machine->canTransition('COMPLETED', 'REQUESTED'));
    }

    public function testReturnHappyPathEndsWithRefundInsteadOfReshipping(): void
    {
        $machine = new ClaimStateMachine();
        $path = [
            ClaimStatus::REQUESTED,
            ClaimStatus::ACCEPTED,
            ClaimStatus::COLLECTING,
            ClaimStatus::COLLECTED,
            ClaimStatus::INSPECTING,
            ClaimStatus::READY_TO_REFUND,
            ClaimStatus::COMPLETED,
        ];

        for ($i = 0, $last = count($path) - 1; $i < $last; $i++) {
            $this->assertTrue($machine->canTransition($path[$i]->value, $path[$i + 1]->value, 'RETURN'));
        }
    }

    public function testBranchesAfterInspectionAreTypeSpecific(): void
    {
        $machine = new ClaimStateMachine();

        // 반품은 바꿔 보낼 상품이 없으므로 재출고로 샐 수 없다
        $this->assertFalse($machine->canTransition('INSPECTING', 'READY_TO_SHIP', 'RETURN'));
        // 교환은 환불로 끝나지 않는다
        $this->assertFalse($machine->canTransition('INSPECTING', 'READY_TO_REFUND', 'EXCHANGE'));
        // 검수 거절은 양쪽 공통
        $this->assertTrue($machine->canTransition('INSPECTING', 'REJECTED', 'RETURN'));
        $this->assertTrue($machine->canTransition('INSPECTING', 'REJECTED', 'EXCHANGE'));
    }

    public function testInspectionRejectionMustBeReturnedBeforeClosure(): void
    {
        $machine = new ClaimStateMachine();

        $this->assertTrue($machine->canTransition('INSPECTING', 'REJECTED'));
        $this->assertTrue($machine->canTransition('REJECTED', 'RETURNING'));
        $this->assertTrue($machine->canTransition('RETURNING', 'CLOSED'));
        $this->assertFalse($machine->canTransition('REJECTED', 'CLOSED'));
    }
}
