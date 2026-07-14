<?php

namespace Tests\Unit\Infrastructure\Cache;

use PHPUnit\Framework\TestCase;
use Mublo\Infrastructure\Cache\FileCache;

/**
 * FileCacheTest
 *
 * FileCache 단위 테스트
 * - set / get 기본 동작
 * - TTL 만료 처리
 * - has / delete
 * - flush
 * - remember
 * - increment / decrement
 * - ttl()
 * - 도메인 ID 멀티테넌트
 */
class FileCacheTest extends TestCase
{
    private FileCache $cache;
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        // 임시 디렉토리 사용
        $this->tempDir = sys_get_temp_dir() . '/mublo_cache_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);

        $this->cache = new FileCache($this->tempDir, 3600);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    }

    // =========================================================================
    // set / get 기본 동작
    // =========================================================================

    public function testSetAndGetString(): void
    {
        $this->cache->set('greeting', 'Hello, World!');
        $this->assertEquals('Hello, World!', $this->cache->get('greeting'));
    }

    public function testSetAndGetInteger(): void
    {
        $this->cache->set('count', 42);
        $this->assertEquals(42, $this->cache->get('count'));
    }

    public function testSetAndGetArray(): void
    {
        $data = ['user' => 'testuser', 'level' => 5, 'tags' => ['a', 'b']];
        $this->cache->set('user_data', $data);

        $retrieved = $this->cache->get('user_data');
        $this->assertEquals($data, $retrieved);
    }

    public function testSetAndGetBoolean(): void
    {
        $this->cache->set('flag_true', true);
        $this->cache->set('flag_false', false);

        $this->assertTrue($this->cache->get('flag_true'));
        $this->assertFalse($this->cache->get('flag_false'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $this->assertNull($this->cache->get('nonexistent'));
        $this->assertEquals('default_value', $this->cache->get('nonexistent', 'default_value'));
    }

    public function testSetReturnsTrueOnSuccess(): void
    {
        $result = $this->cache->set('test_key', 'test_value');
        $this->assertTrue($result);
    }

    // =========================================================================
    // TTL 만료
    // =========================================================================

    public function testGetReturnsDefaultAfterTtlExpiry(): void
    {
        // Given: TTL 1초
        $this->cache->set('expiring_key', 'soon_gone', 1);

        // 만료 전 조회
        $this->assertEquals('soon_gone', $this->cache->get('expiring_key'));

        // 1초 후 만료
        sleep(2);

        // Then: 만료된 키는 default 반환
        $this->assertNull($this->cache->get('expiring_key'));
    }

    public function testSetWithZeroTtlExpiresImmediately(): void
    {
        $this->cache->set('zero_ttl', 'value', 0);
        sleep(1);

        $this->assertNull($this->cache->get('zero_ttl'));
    }

    // =========================================================================
    // has
    // =========================================================================

    public function testHasReturnsTrueForExistingKey(): void
    {
        $this->cache->set('existing', 'value');
        $this->assertTrue($this->cache->has('existing'));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        $this->assertFalse($this->cache->has('nonexistent_key_xyz'));
    }

    public function testHasReturnsFalseAfterDelete(): void
    {
        $this->cache->set('to_delete', 'value');
        $this->assertTrue($this->cache->has('to_delete'));

        $this->cache->delete('to_delete');
        $this->assertFalse($this->cache->has('to_delete'));
    }

    // =========================================================================
    // delete
    // =========================================================================

    public function testDeleteRemovesKey(): void
    {
        $this->cache->set('delete_me', 'value');
        $this->cache->delete('delete_me');

        $this->assertNull($this->cache->get('delete_me'));
    }

    public function testDeleteReturnsTrueForNonExistentKey(): void
    {
        // 존재하지 않는 키 삭제도 true 반환 (이미 없으면 성공)
        $result = $this->cache->delete('never_set_key');
        $this->assertTrue($result);
    }

    public function testDeleteReturnsTrueForExistingKey(): void
    {
        $this->cache->set('existing_key', 'value');
        $result = $this->cache->delete('existing_key');
        $this->assertTrue($result);
    }

    // =========================================================================
    // flush
    // =========================================================================

    public function testFlushRemovesAllKeys(): void
    {
        $this->cache->set('key1', 'value1');
        $this->cache->set('key2', 'value2');
        $this->cache->set('key3', 'value3');

        $count = $this->cache->flush();

        $this->assertGreaterThanOrEqual(3, $count);
        $this->assertNull($this->cache->get('key1'));
        $this->assertNull($this->cache->get('key2'));
        $this->assertNull($this->cache->get('key3'));
    }

    public function testFlushReturnsZeroForEmptyCache(): void
    {
        $count = $this->cache->flush();
        $this->assertEquals(0, $count);
    }

    // =========================================================================
    // remember
    // =========================================================================

    public function testRememberReturnsCachedValue(): void
    {
        $callCount = 0;
        $this->cache->set('remember_key', 'cached_value');

        $result = $this->cache->remember('remember_key', 60, function () use (&$callCount) {
            $callCount++;
            return 'fresh_value';
        });

        $this->assertEquals('cached_value', $result);
        $this->assertEquals(0, $callCount, 'callback은 캐시가 있으면 호출되지 않아야 합니다');
    }

    public function testRememberCallsCallbackWhenCacheMissed(): void
    {
        $callCount = 0;

        $result = $this->cache->remember('missing_key', 60, function () use (&$callCount) {
            $callCount++;
            return 'computed_value';
        });

        $this->assertEquals('computed_value', $result);
        $this->assertEquals(1, $callCount);
    }

    public function testRememberCachesCallbackResult(): void
    {
        $this->cache->remember('new_key', 3600, fn() => 'stored_value');

        // 두 번째 호출은 캐시에서
        $result = $this->cache->remember('new_key', 3600, fn() => 'should_not_be_returned');
        $this->assertEquals('stored_value', $result);
    }

    // =========================================================================
    // increment / decrement
    // =========================================================================

    public function testIncrementStartsFromZeroForNewKey(): void
    {
        $result = $this->cache->increment('counter');
        $this->assertEquals(1, $result);
    }

    public function testIncrementAddsValue(): void
    {
        $this->cache->set('counter', 10);
        $result = $this->cache->increment('counter', 5);
        $this->assertEquals(15, $result);
    }

    public function testDecrementSubtractsValue(): void
    {
        $this->cache->set('counter', 10);
        $result = $this->cache->decrement('counter', 3);
        $this->assertEquals(7, $result);
    }

    public function testIncrementReturnsFalseForNonNumericValue(): void
    {
        $this->cache->set('string_key', 'not_a_number');
        $result = $this->cache->increment('string_key');
        $this->assertFalse($result);
    }

    public function testIncrementDefaultValueIsOne(): void
    {
        $this->cache->set('counter', 5);
        $result = $this->cache->increment('counter');
        $this->assertEquals(6, $result);
    }

    // =========================================================================
    // ttl()
    // =========================================================================

    public function testTtlReturnsMinusTwoForMissingKey(): void
    {
        $ttl = $this->cache->ttl('nonexistent_key');
        $this->assertEquals(-2, $ttl);
    }

    public function testTtlReturnsPositiveForValidKey(): void
    {
        $this->cache->set('valid_key', 'value', 3600);
        $ttl = $this->cache->ttl('valid_key');

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(3600, $ttl);
    }

    public function testTtlReturnsMinusOneForExpiredKey(): void
    {
        $this->cache->set('expiring_key', 'value', 1);
        sleep(2);

        $ttl = $this->cache->ttl('expiring_key');
        $this->assertEquals(-1, $ttl);
    }

    // =========================================================================
    // 도메인 ID 멀티테넌트
    // =========================================================================

    public function testDifferentDomainIdsUseSeparateCaches(): void
    {
        // Given: 도메인 1과 도메인 2 분리
        $cache1 = new FileCache($this->tempDir . '/d1', 3600, 1);
        $cache2 = new FileCache($this->tempDir . '/d2', 3600, 2);

        $cache1->set('shared_key', 'domain1_value');
        $cache2->set('shared_key', 'domain2_value');

        // Then: 서로 격리
        $this->assertEquals('domain1_value', $cache1->get('shared_key'));
        $this->assertEquals('domain2_value', $cache2->get('shared_key'));

        // 다른 도메인의 캐시는 보이지 않음
        $cacheGlobal = new FileCache($this->tempDir . '/global', 3600);
        $this->assertNull($cacheGlobal->get('shared_key'));
    }

    public function testSetDomainId(): void
    {
        $cache = new FileCache($this->tempDir . '/multi', 3600);
        $cache->set('key', 'global_value');

        // 도메인 ID 변경
        $cache->setDomainId(5);
        $this->assertEquals(5, $cache->getDomainId());

        // 이전 global 캐시 보이지 않음
        $this->assertNull($cache->get('key'));
    }

    // =========================================================================
    // 키 덮어쓰기
    // =========================================================================

    public function testSetOverwritesExistingKey(): void
    {
        $this->cache->set('key', 'original');
        $this->cache->set('key', 'updated');

        $this->assertEquals('updated', $this->cache->get('key'));
    }

    // =========================================================================
    // 특수 키
    // =========================================================================

    public function testKeyWithSpecialCharacters(): void
    {
        $this->cache->set('key:with:colons', 'value1');
        $this->cache->set('key/with/slashes', 'value2');
        $this->cache->set('key with spaces', 'value3');

        $this->assertEquals('value1', $this->cache->get('key:with:colons'));
        $this->assertEquals('value2', $this->cache->get('key/with/slashes'));
        $this->assertEquals('value3', $this->cache->get('key with spaces'));
    }
}
