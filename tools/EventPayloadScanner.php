<?php

namespace Mublo\Tools;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 이벤트 정의에서 "확장에 노출되는 비안정 반환 타입" 을 찾아낸다.
 *
 * 소스를 정적으로 읽기만 한다 — 오토로드도 리플렉션도 쓰지 않는다. 검사기가 클래스를 적재하면
 * 부수효과를 감수해야 하고, 적재 실패가 곧 검사 누락이 된다.
 *
 * 알려진 한계: 반환 타입을 선언하지 않은 메서드는 볼 수 없다. 현재 번들 이벤트에는 없으며,
 * 새로 생기면 phpstan strict 범위가 먼저 잡는다.
 */
final class EventPayloadScanner
{
    /** 반환 타입으로 쓰였을 때 검사하지 않는 PHP 내장 타입 */
    private const BUILTIN_TYPES = [
        'array', 'bool', 'callable', 'false', 'float', 'int', 'iterable', 'mixed', 'never',
        'null', 'object', 'parent', 'self', 'static', 'string', 'true', 'void',
    ];

    /**
     * 검사 대상 디렉터리를 훑어 누출 목록을 만든다.
     *
     * @param list<string> $scanDirs
     * @return list<array{file: string, symbol: string, line: int}>
     */
    public static function scan(array $scanDirs, string $basePath): array
    {
        $leaks = [];

        foreach ($scanDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = ExtensionApiPath::relativeTo($file->getPathname(), $basePath);

                // 확장이 소유한 테스트는 배포되는 런타임 코드가 아니다.
                if (ExtensionApiPath::isTestPath($relative)) {
                    continue;
                }

                foreach (self::leaksIn($relative, (string) file_get_contents($file->getPathname())) as $leak) {
                    $leaks[] = $leak;
                }
            }
        }

        return $leaks;
    }

    /**
     * 파일 하나에서 누출을 찾는다.
     *
     * @return list<array{file: string, symbol: string, line: int}>
     */
    public static function leaksIn(string $relativePath, string $source): array
    {
        $header = self::parseFileHeader($source);
        if ($header['class'] === null) {
            return [];
        }

        $fqcn = $header['namespace'] !== ''
            ? $header['namespace'] . '\\' . $header['class']
            : $header['class'];

        // 이벤트가 아니면 확장에 안정 API 로 노출되지 않으므로 대상이 아니다.
        if (!ExtensionApiSurface::isEventSymbol($fqcn)) {
            return [];
        }

        $ownPrefix = self::ownedTypePrefix($relativePath);
        $leaks = [];

        foreach (self::publicReturnTypes($source) as $method) {
            foreach ($method['types'] as $rawType) {
                $type = self::resolveType($rawType, $header['namespace'], $header['aliases']);
                if ($type === null || !str_starts_with($type, 'Mublo\\')) {
                    continue;
                }

                if (ExtensionApiSurface::isStable($type)) {
                    continue;
                }

                if ($ownPrefix !== null && str_starts_with($type, $ownPrefix)) {
                    continue;
                }

                $leaks[] = [
                    'file' => $relativePath,
                    'symbol' => "{$header['class']}::{$method['method']}(): {$type}",
                    'line' => $method['line'],
                ];
            }
        }

        return $leaks;
    }

    /**
     * 파일에서 네임스페이스·클래스명·use 별칭을 뽑는다.
     *
     * @return array{namespace: string, class: ?string, aliases: array<string, string>}
     */
    public static function parseFileHeader(string $source): array
    {
        $namespace = '';
        $class = null;
        $aliases = [];

        if (preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+)\s*;/m', $source, $m) === 1) {
            $namespace = $m[1];
        }

        if (preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*class\s+([A-Za-z0-9_]+)/m', $source, $m) === 1) {
            $class = $m[1];
        }

        // use A\B\C; / use A\B\C as D; — 함수·상수 import 는 타입이 될 수 없으므로 제외한다.
        preg_match_all(
            '/^use\s+(?!function\s|const\s)([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $fqcn = ltrim($match[1], '\\');
            $segments = explode('\\', $fqcn);
            $alias = ($match[2] ?? '') !== '' ? $match[2] : end($segments);
            $aliases[$alias] = $fqcn;
        }

        return ['namespace' => $namespace, 'class' => $class, 'aliases' => $aliases];
    }

    /**
     * public 메서드의 선언된 반환 타입을 뽑는다.
     *
     * 유니온·교차 타입은 조각으로 나눈다 — 하나라도 불안정하면 그 조각으로 누출된다.
     *
     * @return list<array{method: string, types: list<string>, line: int}>
     */
    public static function publicReturnTypes(string $source): array
    {
        $methods = [];

        // 접근 제어자를 적지 않은 메서드는 public 이다. static 게터도 대상이다.
        $pattern = '/^[ \t]*(?:(public|protected|private)\s+)?(?:static\s+)?function\s+([A-Za-z0-9_]+)\s*\([^{;]*\)\s*:\s*([^{;\n]+)/m';
        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        foreach ($matches as $match) {
            $visibility = $match[1][0] !== '' ? $match[1][0] : 'public';
            if ($visibility !== 'public') {
                continue;
            }

            $name = $match[2][0];
            if ($name === '__construct') {
                continue;
            }

            $types = preg_split('/[|&]/', trim($match[3][0])) ?: [];
            $types = array_values(array_filter(array_map('trim', $types), static fn(string $t): bool => $t !== ''));

            $methods[] = [
                'method' => $name,
                'types' => $types,
                'line' => substr_count(substr($source, 0, (int) $match[0][1]), "\n") + 1,
            ];
        }

        return $methods;
    }

    /** 파일 안에서 쓰인 타입 이름을 완전한 클래스명으로 바꾼다. */
    public static function resolveType(string $type, string $namespace, array $aliases): ?string
    {
        $type = ltrim(trim($type), '?');
        if ($type === '' || in_array(strtolower($type), self::BUILTIN_TYPES, true)) {
            return null;
        }

        // 완전 수식 이름은 그대로 쓴다.
        if (str_starts_with($type, '\\')) {
            return ltrim($type, '\\');
        }

        // use 별칭이 있으면 그것이 이긴다. 별칭은 첫 조각에만 걸린다 (Foo\Bar 의 Foo).
        $segments = explode('\\', $type);
        $head = $segments[0];
        if (isset($aliases[$head])) {
            $segments[0] = $aliases[$head];
            return implode('\\', $segments);
        }

        return $namespace !== '' ? $namespace . '\\' . $type : $type;
    }

    /**
     * 이 이벤트를 소유한 확장 자신의 네임스페이스.
     *
     * 확장이 자기 이벤트로 자기 타입을 넘기는 것은 내부 응집이다 — 소유자가 같으므로 한쪽을
     * 리팩터링하면 다른 쪽도 같이 고친다. 이 검사가 막으려는 것은 **소유자가 다른** 타입이
     * 이벤트를 타고 나가는 경우다(코어 → 확장, 부모 Package → 종속 Plugin).
     *
     * 종속 Plugin 이 부모 Package 의 타입을 자기 이벤트로 흘리는 것은 여전히 누출이므로,
     * 중첩 경로는 부모가 아니라 자기 네임스페이스를 소유 범위로 삼는다.
     *
     * 소유한 타입을 확장 밖 소비자가 이벤트에서 꺼내 쓰는 것은 소비자 쪽 문제다 —
     * check-extension-api.php 의 게터 체이닝 검사가 담당한다.
     */
    public static function ownedTypePrefix(string $relativePath): ?string
    {
        $path = str_replace('\\', '/', $relativePath);

        if (preg_match('#^packages/([^/]+)/Plugins/([^/]+)/#', $path, $parts) === 1) {
            return "Mublo\\Packages\\{$parts[1]}\\Plugins\\{$parts[2]}\\";
        }

        if (preg_match('#^packages/([^/]+)/#', $path, $parts) === 1) {
            return "Mublo\\Packages\\{$parts[1]}\\";
        }

        if (preg_match('#^plugins/([^/]+)/#', $path, $parts) === 1) {
            return "Mublo\\Plugin\\{$parts[1]}\\";
        }

        return null;
    }
}
