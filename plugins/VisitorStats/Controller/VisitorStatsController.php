<?php

namespace Mublo\Plugin\VisitorStats\Controller;

use Mublo\Core\Context\Context;
use Mublo\Core\Extension\MigrationRunner;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Plugin\VisitorStats\Repository\VisitorCampaignKeyRepository;
use Mublo\Plugin\VisitorStats\Repository\VisitorLogRepository;
use Mublo\Plugin\VisitorStats\Service\ConversionStatsService;
use Mublo\Plugin\VisitorStats\Service\VisitorStatsService;

class VisitorStatsController
{
    private const PLUGIN_NAME = 'VisitorStats';
    private const VIEW_PATH = MUBLO_PLUGIN_PATH . '/VisitorStats/views/Admin/';

    public function __construct(
        private VisitorStatsService $service,
        private ConversionStatsService $conversionService,
        private VisitorLogRepository $logRepo,
        private VisitorCampaignKeyRepository $campaignKeyRepo,
        private MigrationRunner $migrationRunner,
    ) {}

    // =========================================================================
    // 화면 라우트
    // =========================================================================

    public function dashboard(array $params, Context $context): ViewResponse
    {
        if ($redirect = $this->checkInstall()) {
            return $redirect;
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Dashboard')
            ->withData(['pageTitle' => '방문자 통계']);
    }

    public function realtime(array $params, Context $context): ViewResponse
    {
        if ($redirect = $this->checkInstall()) {
            return $redirect;
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Realtime')
            ->withData(['pageTitle' => '실시간 현황']);
    }

    public function pages(array $params, Context $context): ViewResponse
    {
        if ($redirect = $this->checkInstall()) {
            return $redirect;
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Pages')
            ->withData(['pageTitle' => '페이지별 분석']);
    }

    public function referrers(array $params, Context $context): ViewResponse
    {
        if ($redirect = $this->checkInstall()) {
            return $redirect;
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Referrers')
            ->withData(['pageTitle' => '유입 경로']);
    }

    public function environment(array $params, Context $context): ViewResponse
    {
        if ($redirect = $this->checkInstall()) {
            return $redirect;
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Environment')
            ->withData(['pageTitle' => '환경 분석']);
    }

    public function campaigns(array $params, Context $context): ViewResponse
    {
        if ($redirect = $this->checkInstall()) {
            return $redirect;
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Campaigns')
            ->withData(['pageTitle' => '캠페인 통계']);
    }

    public function campaignSettings(array $params, Context $context): ViewResponse
    {
        if ($redirect = $this->checkInstall()) {
            return $redirect;
        }

        $domainId = $context->getDomainId() ?? 1;
        $keys = $this->campaignKeyRepo->getAll($domainId);
        $domain = $context->getDomain() ?? '';

        return ViewResponse::absoluteView(self::VIEW_PATH . 'CampaignSettings')
            ->withData([
                'pageTitle' => '캠페인 키 설정',
                'keys' => $keys,
                'siteDomain' => $domain,
            ]);
    }

    public function conversions(array $params, Context $context): ViewResponse
    {
        if ($redirect = $this->checkInstall()) {
            return $redirect;
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'Conversions')
            ->withData(['pageTitle' => '전환 목록']);
    }

    public function conversionStats(array $params, Context $context): ViewResponse
    {
        if ($redirect = $this->checkInstall()) {
            return $redirect;
        }

        return ViewResponse::absoluteView(self::VIEW_PATH . 'ConversionStats')
            ->withData(['pageTitle' => '전환 통계']);
    }

    // =========================================================================
    // API 라우트
    // =========================================================================

    public function apiSummary(array $params, Context $context): JsonResponse
    {
        try {
            $period = (string) ($context->getRequest()->json('period') ?? 'last_7_days');
            $domainId = $context->getDomainId() ?? 1;

            return JsonResponse::success($this->service->getSummary($domainId, $period));
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    public function apiTrend(array $params, Context $context): JsonResponse
    {
        try {
            $period = (string) ($context->getRequest()->json('period') ?? 'last_7_days');
            $domainId = $context->getDomainId() ?? 1;

            return JsonResponse::success($this->service->getTrend($domainId, $period));
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    public function apiHourly(array $params, Context $context): JsonResponse
    {
        try {
            $period = (string) ($context->getRequest()->json('period') ?? 'last_7_days');
            $domainId = $context->getDomainId() ?? 1;

            return JsonResponse::success($this->service->getHourly($domainId, $period));
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    public function apiRealtime(array $params, Context $context): JsonResponse
    {
        try {
            $request = $context->getRequest();
            $domainId = $context->getDomainId() ?? 1;
            $page = max(1, (int) ($request->json('page') ?? 1));
            $perPage = max(10, min(100, (int) ($request->json('per_page') ?? 30)));

            return JsonResponse::success($this->service->getRealtime($domainId, $page, $perPage));
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    public function apiIpLogs(array $params, Context $context): JsonResponse
    {
        try {
            $request = $context->getRequest();
            $domainId = $context->getDomainId() ?? 1;
            $ipAddress = trim((string) ($request->json('ip_address') ?? ''));
            $page = max(1, (int) ($request->json('page') ?? 1));
            $perPage = max(10, min(100, (int) ($request->json('per_page') ?? 30)));

            if ($ipAddress === '') {
                return JsonResponse::error('IP를 선택해 주세요.');
            }

            if (filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
                return JsonResponse::error('올바른 IP 형식이 아닙니다.');
            }

            return JsonResponse::success($this->service->getIpLogs($domainId, $ipAddress, $page, $perPage));
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    public function apiPages(array $params, Context $context): JsonResponse
    {
        try {
            $request = $context->getRequest();
            $period = (string) ($request->json('period') ?? 'last_7_days');
            $page = max(1, (int) ($request->json('page') ?? 1));
            $domainId = $context->getDomainId() ?? 1;

            return JsonResponse::success($this->service->getPages($domainId, $period, $page));
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    public function apiReferrers(array $params, Context $context): JsonResponse
    {
        try {
            $period = (string) ($context->getRequest()->json('period') ?? 'last_7_days');
            $domainId = $context->getDomainId() ?? 1;

            return JsonResponse::success($this->service->getReferrers($domainId, $period));
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    public function apiEnvironment(array $params, Context $context): JsonResponse
    {
        try {
            $period = (string) ($context->getRequest()->json('period') ?? 'last_7_days');
            $domainId = $context->getDomainId() ?? 1;

            return JsonResponse::success($this->service->getEnvironment($domainId, $period));
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    public function apiCampaigns(array $params, Context $context): JsonResponse
    {
        try {
            $period = (string) ($context->getRequest()->json('period') ?? 'last_7_days');
            $domainId = $context->getDomainId() ?? 1;

            return JsonResponse::success($this->service->getCampaigns($domainId, $period));
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    public function apiCampaignTrend(array $params, Context $context): JsonResponse
    {
        try {
            $request = $context->getRequest();
            $period = (string) ($request->json('period') ?? 'last_7_days');
            $campaignKey = $request->json('campaign_key');
            $domainId = $context->getDomainId() ?? 1;

            return JsonResponse::success($this->service->getCampaignTrend($domainId, $period, $campaignKey));
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    public function apiCampaignKeyCreate(array $params, Context $context): JsonResponse
    {
        try {
            $request = $context->getRequest();
            $domainId = $context->getDomainId() ?? 1;

            $campaignKey = trim((string) ($request->json('campaign_key') ?? ''));
            $groupName = trim((string) ($request->json('group_name') ?? ''));
            $memo = trim((string) ($request->json('memo') ?? ''));

            if ($campaignKey === '') {
                return JsonResponse::error('캠페인 키를 입력해 주세요.');
            }

            if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $campaignKey)) {
                return JsonResponse::error('캠페인 키는 영문, 숫자, _, - 만 사용할 수 있습니다.');
            }

            $existing = $this->campaignKeyRepo->findByKey($domainId, $campaignKey);
            if ($existing) {
                return JsonResponse::error('이미 등록된 캠페인 키입니다.');
            }

            $this->campaignKeyRepo->create([
                'domain_id'    => $domainId,
                'campaign_key' => $campaignKey,
                'group_name'   => $groupName,
                'memo'         => $memo ?: null,
                'is_active'    => 1,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);

            return JsonResponse::success(null, '캠페인 키가 등록되었습니다.');
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    public function apiCampaignKeyUpdate(array $params, Context $context): JsonResponse
    {
        try {
            $request = $context->getRequest();
            $domainId = $context->getDomainId() ?? 1;
            $keyId = (int) ($request->json('key_id') ?? 0);

            if ($keyId <= 0) {
                return JsonResponse::error('잘못된 요청입니다.');
            }

            $existing = $this->campaignKeyRepo->find($keyId, $domainId);
            if (!$existing) {
                return JsonResponse::error('존재하지 않는 키입니다.');
            }

            $updateData = [];
            if ($request->json('group_name') !== null) {
                $updateData['group_name'] = trim((string) $request->json('group_name'));
            }
            if ($request->json('memo') !== null) {
                $updateData['memo'] = trim((string) $request->json('memo')) ?: null;
            }
            if ($request->json('is_active') !== null) {
                $updateData['is_active'] = (int) $request->json('is_active');
            }

            if (empty($updateData)) {
                return JsonResponse::error('변경할 내용이 없습니다.');
            }

            $this->campaignKeyRepo->update($keyId, $domainId, $updateData);

            return JsonResponse::success(null, '캠페인 키가 수정되었습니다.');
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    public function apiCampaignKeyDelete(array $params, Context $context): JsonResponse
    {
        try {
            $domainId = $context->getDomainId() ?? 1;
            $keyId = (int) ($context->getRequest()->json('key_id') ?? 0);

            if ($keyId <= 0) {
                return JsonResponse::error('잘못된 요청입니다.');
            }

            // QR 캠페인 키는 삭제 불가 (시스템 예약)
            $key = $this->campaignKeyRepo->find($keyId, $domainId);
            if ($key && ($key['campaign_key'] ?? '') === 'qr') {
                return JsonResponse::error('QR 캠페인 키는 삭제할 수 없습니다.');
            }

            $this->campaignKeyRepo->delete($keyId, $domainId);

            return JsonResponse::success(null, '캠페인 키가 삭제되었습니다.');
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    // =========================================================================
    // 전환 API
    // =========================================================================

    public function apiCampaignSummary(array $params, Context $context): JsonResponse
    {
        try {
            $period = (string) ($context->getRequest()->json('period') ?? 'last_7_days');
            $domainId = $context->getDomainId() ?? 1;

            return JsonResponse::success($this->conversionService->getCampaignSummary($domainId, $period));
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    public function apiConversionStats(array $params, Context $context): JsonResponse
    {
        try {
            $period = (string) ($context->getRequest()->json('period') ?? 'last_7_days');
            $domainId = $context->getDomainId() ?? 1;

            return JsonResponse::success($this->conversionService->getConversionStats($domainId, $period));
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    public function apiConversions(array $params, Context $context): JsonResponse
    {
        try {
            $request = $context->getRequest();
            $period = (string) ($request->json('period') ?? 'last_7_days');
            $formId = $request->json('form_id') !== null ? (int) $request->json('form_id') : null;
            $campaignKey = $request->json('campaign_key');
            $page = max(1, (int) ($request->json('page') ?? 1));
            $domainId = $context->getDomainId() ?? 1;

            return JsonResponse::success(
                $this->conversionService->getConversionList($domainId, $period, $formId, $campaignKey, $page)
            );
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    public function apiFormConversions(array $params, Context $context): JsonResponse
    {
        try {
            $request = $context->getRequest();
            $period = (string) ($request->json('period') ?? 'last_7_days');
            $formId = (int) ($request->json('form_id') ?? 0);
            $domainId = $context->getDomainId() ?? 1;

            if ($formId <= 0) {
                return JsonResponse::error('폼을 선택해 주세요.');
            }

            return JsonResponse::success(
                $this->conversionService->getFormConversions($domainId, $formId, $period)
            );
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    public function apiDashboardConversions(array $params, Context $context): JsonResponse
    {
        try {
            $period = (string) ($context->getRequest()->json('period') ?? 'last_7_days');
            $domainId = $context->getDomainId() ?? 1;

            return JsonResponse::success($this->conversionService->getDashboardConversions($domainId, $period));
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    public function apiPurge(array $params, Context $context): JsonResponse
    {
        try {
            $domainId = $context->getDomainId() ?? 1;
            $days = max(7, (int) ($context->getRequest()->json('days') ?? 30));
            $deleted = $this->logRepo->purgeOldLogs($days, $domainId);

            return JsonResponse::success(
                ['deleted' => $deleted],
                "{$days}일 이전 로그 {$deleted}건을 삭제했습니다."
            );
        } catch (\Throwable $e) {
            return JsonResponse::error($e->getMessage());
        }
    }

    // =========================================================================
    // 설치
    // =========================================================================

    public function install(array $params, Context $context): JsonResponse
    {
        $result = $this->migrationRunner->run('plugin', self::PLUGIN_NAME, $this->getMigrationPath());

        if ($result['success']) {
            return JsonResponse::success(
                ['redirect' => '/admin/visitor-stats/dashboard'],
                '방문자 통계 플러그인이 설치되었습니다. (실행: ' . count($result['executed']) . '개)'
            );
        }

        return JsonResponse::error('설치 실패: ' . ($result['error'] ?? '알 수 없는 오류'));
    }

    // =========================================================================
    // Private
    // =========================================================================

    private function checkInstall(): ?ViewResponse
    {
        $status = $this->migrationRunner->getStatus('plugin', self::PLUGIN_NAME, $this->getMigrationPath());
        if (!empty($status['pending'])) {
            return ViewResponse::absoluteView(self::VIEW_PATH . 'Install')
                ->withData([
                    'pageTitle' => '방문자 통계 플러그인 설치',
                    'pending'   => $status['pending'],
                ]);
        }
        return null;
    }

    private function getMigrationPath(): string
    {
        return MUBLO_PLUGIN_PATH . '/VisitorStats/database/migrations';
    }
}
