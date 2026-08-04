<?php
/**
 * public/index.php
 *
 * 모든 웹 요청의 진입점
 */

$__startTimeNs = hrtime(true);
$__startMemory = memory_get_usage(false);

// 웹 루트 경로를 자동 감지 (public, www 등 디렉토리명에 무관하게 동작)
define('MUBLO_PUBLIC_PATH', __DIR__);

// Bootstrap
$app = require __DIR__ . '/../bootstrap.php';

// 설치 여부 확인 및 라우팅
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$isInstallPath = str_starts_with($requestUri, '/install');

if (!isInstalled() && !$isInstallPath) {
    header('Location: /install');
    exit;
}

if (isInstalled() && $isInstallPath) {
    header('Location: /');
    exit;
}

if ($isInstallPath) {
    require __DIR__ . '/install/index.php';
    exit;
}

// 애플리케이션 실행
$app->boot();
$app->run();

// 디버그 정보 (개발 모드, HTML 응답만)
//
// 판단 근거는 '요청의 Accept' 가 아니라 '실제로 내보낸 응답' 이어야 한다.
// 브라우저에서 링크를 눌러 파일을 받으면 그것도 일반 내비게이션이라
// Accept: text/html 이 그대로 실려 온다. 요청 헤더로 판단하면 다운로드
// 파일 끝에 이 주석이 붙어 JSON·CSV·엑셀이 깨진다(블록킷 내보내기에서 발견).
if (($_ENV['APP_DEBUG'] ?? 'false') === 'true' && isHtmlDocumentResponse()) {
    // hrtime은 시스템 시각 변경의 영향을 받지 않는 단조 시계다.
    $elapsedMs = (hrtime(true) - $__startTimeNs) / 1_000_000;
    $memUsedRaw = memory_get_usage(false);
    $memPeakRaw = memory_get_peak_usage(false);
    $memDeltaRaw = $memUsedRaw - $__startMemory;
    $memPeakDeltaRaw = max(0, $memPeakRaw - $__startMemory);

    $elapsed = number_format($elapsedMs, 2, '.', '');
    $memUsed = formatBytesSmart($memUsedRaw);
    $memPeak = formatBytesSmart($memPeakRaw);
    $memDelta = formatSignedBytesSmart($memDeltaRaw);
    $memPeakDelta = formatSignedBytesSmart($memPeakDeltaRaw);
    $queryStats = collectQueryDebugStats($elapsedMs);

    echo "\n\n<!-- Debug: {$elapsed}ms"
       . " | SQL: {$queryStats['count']}q / {$queryStats['time']}ms ({$queryStats['ratio']}%)"
       . " | Non-SQL: {$queryStats['non_sql']}ms"
       . " | Slowest: {$queryStats['slowest']}ms"
       . ($queryStats['failed'] > 0 ? " / Failed: {$queryStats['failed']}" : '')
       . " | Mem: {$memUsed} ({$memUsedRaw}B)"
       . " | ΔMem: {$memDelta}"
       . " | Peak: {$memPeak} ({$memPeakRaw}B)"
       . " | ΔPeak: {$memPeakDelta}"
       . " -->\n";
}

// ============================================================
// Helper Functions
// ============================================================

/**
 * 지금 내보낸 응답이 '브라우저에 그려질 HTML 문서' 인지 판단한다.
 *
 * 디버그 푸터를 붙여도 되는지 가리는 용도다. 다음 두 경우는 제외한다.
 *  - Content-Type 이 text/html 이 아닌 응답 (JSON·CSV·이미지 등)
 *  - Content-Disposition: attachment (다운로드) — 내용이 HTML 이어도 파일이다
 *
 * Content-Type 을 명시하지 않은 응답은 PHP 기본값이 text/html 이므로 HTML 로 본다.
 */
function isHtmlDocumentResponse(): bool
{
    $isHtml = true;   // Content-Type 미지정 시 PHP 기본값이 text/html
    $seenContentType = false;

    foreach (headers_list() as $header) {
        if (stripos($header, 'content-disposition:') === 0 && stripos($header, 'attachment') !== false) {
            return false;
        }
        if (stripos($header, 'content-type:') === 0) {
            $seenContentType = true;
            $isHtml = stripos($header, 'text/html') !== false;
        }
    }

    return $seenContentType ? $isHtml : true;
}

/**
 * 설치 완료 여부 확인
 *
 * installed.lock은 설치 마지막 단계에서만 생성되므로
 * lock + config 파일 존재만으로 설치 완료 판단.
 * DB 장애 시 설치 페이지로 리다이렉트되는 오동작 방지.
 */
function isInstalled(): bool
{
    static $result = null;

    if ($result !== null) {
        return $result;
    }

    $lockFile = MUBLO_STORAGE_PATH . '/installed.lock';
    $dbConfig = MUBLO_CONFIG_PATH . '/database.php';

    return $result = file_exists($lockFile) && file_exists($dbConfig);
}

/**
 * 바이트를 사람이 읽기 쉬운 단위로 변환 (B, KB, MB, GB, TB)
 * 단위별로 소수 정밀도를 다르게 적용
 */
function formatBytesSmart(int|float $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }

    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $base  = 1024;

    $pow = (int) floor(log($bytes, $base));
    $pow = min($pow, count($units) - 1);

    $value = $bytes / ($base ** $pow);

    // 단위별 정밀도 (작을수록 더 정확히)
    $precision = match ($units[$pow]) {
        'B'  => 0,
        'KB' => 2,
        'MB' => 3,
        'GB', 'TB' => 4,
        default => 2,
    };

    return number_format($value, $precision) . ' ' . $units[$pow];
}

/** 요청 시작점 대비 메모리 차이를 부호와 함께 표시한다. */
function formatSignedBytesSmart(int|float $bytes): string
{
    $sign = $bytes < 0 ? '-' : '+';

    return $sign . formatBytesSmart(abs($bytes));
}

/**
 * 개발 모드에서 Database가 이미 수집한 쿼리 로그를 요청 단위 통계로 요약한다.
 * QueryBuilder는 Database 실행 메서드를 사용하므로 여기서 따로 더하지 않는다.
 * getPdo()로 직접 실행한 SQL과 최초 연결 시간은 이 통계에 포함되지 않는다.
 *
 * @return array{count:int, time:string, ratio:string, non_sql:string, slowest:string, failed:int}
 */
function collectQueryDebugStats(float $elapsedMs): array
{
    $queryLog = [];

    try {
        $container = \Mublo\Core\Container\DependencyContainer::getInstance();
        $database = $container->get(\Mublo\Infrastructure\Database\Database::class);
        $queryLog = $database->getQueryLog();
    } catch (\Throwable) {
        // 설치 화면이나 DB 미사용 응답에서도 디버그 푸터 자체는 출력한다.
    }

    $queryTimeMs = 0.0;
    $slowestMs = 0.0;
    $failed = 0;

    foreach ($queryLog as $query) {
        $durationMs = max(0.0, (float) ($query['duration_ms'] ?? 0.0));
        $queryTimeMs += $durationMs;
        $slowestMs = max($slowestMs, $durationMs);

        if (($query['error'] ?? null) !== null) {
            $failed++;
        }
    }

    $ratio = $elapsedMs > 0 ? min(100.0, ($queryTimeMs / $elapsedMs) * 100) : 0.0;
    $nonSqlMs = max(0.0, $elapsedMs - $queryTimeMs);

    return [
        'count' => count($queryLog),
        'time' => number_format($queryTimeMs, 2, '.', ''),
        'ratio' => number_format($ratio, 1, '.', ''),
        'non_sql' => number_format($nonSqlMs, 2, '.', ''),
        'slowest' => number_format($slowestMs, 2, '.', ''),
        'failed' => $failed,
    ];
}
