<?php
/**
 * DI 위반 검출 스크립트
 *
 * Service/Controller/Repository에서 직접 인스턴스 생성(new) 패턴을 검출합니다.
 *
 * 사용법:
 *   php tools/check-di-violations.php
 *
 * 검출 패턴:
 *   1. ?? new SomeRepository/Service() (선택적 DI 폴백)
 *   2. = new SomeRepository/Service() (생성자 내 직접 생성)
 *   3. new SessionManager() / new DatabaseManager() (인프라 직접 생성)
 *   4. DatabaseManager::getInstance() / CacheFactory::getInstance() (정적 팩토리)
 *
 * 허용 패턴 (false positive 제외):
 *   - new UploadedFile() (DTO/값 객체)
 *   - new Result() (결과 객체)
 *   - new \Exception / new \RuntimeException 등 (예외)
 *   - Entity 생성 (new BoardArticle, new Member 등)
 *   - Event 생성 (new ArticleCreatedEvent 등)
 */

$basePath = dirname(__DIR__);

$scanDirs = [
    $basePath . '/src/Controller',
    $basePath . '/src/Service',
];

// 검출 대상 클래스 패턴 (정규식)
$violationPatterns = [
    // ?? new 패턴
    '/\?\?\s*new\s+\w+/' => '?? new fallback pattern',
    // = new Repository/Service (constructor 내) — FQCN(new \Mublo\...\XxxRepository)도 잡는다
    '/=\s*new\s+\\\\?[\w\\\\]*(Repository|Service|Manager|Cache|Resolver)\s*\(/' => 'Direct instantiation of Repository/Service/Manager',
    // 정적 팩토리 호출
    '/[\w\\\\]+Manager::getInstance\(\)/' => 'Static factory getInstance()',
    '/CacheFactory::getInstance\(\)/' => 'CacheFactory static call',
    // new SessionManager
    '/new\s+SessionManager\s*\(/' => 'Direct SessionManager instantiation',
];

// 허용 패턴 (이 줄은 무시)
$allowedPatterns = [
    '/new\s+(UploadedFile|Result|\\\\Exception|\\\\RuntimeException|\\\\InvalidArgumentException|\\\\LogicException)/',
    '/new\s+\w*Event\s*\(/',     // 이벤트 객체
    '/new\s+\w*Entity\s*\(/',    // 엔티티 객체
    '/new\s+Board(Article|Comment|Config|Group|Category|Reaction|Attachment|Link|Permission|Page|Row|Column)\s*\(/',
    '/new\s+Member\s*\(/',
    '/new\s+MemberLevel\s*\(/',
    '/new\s+Domain\s*\(/',
    '/new\s+Policy\s*\(/',
    '/new\s+Block(Row|Column|Page)\s*\(/',
    '/new\s+Menu(Item)?\s*\(/',
    '/new\s+\\\\DateTimeImmutable/',
    '/new\s+\\\\DateTime/',
    '/new\s+\\\\stdClass/',
    '/new\s+self\s*\(/',
    '/new\s+static\s*\(/',
];

$violations = [];
$fileCount = 0;

foreach ($scanDirs as $dir) {
    if (!is_dir($dir)) continue;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') continue;

        $fileCount++;
        $filePath = $file->getPathname();
        $relativePath = str_replace($basePath . DIRECTORY_SEPARATOR, '', $filePath);
        $lines = file($filePath, FILE_IGNORE_NEW_LINES);

        foreach ($lines as $lineNum => $line) {
            $actualLine = $lineNum + 1;

            foreach ($violationPatterns as $pattern => $description) {
                if (!preg_match($pattern, $line)) continue;

                // 허용 패턴 체크
                $isAllowed = false;
                foreach ($allowedPatterns as $allowed) {
                    if (preg_match($allowed, $line)) {
                        $isAllowed = true;
                        break;
                    }
                }

                if ($isAllowed) continue;

                // 주석인지 확인
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                    continue;
                }

                $violations[] = [
                    'file' => $relativePath,
                    'line' => $actualLine,
                    'code' => trim($line),
                    'type' => $description,
                ];
            }
        }
    }
}

// 결과 출력
echo "=== DI Violation Check ===" . PHP_EOL;
echo "Scanned: {$fileCount} files" . PHP_EOL;
echo PHP_EOL;

if (empty($violations)) {
    echo "✓ No DI violations found!" . PHP_EOL;
    exit(0);
}

echo "✗ Found " . count($violations) . " violation(s):" . PHP_EOL;
echo PHP_EOL;

// 파일별 그룹핑
$grouped = [];
foreach ($violations as $v) {
    $grouped[$v['file']][] = $v;
}

foreach ($grouped as $file => $items) {
    echo "  {$file}:" . PHP_EOL;
    foreach ($items as $item) {
        echo "    L{$item['line']}: [{$item['type']}]" . PHP_EOL;
        echo "      {$item['code']}" . PHP_EOL;
    }
    echo PHP_EOL;
}

exit(1);
