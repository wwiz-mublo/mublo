<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Service;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Repository\SaasRepository;

final class SaasService
{
    public function __construct(private SaasRepository $repository)
    {
    }

    /** @return array<string, mixed> */
    public function dashboard(?int $domainId, ?string $frameworkUserId): array
    {
        $loginId = trim((string) $frameworkUserId);
        if ($domainId === null || $loginId === '') {
            throw new ApiException('SAAS_CONTEXT_REQUIRED', '회사 운영 context를 확인할 수 없습니다.', 403);
        }
        $principal = $this->repository->principal($domainId, $loginId);
        if ($principal === null) {
            throw new ApiException('SAAS_ROLE_NOT_MAPPED', '현재 관리자 계정에 AI 비서 회사 역할이 연결되지 않았습니다.', 403);
        }
        if (!in_array($principal['role'], ['OWNER', 'MANAGER'], true)) {
            throw new ApiException('ROLE_FORBIDDEN', '이 운영 화면은 OWNER 또는 MANAGER만 볼 수 있습니다.', 403);
        }
        $companyId = (string) $principal['company_id'];
        return [
            'principal' => $principal,
            'summary' => $this->repository->summary($companyId),
            'batches' => $this->repository->recentBatches($companyId),
            'customers' => $this->repository->recentCustomers($companyId),
            'workers' => $this->repository->workers(),
        ];
    }

    /** @return array<string, mixed> */
    public function workspace(?int $domainId, ?string $frameworkUserId, string $section = 'dashboard'): array
    {
        $principal = $this->requirePrincipal($domainId, $frameworkUserId);
        if (!in_array($principal['role'], ['OWNER', 'MANAGER', 'STAFF'], true)) {
            throw new ApiException('ROLE_FORBIDDEN', 'AI 비서 워크스페이스를 사용할 권한이 없습니다.', 403);
        }

        $companyId = (string) $principal['company_id'];
        $allowed = ['dashboard', 'customers', 'analysis', 'schedules', 'devices', 'company'];
        if (!in_array($section, $allowed, true)) {
            $section = 'dashboard';
        }

        $data = [
            'section' => $section,
            'principal' => $principal,
            'summary' => $this->repository->summary($companyId),
            'customers' => [],
            'batches' => [],
            'schedules' => [],
            'devices' => [],
            'subscription' => null,
            'companyUsers' => [],
        ];

        if (in_array($section, ['dashboard', 'customers'], true)) {
            $data['customers'] = $this->repository->recentCustomers($companyId, $section === 'customers' ? 50 : 6);
        }
        if (in_array($section, ['dashboard', 'analysis'], true)) {
            $data['batches'] = $this->repository->recentBatches($companyId, $section === 'analysis' ? 50 : 6);
        }
        if (in_array($section, ['dashboard', 'schedules'], true)) {
            $data['schedules'] = $this->repository->recentSchedules($companyId, $section === 'schedules' ? 50 : 6);
        }
        if (in_array($section, ['dashboard', 'devices'], true)) {
            $data['devices'] = $this->repository->devices($companyId, $section === 'devices' ? 50 : 6);
        }
        if (in_array($section, ['dashboard', 'company'], true)) {
            $data['subscription'] = $this->repository->subscription($companyId);
            $data['companyUsers'] = $this->repository->companyUsers($companyId);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function requirePrincipal(?int $domainId, ?string $frameworkUserId): array
    {
        $loginId = trim((string) $frameworkUserId);
        if ($domainId === null || $loginId === '') {
            throw new ApiException('SAAS_CONTEXT_REQUIRED', '회사 운영 context를 확인할 수 없습니다.', 403);
        }
        $principal = $this->repository->principal($domainId, $loginId);
        if ($principal === null) {
            throw new ApiException('SAAS_ROLE_NOT_MAPPED', '현재 회원 계정에 AI 비서 회사가 연결되지 않았습니다.', 403);
        }
        return $principal;
    }

    /** @return array<string, mixed> */
    public function platform(string $section = 'dashboard'): array
    {
        $allowed = ['dashboard', 'companies', 'delivery', 'infrastructure'];
        if (!in_array($section, $allowed, true)) {
            $section = 'dashboard';
        }
        return [
            'section' => $section,
            'summary' => $this->repository->platformSummary(),
            'companies' => in_array($section, ['dashboard', 'companies'], true)
                ? $this->repository->platformCompanies($section === 'companies' ? 200 : 8) : [],
            'delivery' => in_array($section, ['dashboard', 'delivery'], true)
                ? $this->repository->platformDelivery($section === 'delivery' ? 200 : 8) : [],
            'analysis' => in_array($section, ['dashboard', 'infrastructure'], true)
                ? $this->repository->platformAnalysis($section === 'infrastructure' ? 200 : 8) : [],
            'workers' => in_array($section, ['dashboard', 'infrastructure'], true)
                ? $this->repository->workers() : [],
        ];
    }
}
