<?php
/**
 * public/index.php
 *
 * 모든 웹 요청의 진입점
 */

$__startTime = microtime(true);

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

// [추가] 쿼리 통계 수집을 위한 전역 변수 초기화
//$GLOBALS['__queryCount'] = 0;
//$GLOBALS['__queryTime'] = 0;

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
    $elapsedMs = (microtime(true) - $__startTime) * 1000;
    
    // DB 쿼리 비중 계산
    //$dbTime = $GLOBALS['__queryTime'] ?? 0;
    //$dbRatio = ($elapsedMs > 0) ? round(($dbTime / $elapsedMs) * 100, 1) : 0;
    //$phpTime = $elapsedMs - $dbTime;

    $memUsedRaw = memory_get_usage(false);
    $memPeakRaw = memory_get_peak_usage(true);

    $memUsed  = formatBytesSmart($memUsedRaw);
    $memPeak  = formatBytesSmart($memPeakRaw);
    $memDelta = formatBytesSmart($memPeakRaw - $memUsedRaw);

    echo "\n\n";

    echo "\n<!-- Debug: {$elapsedMs}ms"
       . " | Mem: {$memUsed} ({$memUsedRaw}B)"
       . " | Peak: {$memPeak} ({$memPeakRaw}B)"
       . " | Δ: {$memDelta}"
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