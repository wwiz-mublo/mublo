<?php
declare(strict_types=1);

namespace Mublo\Core\Env;

/**
 * Env - 환경 변수 로더
 *
 * .env 파일을 파싱하여 $_ENV와 putenv()에 등록합니다.
 * .env 파일 파싱에 필요한 최소 기능만 제공하는 경량 로더입니다.
 *
 * 사용법:
 *   Env::load(__DIR__ . '/.env');
 *   $value = Env::get('DB_HOST', 'localhost');
 *   // 또는 헬퍼 함수
 *   $value = env('DB_HOST', 'localhost');
 */
class Env
{
    /**
     * 로드된 환경 변수 캐시
     */
    private static array $cache = [];

    /**
     * 로드 완료 여부
     */
    private static bool $loaded = false;

    /**
     * .env 파일 로드
     *
     * @param string $path .env 파일 경로
     * @param bool $overwrite 기존 환경 변수 덮어쓰기 여부
     * @return bool 로드 성공 여부
     */
    public static function load(string $path, bool $overwrite = false): bool
    {
        if (!file_exists($path)) {
            return false;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return false;
        }

        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);

            // 빈 줄이나 주석은 스킵
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // KEY=VALUE 형식 파싱
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = self::parseLine($line);

            if ($key === null) {
                continue;
            }

            // 기존 환경 변수가 있고 덮어쓰기가 false면 스킵
            if (!$overwrite && getenv($key) !== false) {
                continue;
            }

            self::set($key, $value);
        }

        self::$loaded = true;
        return true;
    }

    /**
     * 환경 변수 라인 파싱
     *
     * @return array{0: ?string, 1: ?string} [key, value]
     */
    private static function parseLine(string $line): array
    {
        // 첫 번째 = 기준으로 분리
        $pos = strpos($line, '=');
        if ($pos === false) {
            return [null, null];
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        // 키 유효성 검사 (알파벳, 숫자, 언더스코어만)
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/i', $key)) {
            return [null, null];
        }

        // 값에서 따옴표 제거
        $value = self::parseValue($value);

        return [$key, $value];
    }

    /**
     * 값 파싱 (따옴표 처리, 인라인 주석 제거)
     */
    private static function parseValue(string $value): string
    {
        // 큰따옴표로 감싸진 경우
        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return substr($value, 1, -1);
        }

        // 작은따옴표로 감싸진 경우
        if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }

        // 따옴표가 없는 경우 인라인 주석 제거
        if (str_contains($value, '#')) {
            // 따옴표 안의 #은 유지
            $value = preg_replace('/\s+#.*$/', '', $value);
        }

        return trim($value);
    }

    /**
     * 환경 변수 설정
     */
    public static function set(string $key, string $value): void
    {
        self::$cache[$key] = $value;
        $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    /**
     * 환경 변수 조회
     *
     * @param string $key 키
     * @param mixed $default 기본값
     * @return mixed 값 (boolean, null 변환 포함)
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        // 캐시 확인
        if (isset(self::$cache[$key])) {
            return self::convertValue(self::$cache[$key]);
        }

        // getenv 확인
        $value = getenv($key);
        if ($value !== false) {
            self::$cache[$key] = $value;
            return self::convertValue($value);
        }

        // $_ENV 확인
        if (isset($_ENV[$key])) {
            self::$cache[$key] = $_ENV[$key];
            return self::convertValue($_ENV[$key]);
        }

        return $default;
    }

    /**
     * 문자열 값을 적절한 타입으로 변환
     */
    private static function convertValue(string $value): mixed
    {
        $lower = strtolower($value);

        return match ($lower) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }

    /**
     * 환경 변수 존재 여부 확인
     */
    public static function has(string $key): bool
    {
        return isset(self::$cache[$key])
            || getenv($key) !== false
            || isset($_ENV[$key]);
    }

    /**
     * 필수 환경 변수 확인
     *
     * @param array $keys 필수 키 목록
     * @throws \RuntimeException 누락된 키가 있으면 예외
     */
    public static function required(array $keys): void
    {
        $missing = [];

        foreach ($keys as $key) {
            if (!self::has($key)) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                'Missing required environment variables: ' . implode(', ', $missing)
            );
        }
    }

    /**
     * 로드 여부 확인
     */
    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    /**
     * 캐시 초기화 (테스트용)
     */
    public static function clear(): void
    {
        self::$cache = [];
        self::$loaded = false;
    }

    /**
     * 모든 로드된 환경 변수 반환
     */
    public static function all(): array
    {
        return self::$cache;
    }
}
