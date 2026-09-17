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

    /**
     * 코어가 회원 가입과 완료 이벤트 발행을 책임진다.
     *
     * persistRelated는 회원 저장 후 같은 트랜잭션에서 실행한다. 실패하면 가입 전체가
     * 롤백된다. 호출자는 외부 트랜잭션으로 감싸지 않아야 한다.
     * @param null|callable(int): void $persistRelated 확장 소유 데이터 저장
     */
    public function create(MemberRegistrationRequest $request, ?callable $persistRelated = null): ?int;

    public function verifyCredentials(int $domainId, string $userId, string $password): ?MemberProfile;

    public function validateCustomFields(int $domainId, array $values): Result;

    public function saveCustomFields(int $memberId, int $domainId, array $values): void;

    public function customFieldDefinitions(int $domainId): array;
}
