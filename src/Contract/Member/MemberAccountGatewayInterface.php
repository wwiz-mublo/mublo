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

    /**
     * 이 회원이 비밀번호로 로그인할 수 있는지.
     *
     * 확장이 마지막 로그인 수단을 끊으려는지 판단할 때 쓴다. 비밀번호 없이 외부 인증에만
     * 의지하던 회원의 연결을 끊으면 그 회원은 계정에 영영 들어올 수 없다.
     */
    public function hasLocalPassword(int $memberId): bool;

    public function verifyCredentials(int $domainId, string $userId, string $password): ?MemberProfile;

    public function validateCustomFields(int $domainId, array $values): Result;

    public function saveCustomFields(int $memberId, int $domainId, array $values): void;

    public function customFieldDefinitions(int $domainId): array;

    /**
     * 현재 비밀번호로 본인을 확인하고 탈퇴시킨다 — 세션이 없는 클라이언트(모바일 앱 API 등)용.
     *
     * 웹 마이페이지 탈퇴와 같은 처리를 거친다: 도메인 운영자 차단, MemberWithdrawingEvent
     * (구독자가 보류시킬 수 있다), 개인정보 정리, MemberWithdrawnEvent. 비밀번호가 맞지 않거나
     * 비밀번호가 없는 회원(SNS 전용)이면 실패를 돌려준다. 호출자는 시도 횟수를 제한해야 한다.
     */
    public function withdrawWithPassword(int $memberId, string $password, string $reason = ''): Result;

    /**
     * 확장이 회원 등급을 바꾼다 — 현재 등급이 $onlyFrom 안에 있을 때만.
     *
     * 확장이 맡은 등급 사이에서만 옮기게 하려는 것이다: 관리자가 따로 준 등급은 $onlyFrom 밖이라
     * 덮어쓰지 않는다. 조건 확인과 변경은 한 UPDATE 로 해 동시에 바꾼 값을 되돌리지 않는다.
     * 관리 권한이 있는 등급(최고관리자·관리자·사이트 운영)은 주지도 빼앗지도 못한다 — 그런 등급이
     * 대상이나 $onlyFrom 에 있으면 InvalidArgumentException. 없는 등급도 마찬가지다.
     *
     * 바뀌면 커밋 뒤 MemberUpdatedEvent(source=api, changes=['level_value'])를 발행한다.
     * 로그인 중인 웹 세션의 등급은 다시 로그인할 때 바뀐다 (관리자 수정과 같다).
     *
     * @param int[] $onlyFrom 바꿔도 되는 현재 등급 값
     * @return bool 바꿨으면 true, 이미 그 등급이거나 현재 등급이 $onlyFrom 밖·회원 없음이면 false
     */
    public function changeLevel(int $memberId, int $toLevel, array $onlyFrom): bool;
}
