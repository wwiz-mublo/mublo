<?php

namespace Tests\Unit\Infrastructure\Redis;

use Mublo\Infrastructure\Redis\RedisManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class RedisManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        $reflection = new ReflectionClass(RedisManager::class);
        $reflection->getProperty('instance')->setValue(null, null);
        $reflection->getProperty('connectionFailed')->setValue(null, false);
        $reflection->getProperty('config')->setValue(null, []);
    }

    public function testDefaultConnectTimeoutFailsFast(): void
    {
        $reflection = new ReflectionClass(RedisManager::class);
        $config = $reflection->getMethod('getDefaultConfig')->invoke(null);

        self::assertSame(0.2, $config['timeout']);
    }

    public function testKnownConnectionFailureDoesNotRetryInSameRequest(): void
    {
        $reflection = new ReflectionClass(RedisManager::class);
        $reflection->getProperty('connectionFailed')->setValue(null, true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Redis connection already failed');

        RedisManager::getInstance();
    }
}
