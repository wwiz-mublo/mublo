<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Contract\Member\MemberLevelCatalogInterface;
use Mublo\Contract\Member\MemberQueryInterface;

/**
 * 회원 → 등급 ID 해석
 *
 * 등급별 할인·적립 설정(JSON)은 **levelId** 를 키로 저장되는데, 회원이 들고 있는
 * 값은 levelValue 다. 둘을 잇는 조회를 한 곳에 모으고 요청 단위로 메모이즈한다.
 * (레벨 설정을 levelId 로 읽는 방식은 OrderPointService 의 포인트 한도 해석과 같다.)
 *
 * 회원 조회·등급 카탈로그가 없는 환경(단위 테스트 등)에서는 조용히 null 을 돌려주고,
 * 호출 쪽은 "등급 할인 없음"으로 처리한다.
 */
class MemberLevelResolver
{
    private ?MemberQueryInterface $memberQuery;
    private ?MemberLevelCatalogInterface $levelCatalog;

    /** @var array<int,int|null> memberId => levelId */
    private array $byMember = [];

    /** @var array<int,int|null> levelValue => levelId */
    private array $byValue = [];

    public function __construct(
        ?MemberQueryInterface $memberQuery = null,
        ?MemberLevelCatalogInterface $levelCatalog = null
    ) {
        $this->memberQuery = $memberQuery;
        $this->levelCatalog = $levelCatalog;
    }

    /**
     * 회원 ID로 등급 ID 조회 (비회원이거나 알 수 없으면 null)
     */
    public function levelIdFor(int $memberId): ?int
    {
        if ($memberId <= 0) {
            return null;
        }

        if (array_key_exists($memberId, $this->byMember)) {
            return $this->byMember[$memberId];
        }

        $profile = $this->memberQuery?->findProfile($memberId);

        return $this->byMember[$memberId] = $profile === null
            ? null
            : $this->levelIdForValue($profile->levelValue);
    }

    /**
     * 등급값으로 등급 ID 조회 (로그인 사용자의 levelValue 를 이미 들고 있을 때)
     */
    public function levelIdForValue(int $levelValue): ?int
    {
        if (array_key_exists($levelValue, $this->byValue)) {
            return $this->byValue[$levelValue];
        }

        return $this->byValue[$levelValue] = $this->levelCatalog?->findByValue($levelValue)?->levelId;
    }
}
