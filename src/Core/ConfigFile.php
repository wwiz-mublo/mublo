<?php
declare(strict_types=1);

namespace Mublo\Core;

/**
 * config/ 디렉터리의 설정 파일을 읽는 단일 지점.
 *
 * 예전에는 security.php 하나를 열한 곳에서 읽으면서 경로를 세 가지 방법으로
 * 조립했다 — MUBLO_CONFIG_PATH, MUBLO_ROOT_PATH . '/config', dirname(__DIR__, 3).
 * 지금은 셋 다 같은 곳을 가리키지만, 설정 위치를 옮기는 순간 조용히 갈라진다.
 * 못 찾은 쪽은 예외 없이 기본값으로 넘어가므로 캐시 드라이버나 Redis 사용 여부가
 * 아무 신호 없이 바뀐다.
 *
 * 실패 계약은 한 가지로 고정한다.
 *
 *  - 파일 없음 → 빈 배열. 설치 전·테스트 환경에서 정상 동작해야 한다.
 *  - 파일이 있는데 읽을 수 없음 → require 가 그대로 터진다. 조용히 기본값으로
 *    내려앉으면 설정이 왜 안 먹는지 찾을 단서가 남지 않는다.
 *  - 배열이 아닌 값을 반환 → 예외. 손상된 설치본이며 기본값으로 가릴 일이 아니다.
 *
 * 한 요청 안에서는 파일당 한 번만 읽는다.
 */
final class ConfigFile
{
    /** @var array<string, array<string, mixed>> */
    private static array $loaded = [];

    /**
     * 설정 파일 로드.
     *
     * @param string $name 확장자 없는 파일명 (예: 'security', 'database')
     * @return array<string, mixed> 파일이 없으면 빈 배열
     */
    public static function load(string $name): array
    {
        if (array_key_exists($name, self::$loaded)) {
            return self::$loaded[$name];
        }

        $path = self::path($name);

        // is_file() 이 아니라 file_exists() 다. 경로에 무언가 있는데 읽을 수 없다면
        // (권한, 혹은 같은 이름의 디렉터리) 그건 "설정이 없음" 이 아니라 손상된
        // 설치본이다. 조용히 기본값으로 넘기지 않고 require 가 그대로 터지게 둔다.
        if ($path === null || !file_exists($path)) {
            return self::$loaded[$name] = [];
        }

        $config = require $path;

        if (!is_array($config)) {
            throw new \RuntimeException(
                sprintf('설정 파일 형식이 올바르지 않습니다: config/%s.php 는 배열을 반환해야 합니다.', $name)
            );
        }

        return self::$loaded[$name] = $config;
    }

    /**
     * 설정 파일이 존재하는가.
     *
     * 파일이 없으면 동작할 수 없는 쪽(설치 완료를 전제하는 서비스)이 직접
     * 판단할 수 있게 열어 둔다.
     */
    public static function exists(string $name): bool
    {
        $path = self::path($name);

        // load() 와 같은 판정을 써야 한다. exists() 가 거짓인데 load() 가 읽으려
        // 드는(또는 그 반대인) 어긋남이 생기면 안 된다.
        return $path !== null && file_exists($path);
    }

    /**
     * 캐시 비우기 (테스트에서 설정 파일을 바꿔 끼울 때 사용).
     */
    public static function reset(): void
    {
        self::$loaded = [];
    }

    /**
     * 설정 파일 경로. 상수가 없으면 null — 설치 전이나 일부 CLI 경로에서 발생한다.
     */
    private static function path(string $name): ?string
    {
        if (!defined('MUBLO_CONFIG_PATH')) {
            return null;
        }

        return MUBLO_CONFIG_PATH . '/' . $name . '.php';
    }
}
