<?php

namespace Tests\Unit\Infrastructure\Cache;

use PHPUnit\Framework\TestCase;
use Mublo\Infrastructure\Cache\RedisCache;

/**
 * RedisCacheSerializationTest
 *
 * RedisCache의 값 인코딩이 JSON(비객체)임을 검증한다.
 * SERIALIZER_PHP(네이티브 unserialize)로 인한 객체 인젝션 표면 제거를 회귀 고정.
 *
 * 실제 Redis 연결 없이 private encode/decode seam만 검증한다.
 */
class RedisCacheSerializationTest extends TestCase
{
    private function encode(mixed $value): string|false
    {
        $m = new \ReflectionMethod(RedisCache::class, 'encode');
        $m->setAccessible(true);
        return $m->invoke(null, $value);
    }

    private function decode(string $data): mixed
    {
        $m = new \ReflectionMethod(RedisCache::class, 'decode');
        $m->setAccessible(true);
        return $m->invoke(null, $data);
    }

    public function testAssocArrayRoundTripsAsArray(): void
    {
        $value = ['a' => 1, 'b' => ['c' => 'x'], 'd' => [1, 2, 3]];
        $decoded = $this->decode($this->encode($value));

        $this->assertIsArray($decoded);
        $this->assertSame($value, $decoded);
    }

    public function testScalarsRoundTrip(): void
    {
        $this->assertSame(42, $this->decode($this->encode(42)));
        $this->assertSame('hello 한글', $this->decode($this->encode('hello 한글')));
        $this->assertSame(true, $this->decode($this->encode(true)));
    }

    public function testEncodedPayloadIsJsonNotPhpSerialized(): void
    {
        $encoded = $this->encode(['user' => 'kim']);
        // JSON이어야 하며 PHP 직렬화 마커(a:, O:, s:)로 시작하지 않아야 한다
        $this->assertJson($encoded);
        $this->assertStringStartsWith('{', $encoded);
    }

    public function testDecodingPhpSerializedObjectYieldsNullNotAnObject(): void
    {
        // 공격자가 Redis에 심을 수 있는 PHP 직렬화 객체 페이로드
        $phpSerializedObject = 'O:8:"stdClass":1:{s:3:"foo";s:3:"bar";}';
        $decoded = $this->decode($phpSerializedObject);

        // JSON 디코더는 이를 파싱하지 못해 null(캐시 미스) → 객체가 절대 생성되지 않음
        $this->assertNull($decoded);
    }

    public function testDecodingLegacyPhpCacheYieldsNull(): void
    {
        // 이전 SERIALIZER_PHP 시절 저장된 배열 캐시 → JSON 미스(안전한 재생성)
        $legacy = 'a:1:{s:1:"a";i:1;}';
        $this->assertNull($this->decode($legacy));
    }
}
