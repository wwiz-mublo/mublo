<?php
declare(strict_types=1);

/**
 * AI Assistant 회사와 최초 OWNER를 1회 생성한다.
 * 비밀번호는 명령행 인자에 남기지 않고 표준입력 한 줄로 받는다.
 *
 * php tools/provision-ai-assistant.php <slug> <company_name> <login_id> [nickname] [framework_domain_id]
 */

use Mublo\Core\Extension\MigrationRunner;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Packages\AiAssistant\Repository\CompanyUserRepository;
use Mublo\Packages\AiAssistant\Service\CompanyProvisioningService;

if (PHP_SAPI !== 'cli') {
    exit("CLI 전용 스크립트입니다.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

$slug = trim((string) ($argv[1] ?? ''));
$companyName = trim((string) ($argv[2] ?? ''));
$loginId = trim((string) ($argv[3] ?? ''));
$nickname = trim((string) ($argv[4] ?? $loginId));
$domainIdValue = trim((string) ($argv[5] ?? ''));
$frameworkDomainId = $domainIdValue === '' ? null : filter_var($domainIdValue, FILTER_VALIDATE_INT);
if ($slug === '' || $companyName === '' || $loginId === '' || $frameworkDomainId === false) {
    fwrite(STDERR, "사용법: php tools/provision-ai-assistant.php <slug> <company_name> <login_id> [nickname] [framework_domain_id]\n");
    fwrite(STDERR, "비밀번호는 실행 직후 표준입력 한 줄로 전달하세요.\n");
    exit(1);
}

fwrite(STDERR, "OWNER 비밀번호 입력: ");
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($password === '') {
    fwrite(STDERR, "비밀번호가 비어 있습니다.\n");
    exit(1);
}

try {
    $db = DatabaseManager::getInstance()->loadFromConfig()->connect();
    $migration = (new MigrationRunner($db))->run(
        'package',
        'AiAssistant',
        MUBLO_PACKAGE_PATH . '/AiAssistant/database/migrations'
    );
    if (($migration['success'] ?? false) !== true) {
        throw new RuntimeException((string) ($migration['error'] ?? '마이그레이션 실패'));
    }
    $result = (new CompanyProvisioningService(new CompanyUserRepository($db)))->provisionOwner(
        $frameworkDomainId === null ? null : (int) $frameworkDomainId,
        $slug,
        $companyName,
        $loginId,
        $nickname,
        $password
    );
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, '생성 실패: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
