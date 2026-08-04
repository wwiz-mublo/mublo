<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

use Mublo\Core\Result\Result;

interface MemberAccountGatewayInterface
{
    public function nicknameExists(
        int $domainId,
        string $nickname,
        bool $includeOriginDomain = false
    ): bool;

    public function create(MemberRegistrationRequest $request): ?int;

    public function verifyCredentials(int $domainId, string $userId, string $password): ?MemberProfile;

    public function validateCustomFields(int $domainId, array $values): Result;

    public function saveCustomFields(int $memberId, int $domainId, array $values): void;

    public function customFieldDefinitions(int $domainId): array;
}
