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
     * persistRelated는 회원 저장 후 같은 트랜잭션에서 실행한다. 실패하면 DB 저장 전체가
     * 롤백된다(트랜잭션 밖의 디스크 파일은 제외). 호출자는 외부 트랜잭션으로 감싸지 않아야 한다.
     *
     * 실패는 반환값이 아니라 예외로 전달된다 — 저장 실패는 DatabaseException,
     * 외부 트랜잭션 안에서의 호출은 LogicException 이다.
     *
     * @param null|callable(int): void $persistRelated 확장 소유 데이터 저장
     * @return int 생성된 회원 ID
     */
    public function create(MemberRegistrationRequest $request, ?callable $persistRelated = null): int;

    public function verifyCredentials(int $domainId, string $userId, string $password): ?MemberProfile;

    public function validateCustomFields(int $domainId, array $values): Result;

    public function saveCustomFields(int $memberId, int $domainId, array $values): void;

    public function customFieldDefinitions(int $domainId): array;
}
