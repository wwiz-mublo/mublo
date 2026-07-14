<?php

namespace Tests\Unit\Core\Registry;

use PHPUnit\Framework\TestCase;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Core\Registry\RegistryNotFoundException;
use Mublo\Core\Registry\DuplicateRegistryException;
use Mublo\Contract\Notification\NotificationGatewayInterface;

class ContractRegistryTest extends TestCase
{
    private ContractRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new ContractRegistry();
    }

    // ─────────────────────────────────────────
    // 1:1 바인딩 테스트
    // ─────────────────────────────────────────

    public function testBindAndResolve(): void
    {
        $impl = new TestGreeter();
        $this->registry->bind(GreeterInterface::class, $impl);

        $resolved = $this->registry->resolve(GreeterInterface::class);
        $this->assertSame($impl, $resolved);
    }

    public function testBindWithClosure(): void
    {
        $this->registry->bind(GreeterInterface::class, fn() => new TestGreeter());

        $resolved = $this->registry->resolve(GreeterInterface::class);
        $this->assertInstanceOf(TestGreeter::class, $resolved);
        $this->assertInstanceOf(GreeterInterface::class, $resolved);
    }

    public function testBindClosureCachesInstance(): void
    {
        $callCount = 0;
        $this->registry->bind(GreeterInterface::class, function () use (&$callCount) {
            $callCount++;
            return new TestGreeter();
        });

        $first = $this->registry->resolve(GreeterInterface::class);
        $second = $this->registry->resolve(GreeterInterface::class);

        $this->assertSame($first, $second);
        $this->assertEquals(1, $callCount);
    }

    public function testBindRejectsInvalidImplementation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->bind(GreeterInterface::class, new \stdClass());
    }

    public function testBindClosureRejectsInvalidReturnType(): void
    {
        $this->registry->bind(GreeterInterface::class, fn() => new \stdClass());

        $this->expectException(\InvalidArgumentException::class);
        $this->registry->resolve(GreeterInterface::class);
    }

    public function testDuplicateBindThrows(): void
    {
        $this->registry->bind(GreeterInterface::class, new TestGreeter());

        $this->expectException(DuplicateRegistryException::class);
        $this->registry->bind(GreeterInterface::class, new TestGreeter());
    }

    public function testResolveUnboundThrows(): void
    {
        $this->expectException(RegistryNotFoundException::class);
        $this->registry->resolve(GreeterInterface::class);
    }

    public function testHas(): void
    {
        $this->assertFalse($this->registry->has(GreeterInterface::class));

        $this->registry->bind(GreeterInterface::class, new TestGreeter());

        $this->assertTrue($this->registry->has(GreeterInterface::class));
    }

    // ─────────────────────────────────────────
    // 1:N 등록 테스트
    // ─────────────────────────────────────────

    public function testRegisterAndGet(): void
    {
        $alpha = new AlphaDriver();
        $this->registry->register(DriverInterface::class, 'alpha', $alpha);

        $resolved = $this->registry->get(DriverInterface::class, 'alpha');
        $this->assertSame($alpha, $resolved);
    }

    public function testRegisterWithClosure(): void
    {
        $this->registry->register(
            DriverInterface::class,
            'alpha',
            fn() => new AlphaDriver()
        );

        $resolved = $this->registry->get(DriverInterface::class, 'alpha');
        $this->assertInstanceOf(AlphaDriver::class, $resolved);
    }

    public function testRegisterClosureCachesInstance(): void
    {
        $callCount = 0;
        $this->registry->register(
            DriverInterface::class,
            'alpha',
            function () use (&$callCount) {
                $callCount++;
                return new AlphaDriver();
            }
        );

        $first = $this->registry->get(DriverInterface::class, 'alpha');
        $second = $this->registry->get(DriverInterface::class, 'alpha');

        $this->assertSame($first, $second);
        $this->assertEquals(1, $callCount);
    }

    public function testRegisterMultipleKeys(): void
    {
        $this->registry->register(DriverInterface::class, 'alpha', new AlphaDriver());
        $this->registry->register(DriverInterface::class, 'beta', new BetaDriver());

        $this->assertInstanceOf(AlphaDriver::class, $this->registry->get(DriverInterface::class, 'alpha'));
        $this->assertInstanceOf(BetaDriver::class, $this->registry->get(DriverInterface::class, 'beta'));
    }

    public function testRegisterRejectsInvalidImplementation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registry->register(DriverInterface::class, 'bad', new \stdClass());
    }

    public function testRegisterClosureRejectsInvalidReturnType(): void
    {
        $this->registry->register(DriverInterface::class, 'bad', fn() => new \stdClass());

        $this->expectException(\InvalidArgumentException::class);
        $this->registry->get(DriverInterface::class, 'bad');
    }

    public function testDuplicateKeyThrows(): void
    {
        $this->registry->register(DriverInterface::class, 'alpha', new AlphaDriver());

        $this->expectException(DuplicateRegistryException::class);
        $this->registry->register(DriverInterface::class, 'alpha', new AlphaDriver());
    }

    public function testGetUnregisteredThrows(): void
    {
        $this->expectException(RegistryNotFoundException::class);
        $this->registry->get(DriverInterface::class, 'nonexistent');
    }

    public function testKeys(): void
    {
        $this->assertEquals([], $this->registry->keys(DriverInterface::class));

        $this->registry->register(DriverInterface::class, 'alpha', new AlphaDriver());
        $this->registry->register(DriverInterface::class, 'beta', new BetaDriver());

        $keys = $this->registry->keys(DriverInterface::class);
        $this->assertEquals(['alpha', 'beta'], $keys);
    }

    public function testHasKey(): void
    {
        $this->assertFalse($this->registry->hasKey(DriverInterface::class, 'alpha'));

        $this->registry->register(DriverInterface::class, 'alpha', new AlphaDriver());

        $this->assertTrue($this->registry->hasKey(DriverInterface::class, 'alpha'));
        $this->assertFalse($this->registry->hasKey(DriverInterface::class, 'beta'));
    }

    public function testAll(): void
    {
        $this->assertEquals([], $this->registry->all(DriverInterface::class));

        $alpha = new AlphaDriver();
        $this->registry->register(DriverInterface::class, 'alpha', $alpha);

        $all = $this->registry->all(DriverInterface::class);
        $this->assertCount(1, $all);
        $this->assertSame($alpha, $all['alpha']);
    }

    public function testAllDoesNotResolveClosure(): void
    {
        $callCount = 0;
        $this->registry->register(
            DriverInterface::class,
            'lazy',
            function () use (&$callCount) {
                $callCount++;
                return new AlphaDriver();
            }
        );

        $all = $this->registry->all(DriverInterface::class);
        $this->assertCount(1, $all);
        $this->assertEquals(0, $callCount);
    }

    // ─────────────────────────────────────────
    // 메타데이터 테스트
    // ─────────────────────────────────────────

    public function testRegisterWithMeta(): void
    {
        $meta = ['label' => 'Alpha', 'icon' => 'bi-star'];
        $this->registry->register(
            DriverInterface::class,
            'alpha',
            fn() => new AlphaDriver(),
            $meta
        );

        $this->assertEquals($meta, $this->registry->getMeta(DriverInterface::class, 'alpha'));
    }

    public function testGetMetaReturnsEmptyForUnregistered(): void
    {
        $this->assertEquals([], $this->registry->getMeta(DriverInterface::class, 'nonexistent'));
    }

    public function testGetMetaReturnsEmptyWhenNoMetaProvided(): void
    {
        $this->registry->register(DriverInterface::class, 'alpha', new AlphaDriver());

        $this->assertEquals([], $this->registry->getMeta(DriverInterface::class, 'alpha'));
    }

    public function testAllMeta(): void
    {
        $this->assertEquals([], $this->registry->allMeta(DriverInterface::class));

        $this->registry->register(
            DriverInterface::class,
            'alpha',
            fn() => new AlphaDriver(),
            ['label' => 'Alpha']
        );
        $this->registry->register(
            DriverInterface::class,
            'beta',
            fn() => new BetaDriver(),
            ['label' => 'Beta']
        );

        $allMeta = $this->registry->allMeta(DriverInterface::class);
        $this->assertCount(2, $allMeta);
        $this->assertEquals('Alpha', $allMeta['alpha']['label']);
        $this->assertEquals('Beta', $allMeta['beta']['label']);
    }

    public function testAllMetaDoesNotResolveClosure(): void
    {
        $callCount = 0;
        $this->registry->register(
            DriverInterface::class,
            'lazy',
            function () use (&$callCount) {
                $callCount++;
                return new AlphaDriver();
            },
            ['label' => 'Lazy']
        );

        $this->registry->allMeta(DriverInterface::class);
        $this->assertEquals(0, $callCount);
    }

    public function testRegisterNotificationGatewayWarnsWhenRequiredMetaMissing(): void
    {
        $warning = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$warning): bool {
            if ($errno === E_USER_WARNING) {
                $warning = $errstr;
                return true;
            }
            return false;
        });

        try {
            $this->registry->register(
                NotificationGatewayInterface::class,
                'broken_notification',
                new NotificationGatewayStub(),
                ['label' => 'Broken Notification']
            );
        } finally {
            restore_error_handler();
        }

        $this->assertNotNull($warning);
        $this->assertStringContainsString('missing', $warning);
        $this->assertStringContainsString('channels', $warning);
    }

    public function testRegisterNotificationGatewayWithoutMetaIssuesDoesNotWarn(): void
    {
        $warning = null;
        set_error_handler(static function (int $errno, string $errstr) use (&$warning): bool {
            if ($errno === E_USER_WARNING) {
                $warning = $errstr;
                return true;
            }
            return false;
        });

        try {
            $this->registry->register(
                NotificationGatewayInterface::class,
                'ok_notification',
                new NotificationGatewayStub(),
                [
                    'label' => 'Notification',
                    'channels' => ['sms', 'email'],
                ]
            );
        } finally {
            restore_error_handler();
        }

        $this->assertNull($warning);
    }

    // ─────────────────────────────────────────
    // 1:1과 1:N 독립성 테스트
    // ─────────────────────────────────────────

    public function testBindAndRegisterAreIndependent(): void
    {
        // 같은 계약에 bind와 register 모두 사용 가능
        $this->registry->bind(DriverInterface::class, new AlphaDriver());
        $this->registry->register(DriverInterface::class, 'beta', new BetaDriver());

        $bound = $this->registry->resolve(DriverInterface::class);
        $registered = $this->registry->get(DriverInterface::class, 'beta');

        $this->assertInstanceOf(AlphaDriver::class, $bound);
        $this->assertInstanceOf(BetaDriver::class, $registered);
    }
}

// ─────────────────────────────────────────
// 테스트용 인터페이스 및 구현체
// ─────────────────────────────────────────

interface GreeterInterface
{
    public function greet(): string;
}

class TestGreeter implements GreeterInterface
{
    public function greet(): string
    {
        return 'hello';
    }
}

interface DriverInterface
{
    public function getKey(): string;
}

class AlphaDriver implements DriverInterface
{
    public function getKey(): string
    {
        return 'alpha';
    }
}

class BetaDriver implements DriverInterface
{
    public function getKey(): string
    {
        return 'beta';
    }
}

class NotificationGatewayStub implements NotificationGatewayInterface
{
    public function send(
        string $channel,
        string $templateCode,
        string $recipient,
        array $fieldValues
    ): \Mublo\Contract\Notification\NotificationSendResult {
        return new \Mublo\Contract\Notification\NotificationSendResult(true, 'ok');
    }

    public function getSupportedChannels(): array
    {
        return ['sms' => 'SMS'];
    }

    public function getChannelTree(int $domainId): array
    {
        return [];
    }
}
