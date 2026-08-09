<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Service;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Repository\CompanyUserRepository;
use Mublo\Packages\AiAssistant\Support\Uuid;

final class CompanyProvisioningService
{
    public function __construct(private CompanyUserRepository $repository)
    {
    }

    /** @return array{company_id: string, user_id: string, role: string} */
    public function provisionOwner(
        ?int $frameworkDomainId,
        string $slug,
        string $companyName,
        string $loginId,
        string $nickname,
        string $password
    ): array {
        $slug = strtolower(trim($slug));
        $loginId = trim($loginId);
        if (preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/', $slug) !== 1) {
            throw new ApiException('COMPANY_SLUG_INVALID', '회사 식별자 형식이 올바르지 않습니다.', 422);
        }
        if ($companyName === '' || mb_strlen($companyName) > 190) {
            throw new ApiException('COMPANY_NAME_INVALID', '회사명을 확인해 주세요.', 422);
        }
        if ($loginId === '' || mb_strlen($loginId) > 190) {
            throw new ApiException('USER_ID_INVALID', '사용자 ID를 확인해 주세요.', 422);
        }
        if (strlen($password) < 10 || strlen($password) > 1024) {
            throw new ApiException('PASSWORD_POLICY_FAILED', '비밀번호는 10자 이상이어야 합니다.', 422);
        }

        $companyId = Uuid::v4();
        $userId = Uuid::v4();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($passwordHash)) {
            throw new \RuntimeException('Password hashing failed');
        }

        try {
            $this->repository->provision(
                $companyId,
                $frameworkDomainId,
                $slug,
                trim($companyName),
                $userId,
                $loginId,
                trim($nickname),
                $passwordHash
            );
        } catch (\Throwable $exception) {
            throw new ApiException(
                'COMPANY_PROVISION_CONFLICT',
                '이미 등록된 회사 또는 사용자 정보입니다.',
                409,
                [],
            );
        }

        return ['company_id' => $companyId, 'user_id' => $userId, 'role' => 'OWNER'];
    }
}
