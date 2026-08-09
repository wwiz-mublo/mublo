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
}
