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

    public function testInspectionRejectionMustBeReturnedBeforeClosure(): void
    {
        $machine = new ClaimStateMachine();

        $this->assertTrue($machine->canTransition('INSPECTING', 'REJECTED'));
        $this->assertTrue($machine->canTransition('REJECTED', 'RETURNING'));
        $this->assertTrue($machine->canTransition('RETURNING', 'CLOSED'));
        $this->assertFalse($machine->canTransition('REJECTED', 'CLOSED'));
    }
}
