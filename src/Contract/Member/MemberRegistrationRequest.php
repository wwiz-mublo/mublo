<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

/** 신뢰 확장이 회원 계정을 만들 때 사용하는 명시적 입력 모델. */
final readonly class MemberRegistrationRequest
{
    public function __construct(
        public int $domainId,
        public string $userId,
        public string $passwordHash,
        public string $nickname,
        public int $levelValue = 1,
        public ?int $originDomainId = null,
        public ?string $domainGroup = null,
        /**
         * 회원이 동의한 약관 번호.
         *
         * 스냅샷이 아니라 번호만 받는다 — 버전과 내용 해시는 코어가 직접 찾아 기록한다.
         * 확장이 만든 증빙을 그대로 저장하면 필수 약관을 건너뛰거나 내용을 바꿔 넣을 수 있다.
         *
         * @var int[]
         */
        public array $agreedPolicyIds = [],
        /** 동의 기록에 남길 접속 정보 — 코어 가입 폼과 같은 증빙을 남기기 위해서다. */
        public ?string $ipAddress = null,
        public ?string $userAgent = null
    ) {
    }
}
