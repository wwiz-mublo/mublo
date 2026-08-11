<?php

namespace Mublo\Tools;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * 확장이 이벤트 게터의 반환값으로 남의 타입에 닿는 것을 찾아낸다.
 *
 *     public function onArticleDeleted(ArticleDeletedEvent $event): void
 *     {
 *         $event->getArticle()->getDomainId();   // BoardArticle 에 묶인다 — use 문은 없다
 *         $article = $event->getArticle();       // 변수에 담아도 묶이는 것은 같다
 *     }
 *
 * import 검사는 소스에 적힌 이름만 보므로 이 형태를 볼 수 없다. 여기서는 이벤트 클래스를
 * 실제로 찾아가 게터의 반환 타입을 읽어 판정한다 — 추측이 아니라 상호 참조다.
 *
 * 소유자가 같은 타입(자기 패키지 이벤트에서 자기 엔티티)은 내부 응집이므로 통과시킨다.
 * 그 판단은 EventPayloadScanner 의 생산자측 규칙과 짝을 이룬다.
 */
final class EventConsumerScanner
{
    /**
     * @param list<string> $consumerDirs 확장 코드 디렉터리
     * @param list<string> $eventDirs    이벤트 정의를 찾을 디렉터리
     * @return list<array{file: string, symbol: string, line: int}>
     */
    public static function scan(array $consumerDirs, array $eventDirs, string $basePath): array
    {
        $eventGetters = self::collectEventGetters($eventDirs, $basePath);
        $violations = [];

        foreach (self::phpFiles($consumerDirs) as $path) {
            $relative = ExtensionApiPath::relativeTo($path, $basePath);
            if (ExtensionApiPath::classify($relative) === null || ExtensionApiPath::isTestPath($relative)) {
                continue;
            }

            foreach (self::violationsIn($relative, (string) file_get_contents($path), $eventGetters) as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * 이벤트 클래스별 게터 반환 타입 표를 만든다.
     *
     * @return array<string, array<string, string>> FQCN => [메서드 => 반환 타입]
     */
    public static function collectEventGetters(array $eventDirs, string $basePath): array
    {
        $map = [];

        foreach (self::phpFiles($eventDirs) as $path) {
            $source = (string) file_get_contents($path);
            $header = EventPayloadScanner::parseFileHeader($source);
            if ($header['class'] === null) {
                continue;
            }

            $fqcn = $header['namespace'] !== ''
                ? $header['namespace'] . '\\' . $header['class']
                : $header['class'];

            if (!ExtensionApiSurface::isEventSymbol($fqcn)) {
                continue;
            }

            foreach (EventPayloadScanner::publicReturnTypes($source) as $method) {
                // 유니온이면 첫 조각만으로 판정하지 않는다 — 하나라도 불안정하면 그것으로 샌다.
                foreach ($method['types'] as $rawType) {
                    $type = EventPayloadScanner::resolveType($rawType, $header['namespace'], $header['aliases']);
                    if ($type === null || !str_starts_with($type, 'Mublo\\')) {
                        continue;
                    }

                    if (!isset($map[$fqcn][$method['method']]) || !ExtensionApiSurface::isStable($type)) {
                        $map[$fqcn][$method['method']] = $type;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * 파일 하나에서 위반을 찾는다.
     *
     * @param array<string, array<string, string>> $eventGetters
     * @return list<array{file: string, symbol: string, line: int}>
     */
    public static function violationsIn(string $relativePath, string $source, array $eventGetters): array
    {
        $header = EventPayloadScanner::parseFileHeader($source);
        $handlers = self::eventParameters($source, $header['namespace'], $header['aliases']);
        if ($handlers === []) {
            return [];
        }

        $ownPrefix = EventPayloadScanner::ownedTypePrefix($relativePath);
        $violations = [];

        foreach ($handlers as $variable => $eventClass) {
            $getters = $eventGetters[$eventClass] ?? null;
            if ($getters === null) {
                continue;
            }

            // 게터를 부르는 것 자체가 결합이다. 반환값을 바로 파고들든($event->getX()->),
            // 변수에 담아 뒤에서 쓰든($x = $event->getX();) 남의 타입에 묶이는 것은 같다.
            $pattern = '/\$' . preg_quote($variable, '/') . '\s*->\s*([A-Za-z0-9_]+)\s*\(\s*\)/';
            preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($matches as $match) {
                $type = $getters[$match[1][0]] ?? null;
                if ($type === null
                    || !str_starts_with($type, 'Mublo\\')
                    || ExtensionApiSurface::isStable($type)) {
                    continue;
                }

                if ($ownPrefix !== null && str_starts_with($type, $ownPrefix)) {
                    continue;
                }

                $short = substr($eventClass, (int) strrpos($eventClass, '\\') + 1);
                $violations[] = [
                    'file' => $relativePath,
                    'symbol' => "payload:{$short}::{$match[1][0]}(): {$type}",
                    'line' => substr_count(substr($source, 0, (int) $match[0][1]), "\n") + 1,
                ];
            }
        }

        return $violations;
    }

    /**
     * 이벤트를 받는 파라미터의 변수명 → 이벤트 FQCN.
     *
     * 핸들러 이름 관례에 기대지 않는다 — 타입힌트가 이벤트인 파라미터를 모두 찾는다.
     *
     * @return array<string, string>
     */
    public static function eventParameters(string $source, string $namespace, array $aliases): array
    {
        $parameters = [];

        preg_match_all(
            '/(\\\\?[A-Za-z0-9_\\\\]+)\s+\$([A-Za-z0-9_]+)\s*(?=[,)=])/',
            $source,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $match) {
            $type = EventPayloadScanner::resolveType($match[1], $namespace, $aliases);
            if ($type === null || !ExtensionApiSurface::isEventSymbol($type)) {
                continue;
            }

            $parameters[$match[2]] = $type;
        }

        return $parameters;
    }

    /** @return list<string> */
    private static function phpFiles(array $dirs): array
    {
        $files = [];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
