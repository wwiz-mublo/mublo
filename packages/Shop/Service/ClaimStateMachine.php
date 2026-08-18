<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Packages\Shop\Enum\ClaimStatus;

/**
 * 클레임(교환·반품) 상태 전이 규칙.
 *
 * 두 유형은 회수·검수까지 같은 길을 걷고 검수 이후에만 갈린다.
 * 교환은 재출고대기 → 재출고 → 완료, 반품은 환불대기 → 완료.
 * 검수 거절은 양쪽 다 고객 반송 → 종결이다.
 */
final class ClaimStateMachine
{
    /** @var array<string, string[]> 유형과 무관한 전이 */
    private const COMMON = [
        'REQUESTED' => ['ACCEPTED', 'REFUSED', 'CANCELLED'],
        'ACCEPTED' => ['COLLECTING', 'CANCELLED'],
        'COLLECTING' => ['COLLECTED'],
        'COLLECTED' => ['INSPECTING'],
        'REJECTED' => ['RETURNING'],
        'RETURNING' => ['CLOSED'],
        'COMPLETED' => [],
        'REFUSED' => [],
        'CANCELLED' => [],
        'CLOSED' => [],
    ];

    /** @var array<string, array<string, string[]>> 검수 이후 갈리는 전이 */
    private const BY_TYPE = [
        'EXCHANGE' => [
            'INSPECTING' => ['READY_TO_SHIP', 'REJECTED'],
            'READY_TO_SHIP' => ['RESHIPPING'],
            'RESHIPPING' => ['COMPLETED'],
        ],
        'RETURN' => [
            'INSPECTING' => ['READY_TO_REFUND', 'REJECTED'],
            'READY_TO_REFUND' => ['COMPLETED'],
        ],
    ];

    public function canTransition(string $from, string $to, ?string $returnType = null): bool
    {
        return in_array($to, $this->transitions($returnType)[$from] ?? [], true)
            && ClaimStatus::tryFrom($to) !== null;
    }

    /** @return ClaimStatus[] */
    public function next(string $from, ?string $returnType = null): array
    {
        return array_values(array_filter(array_map(
            static fn(string $status): ?ClaimStatus => ClaimStatus::tryFrom($status),
            $this->transitions($returnType)[$from] ?? []
        )));
    }

    /**
     * 유형별 전이표. 유형을 주지 않으면 두 갈래를 합쳐 돌려준다 —
     * 유형을 모르는 자리(설정 화면 등)에서 전체 그래프를 훑기 위한 것이지,
     * 실제 전이 판정은 유형을 주고 해야 반품이 재출고로 새지 않는다.
     *
     * @return array<string, string[]>
     */
    private function transitions(?string $returnType): array
    {
        $branches = $returnType !== null
            ? [self::BY_TYPE[$returnType] ?? []]
            : array_values(self::BY_TYPE);

        $map = self::COMMON;
        foreach ($branches as $branch) {
            foreach ($branch as $from => $targets) {
                $map[$from] = array_values(array_unique(array_merge($map[$from] ?? [], $targets)));
            }
        }
        return $map;
    }
}
