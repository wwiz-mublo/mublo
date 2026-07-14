<?php
require_once __DIR__ . '/ExtensionApiPath.php';

use Mublo\Tools\ExtensionApiPath;

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
 * 래칫(ratchet) 전략
 *   기존 위반을 한 번에 걷어내는 것은 큰 작업이다. 대신 현재 위반을 baseline 에 동결하고,
 *   **새로운 위반만 실패**시킨다. 표면이 더 넓어지지 않으므로 시간이 지나면 줄어들기만 한다.
 *   기존 위반을 하나 없앨 때마다 baseline 에서 지운다.
 */

$basePath = dirname(__DIR__);
$baselineFile = __DIR__ . '/extension-api-baseline.json';
$rewriteBaseline = in_array('--baseline', $argv, true);

/**
 * 확장이 의존해도 되는 코어 심볼 (네임스페이스 프리픽스)
 *
 * docs/compatibility-policy.md 의 "안정 API" 를 코드로 옮긴 것이다.
 * 두 목록이 어긋나면 문서가 아니라 이 배열이 진실이다 — CI 가 이것을 강제한다.
 */
$stablePrefixes = [
    // 확장 등록·부팅
    'Mublo\Core\Extension\\',
    'Mublo\Core\Container\\',
    'Mublo\Core\Context\\',

    // 이벤트 — 정책상 안정. 네임스페이스가 어디든 \Event\ 아래면 안정으로 본다(아래 isStable 참고).
    'Mublo\Core\Event\\',

    // Contract (확장 간 결합의 정식 통로)
    'Mublo\Contract\\',
    'Mublo\Core\Registry\\',

    // 요청/응답
    'Mublo\Core\Http\\',
    'Mublo\Core\Response\\',
    'Mublo\Core\Result\\',
    'Mublo\Core\Session\SessionInterface',
    'Mublo\Core\App\PrefixedRouteCollector',
    'Mublo\Core\Middleware\\',

    // 블록 시스템 — RendererInterface::render(BlockColumn) 가 엔티티를 시그니처로 강제한다
    'Mublo\Core\Block\\',
    'Mublo\Enum\Block\\',
    'Mublo\Entity\Block\\',

    // 확장이 상속하는 기반 클래스
    'Mublo\Entity\BaseEntity',
    'Mublo\Repository\BaseRepository',

    // 관리자·렌더 확장 지점
    'Mublo\Core\Dashboard\\',
    'Mublo\Core\Rendering\\',
    'Mublo\Core\Theme\\',
    'Mublo\Core\Report\\',
    'Mublo\Core\Crypto\\',

    // 마이페이지 확장 지점 — Package/Plugin 컨트롤러가 마이페이지 레이아웃을
    // 사용할 수 있도록 분리된 서비스 (클래스 독블록 참조)
    'Mublo\Service\Mypage\MypageMenuBuilder',

    // 인프라 — 주입해서 쓰는 서비스와 값 객체
    'Mublo\Infrastructure\Database\Database',
    'Mublo\Infrastructure\Database\DatabaseException',
    'Mublo\Infrastructure\Cache\CacheInterface',
    'Mublo\Infrastructure\Storage\\',
    'Mublo\Infrastructure\Image\ImageProcessor',
    'Mublo\Infrastructure\Mail\\',
    'Mublo\Infrastructure\Log\Logger',
    'Mublo\Infrastructure\Security\RateLimiter',
    'Mublo\Infrastructure\Code\CodeGenerator',
    'Mublo\Core\Cookie\CookieInterface',

    // 예외
    'Mublo\Exception\\',

    // 보조 유틸 (값 객체·헬퍼)
    'Mublo\Helper\\',
];

$scanDirs = [$basePath . '/plugins', $basePath . '/packages'];

// ---------------------------------------------------------------------------

function isStable(string $symbol, array $stablePrefixes): bool
{
    // 이벤트는 정책상 안정 API 다. 다만 코어가 이벤트를 Core\Event\ 밖에도 두었다
    // (예: Service\Admin\Event\AdminMenuBuildingEvent). 위치가 아니라 성격으로 판단한다.
    if (str_contains($symbol, '\Event\\') || str_ends_with($symbol, 'Event')) {
        return true;
    }

    foreach ($stablePrefixes as $prefix) {
        // 역슬래시로 끝나면 네임스페이스 전체, 아니면 정확한 클래스 하나.
        // 접두사 매칭을 느슨하게 하면 Database 가 DatabaseManager 까지 통과시킨다.
        $matched = str_ends_with($prefix, '\\')
            ? str_starts_with($symbol, $prefix)
            : $symbol === $prefix;

        if ($matched) {
            return true;
        }
    }

    return false;
}

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

            // 확장이 소유한 테스트는 검사 대상이 아니다. 테스트는 배포되는
            // 런타임 코드가 아니고, 픽스처를 만들기 위해 코어 Entity 를
            // 직접 쓰는 것이 정상이다. (phpstan excludePaths 와 같은 기준)
            if (preg_match('#^(?:packages|plugins)/[^/]+/tests/#', $relative) === 1) {
                continue;
            }

            foreach (file($file->getPathname()) as $index => $line) {
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
        }
    }

    return $imports;
}

$imports = collectImports($scanDirs, $basePath);

$violations = [];
foreach ($imports as $import) {
    $stable = isStable($import['symbol'], $stablePrefixes);

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

// 심볼 단위로 묶는다 — 같은 심볼을 여러 파일에서 쓰는 것은 하나의 부채다
$violatedSymbols = array_values(array_unique(array_column($violations, 'symbol')));
sort($violatedSymbols);

// --baseline: 현재 상태를 기록하고 종료
if ($rewriteBaseline) {
    file_put_contents(
        $baselineFile,
        json_encode([
            '_comment' => '확장이 의존하는 비안정 코어 심볼. 새 항목 추가 금지 — 하나씩 줄여 나간다.',
            '_generated_by' => 'php tools/check-extension-api.php --baseline',
            'symbols' => $violatedSymbols,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
    );

    echo '베이스라인을 기록했습니다: ' . count($violatedSymbols) . '개 심볼' . PHP_EOL;
    exit(0);
}

$baseline = [];
if (is_file($baselineFile)) {
    $decoded = json_decode((string) file_get_contents($baselineFile), true);
    $baseline = is_array($decoded) ? ($decoded['symbols'] ?? []) : [];
}

$newViolations = array_values(array_filter(
    $violations,
    fn(array $v) => !in_array($v['symbol'], $baseline, true)
));

$resolved = array_values(array_diff($baseline, $violatedSymbols));

echo '=== Extension API Check ===' . PHP_EOL;
echo 'Scanned: ' . count($imports) . ' import(s)' . PHP_EOL;
echo 'Baseline: ' . count($baseline) . ' known unstable symbol(s)' . PHP_EOL;
echo PHP_EOL;

if ($resolved !== []) {
    echo '✓ 해소된 심볼 ' . count($resolved) . '개 — baseline 에서 지워 주세요:' . PHP_EOL;
    foreach ($resolved as $symbol) {
        echo "    {$symbol}" . PHP_EOL;
    }
    echo PHP_EOL;
}

if ($newViolations === []) {
    echo '✓ No new extension API violations!' . PHP_EOL;
    exit(0);
}

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

echo '확장은 docs/compatibility-policy.md 의 안정 API 만 사용해야 합니다.' . PHP_EOL;
echo '필요한 기능이 안정 API 에 없다면 Contract 를 추가하거나 이슈로 논의해 주세요.' . PHP_EOL;

exit(1);
