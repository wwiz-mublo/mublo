<?php
declare(strict_types=1);

namespace Tests\Unit\Contract\Fcm;

use Mublo\Contract\Auth\ApiTokenValidatorInterface;
use Mublo\Contract\Fcm\ContactSaveInterface;
use Mublo\Contract\Fcm\DeviceBindingInterface;
use Mublo\Contract\Fcm\ResolveFcmDeviceDomainEvent;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Result\Result;
use PHPUnit\Framework\TestCase;

class ResolveFcmDeviceDomainEventTest extends TestCase
{
    public function testDefaultsToRequestedDomain(): void
    {
        $event = new ResolveFcmDeviceDomainEvent(7);

        $this->assertSame(7, $event->getDomainId());
        $this->assertSame(7, $event->getDeviceDomainId());
        $this->assertFalse($event->isShared());
    }

    public function testListenerCanPointToSharedDomain(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(
            ResolveFcmDeviceDomainEvent::class,
            static fn(ResolveFcmDeviceDomainEvent $e) => $e->setDeviceDomainId(1)
        );

        $event = $dispatcher->dispatch(new ResolveFcmDeviceDomainEvent(7));

        $this->assertSame(1, $event->getDeviceDomainId());
        $this->assertTrue($event->isShared());
    }

    public function testIgnoresNonPositiveDomain(): void
    {
        $event = new ResolveFcmDeviceDomainEvent(7);
        $event->setDeviceDomainId(0);
        $event->setDeviceDomainId(-3);

        $this->assertSame(7, $event->getDeviceDomainId());
    }

    /**
     * 계약 시그니처를 고정한다 — 구현 확장이 저장소 밖에 있어 바뀌어도 이 저장소에서는 깨지지 않는다.
     */
    public function testContractsCanBeImplementedAsDocumented(): void
    {
        $validator = new class implements ApiTokenValidatorInterface {
            public function validate(string $rawToken): ?array
            {
                return $rawToken === 'ok' ? ['member_id' => 3, 'domain_id' => 2] : null;
            }
        };
        $binding = new class implements DeviceBindingInterface {
            /** @var array<string, int> */
            public array $map = [];
            public function resolveDeviceForPhone(int $domainId, string $phone): ?int
            {
                return $this->map["{$domainId}:{$phone}"] ?? null;
            }
            public function bind(int $domainId, string $phone, int $deviceId, array $meta = []): void
            {
                $this->map["{$domainId}:{$phone}"] ??= $deviceId;
            }
            public function unbind(int $domainId, string $phone): void
            {
                unset($this->map["{$domainId}:{$phone}"]);
            }
            public function rebind(int $domainId, string $phone, int $newDeviceId): void
            {
                $this->map["{$domainId}:{$phone}"] = $newDeviceId;
            }
        };
        $saver = new class implements ContactSaveInterface {
            public function saveContact(int $domainId, string $phone, string $name, array $meta = []): Result
            {
                return Result::success('', ['contact_id' => 1, 'device_id' => (int) ($meta['device_id'] ?? 0), 'status' => 'pending']);
            }
        };

        $this->assertSame(['member_id' => 3, 'domain_id' => 2], $validator->validate('ok'));
        $this->assertNull($validator->validate('bad'));

        $binding->bind(2, '01012345678', 10);
        $binding->bind(2, '01012345678', 11);
        $this->assertSame(10, $binding->resolveDeviceForPhone(2, '01012345678'));
        $binding->rebind(2, '01012345678', 11);
        $this->assertSame(11, $binding->resolveDeviceForPhone(2, '01012345678'));
        $binding->unbind(2, '01012345678');
        $this->assertNull($binding->resolveDeviceForPhone(2, '01012345678'));

        $this->assertSame('pending', $saver->saveContact(2, '01012345678', '홍길동', ['device_id' => 11])->get('status'));
    }
}
