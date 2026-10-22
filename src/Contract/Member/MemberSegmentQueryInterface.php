<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

/**
 * 조건으로 회원을 고르는 조회 계약 (쿠폰 일괄 지급 등 대상 선정용)
 *
 * 패키지가 members 테이블을 직접 읽지 않고, 정상(active) 회원 ID 만 받아 간다.
 */
interface MemberSegmentQueryInterface
{
    /**
     * 이 사이트의 정상 회원 ID (오래된 가입순)
     *
     * @param list<int>   $levelValues 등급 값 — 비우면 모든 일반 등급 (관리자·최고관리자 등급은 늘 제외)
     * @param string|null $joinedFrom  가입일 시작 (Y-m-d, 포함)
     * @param string|null $joinedTo    가입일 끝 (Y-m-d, 포함)
     * @param int         $limit       최대 건수 (1~50000)
     * @return list<int>
     */
    public function findActiveMemberIds(
        int $domainId,
        array $levelValues = [],
        ?string $joinedFrom = null,
        ?string $joinedTo = null,
        int $limit = 10000
    ): array;
}
