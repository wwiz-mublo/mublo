<?php

namespace Mublo\Controller\Admin;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\FileResponse;
use Mublo\Core\Http\Request;
use Mublo\Core\Context\Context;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Extension\ExtensionLoadDiagnostics;
use Mublo\Service\System\SystemService;
use Mublo\Service\System\DataResetService;
use Mublo\Service\System\DatabaseBackupService;
use Mublo\Service\Extension\ExtensionService;
use Mublo\Service\Auth\AuthService;

/**
 * Admin SystemController
 *
 * 시스템 관리 (캐시 초기화, DB 백업, 마이그레이션 점검/실행, 임시파일 정리, 데이터 초기화)
 *
 * GET  /admin/system              → 시스템 관리 페이지
 * POST /admin/system/clearCache   → 캐시 초기화 (AJAX)
 * POST /admin/system/runMigration → 마이그레이션 실행 (AJAX)
 * POST /admin/system/backup-database → 데이터베이스 백업 다운로드 (SUPER)
 * POST /admin/system/cleanupTemp  → 임시파일 정리 (AJAX)
 * POST /admin/system/resetData    → 항목별 데이터 초기화 (AJAX)
 * POST /admin/system/resetAll     → 전체 데이터 초기화 (AJAX)
 */
class SystemController
{
    private SystemService $systemService;
    private ExtensionService $extensionService;
    private DataResetService $dataResetService;
    private DatabaseBackupService $databaseBackupService;
    private AuthService $authService;
    private DependencyContainer $container;

    public function __construct(
        SystemService $systemService,
        ExtensionService $extensionService,
        DataResetService $dataResetService,
        DatabaseBackupService $databaseBackupService,
        AuthService $authService,
        DependencyContainer $container
    ) {
        $this->systemService = $systemService;
        $this->extensionService = $extensionService;
        $this->dataResetService = $dataResetService;
        $this->databaseBackupService = $databaseBackupService;
        $this->authService = $authService;
        $this->container = $container;
    }

    /**
     * 시스템 관리 페이지
     *
     * GET /admin/system
     */
    public function index(array $params, Context $context): ViewResponse
    {
        $domainId = $context->getDomainId();
        $cacheInfo = $this->systemService->getCacheInfo();
        $migrationStatuses = $this->systemService->getAllMigrationStatus(
            $this->extensionService->getEnabledPlugins($domainId),
            $this->extensionService->getEnabledPackages($domainId)
        );

        $totalPending = 0;
        $totalExecuted = 0;
        foreach ($migrationStatuses as $status) {
            $totalPending += count($status['pending']);
            $totalExecuted += count($status['executed']);
        }

        $tempFileInfo = $this->systemService->getTempFileInfo();
        $extensionLoadFailures = [];
        if ($this->container->has(ExtensionLoadDiagnostics::class)) {
            $extensionLoadFailures = $this->container
                ->get(ExtensionLoadDiagnostics::class)
                ->all();
        }

        // 데이터 초기화 항목 (SUPER 전용)
        $resetItems = [];
        if ($this->authService->isSuper()) {
            $resetItems = $this->dataResetService->getResetItems($domainId);
        }

        return ViewResponse::view('system/index')
            ->withData([
                'pageTitle' => '시스템 관리',
                'title' => '시스템 관리',
                'description' => '캐시 초기화, 데이터베이스 마이그레이션 점검, 임시파일 정리를 수행합니다.',
                'cacheInfo' => $cacheInfo,
                'migrationStatuses' => $migrationStatuses,
                'totalPending' => $totalPending,
                'totalExecuted' => $totalExecuted,
                'tempFileInfo' => $tempFileInfo,
                'extensionLoadFailures' => $extensionLoadFailures,
                'resetItems' => $resetItems,
                'isSuper' => $this->authService->isSuper(),
                'activeCode' => '002_005',
            ]);
    }

    /**
     * 캐시 초기화 (AJAX)
     *
     * POST /admin/system/clearCache
     */
    public function clearCache(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId();
        $domainName = $this->resolvedDomainName($context);
        $result = $this->systemService->clearAllCache($domainId, $domainName);

        return $result->isSuccess()
            ? JsonResponse::success(null, $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 미실행 마이그레이션 실행 (AJAX)
     *
     * POST /admin/system/runMigration
     */
    public function runMigration(array $params, Context $context): JsonResponse
    {
        $domainId = $context->getDomainId();
        $enabledPlugins = $this->extensionService->getEnabledPlugins($domainId);
        $enabledPackages = $this->extensionService->getEnabledPackages($domainId);

        // 실행 전: 미실행 마이그레이션을 가진 확장만 파악한다.
        // 시드 재실행은 "이번에 실제로 마이그레이션이 돈 확장"으로 한정해야,
        // install()이 자체 마이그레이션하는 확장(예: Faq)까지 무차별 재호출돼 시드가 중복되는 일이 없다.
        $migratedExtensions = [];
        foreach ($this->systemService->getAllMigrationStatus($enabledPlugins, $enabledPackages) as $status) {
            if ($status['source'] !== 'core' && !empty($status['pending'])) {
                $migratedExtensions[] = ['type' => $status['source'], 'name' => $status['name']];
            }
        }

        $result = $this->systemService->runPendingMigrations($enabledPlugins, $enabledPackages);

        if ($result->isSuccess()) {
            // 마이그레이션으로 테이블이 생긴 확장만, 활성화 시점에 건너뛴 시드를 마무리한다.
            // (예: 최초 설치 도메인의 Shop 배송 템플릿 — install()은 멱등이라 재호출 안전)
            if (!empty($migratedExtensions)) {
                $this->extensionService->seedExtensions($migratedExtensions, $this->container, $context);
            }

            return JsonResponse::success($result->getData(), $result->getMessage());
        }

        return JsonResponse::error($result->getMessage(), $result->getData());
    }

    /**
     * 전체 데이터베이스 백업 생성 및 다운로드 (SUPER 전용)
     *
     * POST /admin/system/backup-database
     */
    public function backupDatabase(Request $request, Context $context): FileResponse|JsonResponse
    {
        if ($request->getMethod() !== 'POST') {
            return JsonResponse::error('허용되지 않은 요청 방식입니다.', null, 405);
        }

        if (!$this->authService->isSuper()) {
            return JsonResponse::forbidden('SUPER 관리자만 사용할 수 있습니다.');
        }

        $password = (string) ($request->json('password') ?? '');
        if ($password === '') {
            return JsonResponse::validationError(
                ['password' => ['관리자 비밀번호를 입력해주세요.']],
                '관리자 비밀번호를 입력해주세요.'
            );
        }

        $memberId = $this->authService->id();
        if (!$this->dataResetService->verifyPassword($memberId, $password)) {
            return JsonResponse::error('비밀번호가 일치하지 않습니다.', null, 422);
        }

        $result = $this->databaseBackupService->create();
        if ($result->isFailure()) {
            return JsonResponse::serverError($result->getMessage());
        }

        return new FileResponse(
            $result->get('filePath'),
            200,
            [
                'Content-Type' => $result->get('mimeType'),
                'Content-Disposition' => 'attachment; filename="' . $result->get('fileName') . '"',
                'Content-Length' => (string) $result->get('size', 0),
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
            deleteAfterSend: true
        );
    }

    /**
     * 임시파일 정리 (AJAX)
     *
     * POST /admin/system/cleanupTemp
     */
    public function cleanupTemp(Request $request, Context $context): JsonResponse
    {
        $maxAgeHours = (int) ($request->json('maxAgeHours') ?? 24);
        if ($maxAgeHours < 1) {
            $maxAgeHours = 1;
        }

        $result = $this->systemService->cleanupTempFiles($maxAgeHours);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 항목별 데이터 초기화 (AJAX)
     *
     * POST /admin/system/resetData
     */
    public function resetData(Request $request, Context $context): JsonResponse
    {
        if (!$this->authService->isSuper()) {
            return JsonResponse::error('SUPER 관리자만 사용할 수 있습니다.');
        }

        $category = $request->json('category') ?? '';
        $password = $request->json('password') ?? '';

        if (empty($category) || empty($password)) {
            return JsonResponse::error('카테고리와 비밀번호를 입력해주세요.');
        }

        $domainId = $context->getDomainId();
        $memberId = $this->authService->id();

        $result = $this->dataResetService->resetCategory($category, $domainId, $memberId, $password);

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 전체 데이터 초기화 (AJAX)
     *
     * POST /admin/system/resetAll
     */
    public function resetAll(Request $request, Context $context): JsonResponse
    {
        if (!$this->authService->isSuper()) {
            return JsonResponse::error('SUPER 관리자만 사용할 수 있습니다.');
        }

        $password = $request->json('password') ?? '';
        $confirmText = $request->json('confirmText') ?? '';

        if (empty($password) || empty($confirmText)) {
            return JsonResponse::error('비밀번호와 확인 문구를 입력해주세요.');
        }

        $domainId = $context->getDomainId();
        $memberId = $this->authService->id();

        $result = $this->dataResetService->resetAll($domainId, $memberId, $password, $confirmText);

        // 전체 초기화 후 캐시도 초기화
        if ($result->isSuccess()) {
            $this->systemService->clearAllCache($domainId, $this->resolvedDomainName($context));
        }

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    /**
     * 라우트 캐시는 raw Host가 아니라 DomainResolver가 해석한 정식 도메인으로 생성된다.
     * 서브도메인 폴백 요청에서도 같은 키를 지우도록 정식 도메인을 우선한다.
     */
    private function resolvedDomainName(Context $context): ?string
    {
        return $context->getDomainInfo()?->getDomain() ?? $context->getDomain();
    }
}
