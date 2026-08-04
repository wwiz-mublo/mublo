<?php
declare(strict_types=1);
namespace Mublo\Core\Extension;

/**
 * NestedPlugin
 *
 * 패키지 종속 플러그인(패키지 내부 플러그인)의 식별 규약.
 *
 * 독립 플러그인은 `plugins/{Name}` 에 살지만, 특정 패키지 없이는 의미가
 * 없는 플러그인(예: 게시판 신고)은 그 패키지 안에 산다:
 *
 *   이름(활성 목록·manifest 키):  "{Package}/{Name}"      예: Board/BoardReport
 *   표준 디렉토리(PluginHostTrait): packages/{Package}/Plugins/{Name}/
 *   표준 네임스페이스:  Mublo\Packages\{Package}\Plugins\{Name}\
 *   에셋 URL:      /serve/plugin/{Package}.{Name}/...     (경로 세그먼트라 '.' 사용)
 *
 * **발견의 주체는 패키지다.** 코어는 패키지 디렉토리를 스스로 읽지 않고,
 * 패키지 Provider 가 PluginHostInterface 를 구현한 경우에만 그 응답
 * (discoverPlugins)을 신뢰한다 — 어디에 어떤 플러그인이 있는지는 패키지가
 * 답하고, 코어는 활성화 상태 관리·로딩 실행·보안 게이트만 담당한다.
 *
 * 종속 규칙:
 * - 부모 패키지가 비활성이면 로드에서 자동 제외된다.
 * - 활성화 검증에는 requires["package:{Package}"] 가 스캔 시 자동 주입된다.
 * - 패키지가 PluginHostInterface 를 구현하지 않으면 플러그인은 존재하지 않는
 *   것으로 취급된다 — 수용 여부는 패키지 개발자의 구현이 결정한다.
 */
final class NestedPlugin
{
    /** 표준 규약(PluginHostTrait)에서 플러그인들이 사는 서브디렉토리 */
    public const SUBDIR = 'Plugins';

    /** @var array<string, ?PluginHostInterface> 요청 스코프 호스트 캐시 */
    private static array $hosts = [];

    /** @var array<string, array<string, array{dir: string, providerClass: string}>> 발견 결과 캐시 */
    private static array $discovered = [];

    /** 이름이 패키지 종속 플러그인("{Package}/{Name}")인가 */
    public static function isNested(string $name): bool
    {
        return str_contains($name, '/');
    }

    /**
     * "{Package}/{Name}" → [package, plugin]
     *
     * @return array{0: string, 1: string}|null 형식이 아니면 null
     */
    public static function parts(string $name): ?array
    {
        $seg = explode('/', $name);
        if (count($seg) !== 2 || $seg[0] === '' || $seg[1] === '') {
            return null;
        }
        return [$seg[0], $seg[1]];
    }

    /** 부모 패키지명. 중첩이 아니면 null */
    public static function parentPackage(string $name): ?string
    {
        $parts = self::parts($name);
        return $parts === null ? null : $parts[0];
    }

    /** 플러그인 자체 이름 (라우트 접두사·메뉴 등에 쓰는 짧은 이름) */
    public static function basename(string $name): string
    {
        $parts = self::parts($name);
        return $parts === null ? $name : $parts[1];
    }

    /**
     * 확장 이름(디렉토리명)이 Provider 네임스페이스 세그먼트로 쓸 수 있는가.
     *
     * 로딩은 이름으로 Mublo\Plugin\{Name}\{Name}Provider (패키지는
     * Mublo\Packages\{Name}\...) 를 조립하므로, PHP 식별자 규칙을 어기는
     * 이름은 어디서도 로드될 수 없다. 스캐너(ExtensionService)와
     * 설치기(ExtensionInstaller)가 이 규칙 하나를 공유한다 — 두 곳의
     * "유효한 확장 이름" 판정이 갈라지면 설치는 되는데 로드가 조용히
     * 깨지는(또는 그 반대) 상태가 생긴다.
     */
    public static function isValidName(string $name): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $name) === 1;
    }

    /**
     * 패키지의 플러그인 호스트 Provider
     *
     * 패키지 Provider 가 PluginHostInterface 를 구현했을 때만 인스턴스를
     * 반환한다. 이 인스턴스는 발견 질의 전용이다 — register()/boot() 는
     * 호출되지 않으므로 discoverPlugins() 는 컨테이너에 의존하면 안 된다.
     */
    public static function hostProvider(string $package): ?PluginHostInterface
    {
        if (array_key_exists($package, self::$hosts)) {
            return self::$hosts[$package];
        }

        $class = "Mublo\\Packages\\{$package}\\{$package}Provider";

        if (!class_exists($class) || !is_subclass_of($class, PluginHostInterface::class)) {
            return self::$hosts[$package] = null;
        }

        try {
            return self::$hosts[$package] = new $class();
        } catch (\Throwable $e) {
            error_log("[NestedPlugin] 호스트 Provider 생성 실패 ({$package}): " . $e->getMessage());
            return self::$hosts[$package] = null;
        }
    }

    /**
     * 패키지에게 종속 플러그인 목록을 묻는다
     *
     * @return array<string, array{dir: string, providerClass: string}> 자체 이름 => 정보
     */
    public static function discover(string $package): array
    {
        if (isset(self::$discovered[$package])) {
            return self::$discovered[$package];
        }

        $host = self::hostProvider($package);
        if ($host === null) {
            return self::$discovered[$package] = [];
        }

        try {
            $plugins = $host->discoverPlugins();
        } catch (\Throwable $e) {
            error_log("[NestedPlugin] discoverPlugins 실패 ({$package}): " . $e->getMessage());
            $plugins = [];
        }

        // 형식 검증 — 깨진 응답 하나가 확장 화면 전체를 죽이면 안 된다
        $valid = [];
        foreach ($plugins as $pluginName => $info) {
            if (is_string($pluginName) && $pluginName !== ''
                && is_array($info) && !empty($info['dir']) && !empty($info['providerClass'])) {
                $valid[$pluginName] = ['dir' => (string) $info['dir'], 'providerClass' => (string) $info['providerClass']];
            }
        }

        return self::$discovered[$package] = $valid;
    }

    /** "{Package}/{Name}" 의 발견 정보. 패키지가 답하지 않으면 null */
    private static function info(string $name): ?array
    {
        $parts = self::parts($name);
        if ($parts === null) {
            return null;
        }
        return self::discover($parts[0])[$parts[1]] ?? null;
    }

    /** 플러그인 디렉토리 절대 경로 — 패키지의 발견 응답 기준. 없으면 null */
    public static function dir(string $name): ?string
    {
        return self::info($name)['dir'] ?? null;
    }

    /** Provider FQCN — 패키지의 발견 응답 기준. 없으면 null */
    public static function providerClass(string $name): ?string
    {
        return self::info($name)['providerClass'] ?? null;
    }

    /** 에셋 URL 세그먼트("{Package}.{Name}") → 내부 이름. 중첩 표기가 아니면 그대로 */
    public static function fromUrlKey(string $urlKey): string
    {
        return str_replace('.', '/', $urlKey);
    }

    /** 내부 이름 → 에셋 URL 세그먼트 */
    public static function toUrlKey(string $name): string
    {
        return str_replace('/', '.', $name);
    }
}
