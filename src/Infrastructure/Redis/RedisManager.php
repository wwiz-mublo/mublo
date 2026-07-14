<?php

namespace Mublo\Infrastructure\Redis;

use Redis;
use RedisException;

/**
 * RedisManager
 *
 * Redis 연결 관리 클래스
 * - 싱글톤 연결 유지
 * - 설정 기반 연결
 * - 연결 상태 확인
 */
class RedisManager
{
    private static ?Redis $instance = null;
    private static array $config = [];

    /**
     * Redis 인스턴스 반환 (싱글톤)
     */
    public static function getInstance(): Redis
    {
        if (self::$instance === null) {
            self::$instance = self::createConnection();
        }

        return self::$instance;
    }

    /**
     * Redis 연결 생성
     */
    private static function createConnection(): Redis
    {
        $config = self::getConfig();

        $redis = new Redis();

        try {
            $redis->connect(
                $config['host'],
                $config['port'],
                2.0  // timeout 2초
            );

            // 비밀번호가 있으면 인증
            if (!empty($config['password'])) {
                $redis->auth($config['password']);
            }

            // 기본 옵션 설정
            // SERIALIZER_PHP(네이티브 serialize/unserialize)는 Redis에 저장된 값을 읽을 때마다
            // PHP unserialize를 수행해 객체 인젝션(POP 체인) 표면이 된다. 값 인코딩은
            // 소비자(RedisCache)가 JSON으로 명시 처리하므로 자동 직렬화를 끈다.
            // 주의: 업그레이드 시 기존에 PHP-직렬화로 저장된 캐시/세션은 JSON으로 읽히지 않아
            //       (캐시=미스 재생성, 세션=1회 리셋) Redis flush를 권장.
            $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
            $redis->setOption(Redis::OPT_PREFIX, 'wz:');

        } catch (RedisException $e) {
            throw new \RuntimeException(
                'Redis 연결 실패: ' . $e->getMessage()
            );
        }

        return $redis;
    }

    /**
     * 설정 로드
     */
    private static function getConfig(): array
    {
        if (!empty(self::$config)) {
            return self::$config;
        }

        // config/security.php에서 redis 설정 로드
        $configPath = dirname(__DIR__, 3) . '/config/security.php';

        if (file_exists($configPath)) {
            $config = require $configPath;
            self::$config = $config['redis'] ?? self::getDefaultConfig();
        } else {
            self::$config = self::getDefaultConfig();
        }

        return self::$config;
    }

    /**
     * 기본 설정
     */
    private static function getDefaultConfig(): array
    {
        return [
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
        ];
    }

    /**
     * 연결 상태 확인
     */
    public static function isConnected(): bool
    {
        try {
            $redis = self::getInstance();
            return $redis->ping() === true || $redis->ping() === '+PONG';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * 연결 종료
     */
    public static function disconnect(): void
    {
        if (self::$instance !== null) {
            try {
                self::$instance->close();
            } catch (\Throwable $e) {
                // 무시
            }
            self::$instance = null;
        }
    }

    /**
     * 연결 재설정 (설정 변경 시)
     */
    public static function reconnect(array $config = []): Redis
    {
        self::disconnect();

        if (!empty($config)) {
            self::$config = $config;
        }

        return self::getInstance();
    }

    /**
     * Redis 사용 가능 여부 (확장 설치 확인)
     */
    public static function isAvailable(): bool
    {
        return extension_loaded('redis');
    }
}
