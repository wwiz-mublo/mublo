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

    /**
     * create() 로 만든 회원의 가입이 확정됐음을 코어에 알린다.
     *
     * create() 는 행만 만들고 가입 이벤트를 발행하지 않는다. 호출자가 자기 트랜잭션을
     * 커밋한 뒤 이 메서드를 호출해야 가입 포인트·쿠폰 같은 코어 확장점이 동작한다.
     * 이벤트 처리 중 예외는 가입을 되돌리지 못하므로 여기서 삼키고 로그로 남긴다.
     */
    public function notifyRegistered(int $memberId): void;

    public function verifyCredentials(int $domainId, string $userId, string $password): ?MemberProfile;

    public function validateCustomFields(int $domainId, array $values): Result;

    public function saveCustomFields(int $memberId, int $domainId, array $values): void;

    public function customFieldDefinitions(int $domainId): array;
}
