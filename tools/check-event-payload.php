<?php
require_once __DIR__ . '/ExtensionApiPath.php';
require_once __DIR__ . '/ExtensionApiSurface.php';
require_once __DIR__ . '/EventPayloadScanner.php';

use Mublo\Tools\EventPayloadScanner;

/**
 * 이벤트 payload 누출 검출 스크립트
 *
 * 이벤트가 게터 반환 타입으로 내부 타입을 확장에 넘기고 있지 않은지 검사합니다.
 *
 * 사용법:
 *   php tools/check-event-payload.php            # 검사
 *   php tools/check-event-payload.php --baseline # 현재 위반을 베이스라인으로 다시 기록
 *
 * 왜 필요한가
 *   check-extension-api.php 는 확장이 "import 한 심볼"만 봅니다. 이벤트는 정책상 안정 API 라
 *   무조건 통과하므로, 이벤트가 반환하는 타입을 타고 들어가는 의존은 그 검사기에 보이지 않습니다.
 *
 *       use Mublo\Entity\Member\Member;            // 잡힌다 — use 문이 보인다
 *       $event->getMember()->getLevelValue();      // 안 잡힌다 — use 문이 없다
 *
 *   두 번째 형태로도 확장은 코어 엔티티에 그대로 묶이고, 코어가 그 엔티티를 리팩터링하면 깨집니다.
 *   실제로 이 사각지대에서 세 건이 자라났고 그중 하나는 사용자에게 보이는 버그였습니다.
 *
 *   소비자 쪽에서 이것을 잡으려면 타입 추론이 필요하지만, 생산자 쪽에서는 반환 타입만 보면 됩니다.
 *   그래서 "안정 이벤트는 불안정 타입을 반환하지 않는다" 를 이벤트 정의에서 강제합니다.
 *
 * 검사 대상
 *   check-extension-api.php 의 isStable() 이 무조건 통과시키는 것과 같은 기준으로 이벤트를 고릅니다
 *   (`\Event\` 아래이거나 이름이 `Event` 로 끝남). 저쪽이 눈감아 주는 표면이 이쪽 대상입니다.
 *
 * 현재 정책
 *   기존 위반은 baseline 에 동결하고 새 위반만 실패시킵니다. 해소된 항목이 baseline 에 남아 있어도
 *   실패합니다 — 목록이 현실보다 커지면 다음 사람이 그것을 허용 목록으로 읽습니다.
 */

$basePath = dirname(__DIR__);
$baselineFile = __DIR__ . '/event-payload-baseline.json';
$rewriteBaseline = in_array('--baseline', $argv, true);

$scanDirs = [$basePath . '/src', $basePath . '/packages', $basePath . '/plugins'];

/** @param array{file: string, symbol: string} $occurrence */
function occurrenceKey(array $occurrence): string
{
    return $occurrence['file'] . "\0" . $occurrence['symbol'];
}

// ---------------------------------------------------------------------------

$leaks = EventPayloadScanner::scan($scanDirs, $basePath);

$current = [];
foreach ($leaks as $leak) {
    $current[occurrenceKey($leak)] ??= $leak;
}
ksort($current);

// --baseline: 현재 상태를 기록하고 종료
if ($rewriteBaseline) {
    $items = array_map(
        static fn(array $item): array => ['file' => $item['file'], 'symbol' => $item['symbol']],
        array_values($current)
    );

    $payload = [
        '_comment' => '이벤트가 반환하는 기존 비안정 타입. 새 occurrence 추가 금지 — 이벤트는 스칼라나 Contract DTO 로 payload 를 넘긴다.',
        '_generated_by' => 'php tools/check-event-payload.php --baseline',
        'occurrences' => $items,
    ];

    file_put_contents(
        $baselineFile,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"
    );

    echo '베이스라인을 기록했습니다: ' . count($items) . '개 occurrence' . PHP_EOL;
    exit(0);
}

$baseline = [];
$baselineErrors = [];

if (!is_file($baselineFile)) {
    $baselineErrors[] = 'baseline 파일이 없습니다: tools/event-payload-baseline.json';
} else {
    $decoded = null;
    try {
        $decoded = json_decode((string) file_get_contents($baselineFile), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $baselineErrors[] = 'baseline 이 올바른 JSON 이 아닙니다: ' . $e->getMessage();
    }

    $rawOccurrences = is_array($decoded) ? ($decoded['occurrences'] ?? null) : null;
    if ($decoded !== null && !is_array($rawOccurrences)) {
        $baselineErrors[] = 'baseline 에 occurrences 배열이 없습니다.';
    }

    foreach (is_array($rawOccurrences) ? $rawOccurrences : [] as $index => $raw) {
        if (!is_array($raw)
            || !is_string($raw['file'] ?? null)
            || ($raw['file'] ?? '') === ''
            || !is_string($raw['symbol'] ?? null)
            || ($raw['symbol'] ?? '') === '') {
            $baselineErrors[] = "occurrences[{$index}] 형식이 잘못되었습니다.";
            continue;
        }

        $item = ['file' => str_replace('\\', '/', $raw['file']), 'symbol' => $raw['symbol']];
        $key = occurrenceKey($item);
        if (isset($baseline[$key])) {
            $baselineErrors[] = "중복 occurrence ({$item['file']} -> {$item['symbol']}).";
            continue;
        }

        $baseline[$key] = $item;
    }
    ksort($baseline);
}

$newLeaks = array_values(array_diff_key($current, $baseline));
$resolved = array_values(array_diff_key($baseline, $current));

echo '=== Event Payload Check ===' . PHP_EOL;
echo 'Scanned: ' . count($leaks) . ' event payload reference(s)' . PHP_EOL;
echo 'Baseline: ' . count($baseline) . ' occurrence(s)' . PHP_EOL;
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

if ($newLeaks === [] && $resolved === [] && $baselineErrors === []) {
    echo '✓ No new event payload leaks!' . PHP_EOL;
    exit(0);
}

if ($newLeaks !== []) {
    echo '✗ 이벤트가 새로 노출하는 비안정 타입 ' . count($newLeaks) . '건:' . PHP_EOL . PHP_EOL;

    $grouped = [];
    foreach ($newLeaks as $leak) {
        $grouped[$leak['file']][] = $leak;
    }

    foreach ($grouped as $file => $items) {
        echo "  {$file}:" . PHP_EOL;
        foreach ($items as $item) {
            echo "    L{$item['line']}: {$item['symbol']}" . PHP_EOL;
        }
        echo PHP_EOL;
    }
}

echo '이벤트는 확장에 안정 API 로 노출됩니다 — payload 도 안정 표면 안에 있어야 합니다.' . PHP_EOL;
echo '엔티티 대신 스칼라 게터나 Contract DTO 로 필요한 값만 넘겨주세요.' . PHP_EOL;

exit(1);
