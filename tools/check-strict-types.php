<?php
/**
 * strict_types 선언 검출 스크립트
 *
 * 런타임 PHP 파일이 declare(strict_types=1) 을 선언했는지 검사합니다.
 *
 * 사용법:
 *   php tools/check-strict-types.php
 *
 * strict_types 는 "그 파일에서 나가는 호출"에만 적용된다. 한 파일이라도 빠지면
 * 그 파일의 호출은 다시 조용히 형변환되므로 전수 적용이 전제다.
 *
 * 검사 대상
 *   src/, packages/, plugins/ 의 .php 런타임 파일
 *
 * 제외 대상
 *   - views/  : HTML 이 섞인 출력 템플릿
 *   - sample/ : dev 전용 정적 사이트 생성기
 *   - tests/  : 픽스처를 느슨하게 넘기는 편이 읽기 쉽다
 */

$basePath = dirname(__DIR__);

$scanDirs = [
    $basePath . '/src',
    $basePath . '/packages',
    $basePath . '/plugins',
];

$excludedSegments = ['/views/', '/sample/', '/tests/'];

$missing = [];
$fileCount = 0;

foreach ($scanDirs as $dir) {
    if (!is_dir($dir)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        foreach ($excludedSegments as $segment) {
            if (str_contains($path, $segment)) {
                continue 2;
            }
        }

        $fileCount++;

        // 선언은 파일 최상단에만 올 수 있으므로 앞부분만 읽으면 충분하다.
        $head = file_get_contents($path, false, null, 0, 512);
        if ($head === false) {
            $missing[] = [str_replace($basePath . '/', '', $path), 'read failed'];
            continue;
        }

        if (!preg_match('/^\s*declare\s*\(\s*strict_types\s*=\s*1\s*\)\s*;/mi', $head)) {
            $missing[] = [str_replace($basePath . '/', '', $path), 'missing declare(strict_types=1)'];
        }
    }
}

echo "=== Strict Types Check ===" . PHP_EOL;
echo "Scanned: {$fileCount} files" . PHP_EOL;
echo PHP_EOL;

if (empty($missing)) {
    echo "✓ All runtime files declare strict_types!" . PHP_EOL;
    exit(0);
}

echo "✗ Found " . count($missing) . " file(s) without strict_types:" . PHP_EOL;
echo PHP_EOL;

foreach ($missing as [$file, $reason]) {
    echo "  {$file} — {$reason}" . PHP_EOL;
}

echo PHP_EOL;
echo "  파일 첫 줄 `<?php` 바로 다음에 `declare(strict_types=1);` 을 추가하세요." . PHP_EOL;

exit(1);
