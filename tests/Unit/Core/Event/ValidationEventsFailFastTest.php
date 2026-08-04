<?php

namespace Tests\Unit\Core\Event;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\Domain\DomainOwnerValidatingEvent;
use Mublo\Core\Event\Domain\DomainProvisioningEvent;
use Mublo\Core\Event\FailFastEventInterface;
use Mublo\Core\Event\Balance\BalanceAdjustingEvent;
use Mublo\Core\Event\Member\MemberRegisterValidatingEvent;
use Mublo\Core\Event\Member\MemberRegisterPreparingEvent;
use Mublo\Service\Member\Event\MemberDeletingEvent;
use Mublo\Service\Member\Event\MemberWithdrawingEvent;
use PHPUnit\Framework\TestCase;

class ValidationEventsFailFastTest extends TestCase
{
    public function testPolicyAndProvisioningEventsFailFast(): void
    {
        $context = $this->createMock(Context::class);

        $this->assertInstanceOf(
            FailFastEventInterface::class,
            new DomainOwnerValidatingEvent(5, 'owner1', 1, '1')
        );
        $this->assertInstanceOf(
            FailFastEventInterface::class,
            new MemberRegisterValidatingEvent([], $context)
        );
        $this->assertInstanceOf(
            FailFastEventInterface::class,
            new MemberRegisterPreparingEvent([], $context)
        );
        $this->assertInstanceOf(
            FailFastEventInterface::class,
            new DomainProvisioningEvent(17, '1/17', 5, 1)
        );
    }

    public function testBlockableDomainEventsFailFastWhenAListenerFails(): void
    {
        $classes = [
            BalanceAdjustingEvent::class,
            MemberWithdrawingEvent::class,
            MemberDeletingEvent::class,
        ];

        foreach ($classes as $class) {
            $this->assertContains(FailFastEventInterface::class, class_implements($class), $class);
        }
    }

    public function testMemberPreparingEventCannotElevateTenantOrAuthorizationFields(): void
    {
        $event = new MemberRegisterPreparingEvent([
            'domain_id' => 1,
            'level_value' => 1,
            'status' => 'pending',
        ], $this->createMock(Context::class));

        foreach (['domain_id', 'domain_group', 'level_value', 'status'] as $field) {
            try {
                $event->set($field, 'attacker-controlled');
                $this->fail($field . ' 변경이 거부되어야 합니다.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
