<?php
declare(strict_types=1);

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Infrastructure\Database\Database;
use Mublo\Packages\AiAssistant\Repository\MessageScheduleRepository;
use Mublo\Packages\AiAssistant\Service\FirebaseSchedulePushGateway;
use Mublo\Packages\AiAssistant\Service\ScheduleDispatchService;

if (PHP_SAPI !== 'cli') exit("CLI 전용 스크립트입니다.\n");

$application = require dirname(__DIR__) . '/bootstrap.php';
$application->boot();
$container = DependencyContainer::getInstance();
$database = $container->get(Database::class);
(new MigrationRunner($database))->run(
    'package',
    'AiAssistant',
    MUBLO_PACKAGE_PATH . '/AiAssistant/database/migrations'
);

$serviceAccount = trim((string) env('MUBLO_AI_FIREBASE_SERVICE_ACCOUNT_FILE', ''));
$dispatcher = new ScheduleDispatchService(
    new MessageScheduleRepository($database),
    new FirebaseSchedulePushGateway($serviceAccount)
);
$limit = max(1, min(100, (int) ($argv[1] ?? 50)));
$result = $dispatcher->runDue($limit);
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
