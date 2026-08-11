<?php
require_once __DIR__ . '/ExtensionApiPath.php';
require_once __DIR__ . '/ExtensionApiBaseline.php';
require_once __DIR__ . '/ExtensionApiSurface.php';

use Mublo\Tools\ExtensionApiBaseline;
use Mublo\Tools\ExtensionApiPath;
use Mublo\Tools\ExtensionApiSurface;

/**
 * 확장 API 위반 검출 스크립트
 *
 * plugins/ 와 packages/ 가 import 하는 코어 심볼이 안정 API 표면 안에 있는지 검사합니다.
 *
 * 사용법:
 *   php tools/check-extension-api.php            # 검사
 *   php tools/check-extension-api.php --baseline # 현재 위반을 베이스라인으로 다시 기록
 *
 * 왜 필요한가
 *   docs/compatibility-policy.md 는 안정 API 목록을 선언하지만, 지금까지 그것을 지키는지
 *   확인하는 장치가 없었다. 그 결과 확장이 Repository·Service 같은 "내부 API"에 직접
 *   의존하게 됐고, 코어를 리팩터링할 때마다 확장이 깨질 수 있게 됐다.
 *
 * 현재 정책
 *   번들 확장의 운영 코드 baseline은 0건이다. 새 비안정 의존을 즉시 실패시키며, 과거 래칫
 *   형식이 다시 생기더라도 소유권·중복·누락·고아·빈 파일 무결성 오류를 함께 검사한다.
 */

$basePath = dirname(__DIR__);
$baselineDirectory = __DIR__ . '/extension-api-baseline';
$legacyBaselineFile = __DIR__ . '/extension-api-baseline.json';
$rewriteBaseline = in_array('--baseline', $argv, true);

/**
 * 확장이 의존해도 되는 코어 심볼 목록은 ExtensionApiSurface 가 소유한다.
 *
 * 이 검사(소비자 쪽 import)와 check-event-payload.php(생산자 쪽 반환 타입)가 같은 표면을 봐야
 * 한쪽이 허용한 타입을 다른 쪽이 금지하는 모순이 생기지 않는다.
 */

$scanDirs = [$basePath . '/plugins', $basePath . '/packages'];

// ---------------------------------------------------------------------------

/** @return array<int, array{symbol: string, file: string, line: int}> */
function collectImports(array $scanDirs, string $basePath): array
{
    $imports = [];

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
            $classification = ExtensionApiPath::classify($relative);
            if ($classification === null) {
                continue;
            }

            // 확장이 소유한 테스트는 검사 대상이 아니다. 테스트는 배포되는
            // 런타임 코드가 아니고, 픽스처를 만들기 위해 코어 Entity 를
            // 직접 쓰는 것이 정상이다. Package 중첩 Plugin도 같은 분류 결과를 쓴다.
            if ($classification['test']) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            foreach (preg_split('/\R/', $source) ?: [] as $index => $line) {
                if (preg_match('/^use\s+(Mublo\\\\[A-Za-z0-9_\\\\]+)/', trim($line), $m) !== 1) {
                    continue;
                }

                $symbol = $m[1];

                // 독립 Plugin끼리의 참조는 requires가 담당한다.
                if (str_starts_with($symbol, 'Mublo\Plugin\\')) {
                    continue;
                }

                if (str_starts_with($symbol, 'Mublo\Packages\\')) {
                    // 일반 Package 내부 참조는 검사하지 않는다. 다만 Package 종속
                    // Plugin은 부모의 공개 Contract/API/Event 경계만 사용해야 한다.
                    if (preg_match('#^packages/([^/]+)/Plugins/([^/]+)/#', $relative, $parts) !== 1) {
                        continue;
                    }

                    $ownNamespace = "Mublo\\Packages\\{$parts[1]}\\Plugins\\{$parts[2]}\\";
                    if (str_starts_with($symbol, $ownNamespace)) {
                        continue;
                    }
                }

                $imports[] = ['symbol' => $symbol, 'file' => $relative, 'line' => $index + 1];
            }

            // import 문을 쓰지 않고 완전한 클래스명으로 내부 API를 우회하는 경우도
            // 동일하게 검사한다. token_get_all을 사용해 문자열·주석의 예시는 제외한다.
            $tokens = token_get_all($source);
            foreach ($tokens as $token) {
                if (!is_array($token) || $token[0] !== T_NAME_FULLY_QUALIFIED) {
                    continue;
                }

                $symbol = ltrim($token[1], '\\');
                if (!str_starts_with($symbol, 'Mublo\\')) {
                    continue;
                }

                if (str_starts_with($symbol, 'Mublo\Plugin\\')) {
                    continue;
                }

                if (str_starts_with($symbol, 'Mublo\Packages\\')) {
                    if (preg_match('#^packages/([^/]+)/Plugins/([^/]+)/#', $relative, $parts) !== 1) {
                        continue;
                    }

                    $ownNamespace = "Mublo\\Packages\\{$parts[1]}\\Plugins\\{$parts[2]}\\";
                    if (str_starts_with($symbol, $ownNamespace)) {
                        continue;
                    }
                }

                $imports[] = ['symbol' => $symbol, 'file' => $relative, 'line' => $token[2]];
            }

            // MemberIdentity::getUserId()는 하위 호환 때문에 Contract에 남아 있지만 신규
            // 확장 코드에서는 금지한다. 토큰으로 검사해 주석·문자열 예시는 제외한다.
            foreach ($tokens as $index => $token) {
                if (!is_array($token) || $token[0] !== T_OBJECT_OPERATOR) {
                    continue;
                }
                $next = $tokens[$index + 1] ?? null;
                if (is_array($next) && $next[0] === T_STRING && $next[1] === 'getUserId') {
                    $imports[] = [
                        'symbol' => 'deprecated:MemberIdentity::getUserId',
                        'file' => $relative,
                        'line' => $next[2],
                    ];
                }
            }
        }
    }

    return $imports;
}

$imports = collectImports($scanDirs, $basePath);

$violations = [];
foreach ($imports as $import) {
    $stable = $import['symbol'] !== 'deprecated:MemberIdentity::getUserId'
        && ExtensionApiSurface::isStable($import['symbol']);

    // Package 종속 Plugin이 부모 Package에서 사용할 수 있는 공개 표면.
    if (!$stable
        && preg_match('#^packages/([^/]+)/Plugins/[^/]+/#', $import['file'], $parts) === 1) {
        $parentPrefix = "Mublo\\Packages\\{$parts[1]}\\";
        $stable = str_starts_with($import['symbol'], $parentPrefix . 'Contract\\Extension\\')
            || str_starts_with($import['symbol'], $parentPrefix . 'Api\\DTO\\')
            || str_starts_with($import['symbol'], $parentPrefix . 'Event\\');
    }

    if (!$stable) {
        $violations[] = $import;
    }
}

// occurrence는 파일 + 심볼 조합이다. 라인 이동은 새 위반으로 보지 않는다.
$currentOccurrences = [];
foreach ($violations as $violation) {
    $key = ExtensionApiBaseline::key($violation);
    $currentOccurrences[$key] ??= $violation;
}
ksort($currentOccurrences);

// --baseline: 현재 상태를 기록하고 종료
if ($rewriteBaseline) {
    $written = ExtensionApiBaseline::rewrite($baselineDirectory, array_values($currentOccurrences));
    echo "베이스라인을 기록했습니다: {$written['occurrences']}개 occurrence, {$written['files']}개 확장" . PHP_EOL;
    exit(0);
}

$baselineResult = ExtensionApiBaseline::read($baselineDirectory, $basePath);
$baseline = $baselineResult['occurrences'];
$baselineErrors = $baselineResult['errors'];
if (is_file($legacyBaselineFile)) {
    $baselineErrors[] = '단일 legacy baseline 파일이 남아 있습니다: tools/extension-api-baseline.json';
}

$newViolations = array_values(array_diff_key($currentOccurrences, $baseline));
$resolved = array_values(array_diff_key($baseline, $currentOccurrences));

echo '=== Extension API Check ===' . PHP_EOL;
echo 'Scanned: ' . count($imports) . ' extension dependency reference(s)' . PHP_EOL;
echo 'Baseline: ' . count($baseline) . " occurrence(s) in {$baselineResult['files']} extension file(s)" . PHP_EOL;
echo PHP_EOL;

if ($resolved !== []) {
    echo '✗ 해소되어 baseline에서 제거해야 할 occurrence ' . count($resolved) . '건:' . PHP_EOL;
    foreach ($resolved as $item) {
        echo "    {$item['file']} -> {$item['symbol']}" . PHP_EOL;
    }
    echo PHP_EOL;
}

if ($baselineErrors !== []) {
    echo '✗ baseline 구조 오류 ' . count($baselineErrors) . '건:' . PHP_EOL;
    foreach ($baselineErrors as $error) {
        echo "    {$error}" . PHP_EOL;
    }
    echo PHP_EOL;
}

if ($newViolations === [] && $resolved === [] && $baselineErrors === []) {
    echo '✓ No new extension API violations!' . PHP_EOL;
    exit(0);
}

if ($newViolations !== []) {
    echo '✗ 안정 API 밖의 새 의존성 ' . count($newViolations) . '건:' . PHP_EOL . PHP_EOL;

    $grouped = [];
    foreach ($newViolations as $v) {
        $grouped[$v['file']][] = $v;
    }

    foreach ($grouped as $file => $items) {
        echo "  {$file}:" . PHP_EOL;
        foreach ($items as $item) {
            echo "    L{$item['line']}: {$item['symbol']}" . PHP_EOL;
        }
        echo PHP_EOL;
    }
}

echo '확장은 docs/compatibility-policy.md 의 안정 API 만 사용해야 합니다.' . PHP_EOL;
echo '필요한 기능이 안정 API 에 없다면 Contract 를 추가하거나 이슈로 논의해 주세요.' . PHP_EOL;

exit(1);
