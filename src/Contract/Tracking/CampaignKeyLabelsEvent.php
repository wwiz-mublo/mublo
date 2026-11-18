<?php
declare(strict_types=1);

namespace Mublo\Contract\Tracking;

use Mublo\Core\Event\AbstractEvent;

/**
 * 캠페인키 → 표시 이름 조회 (관리자 목록의 "유입" 칸용)
 *
 * 패키지는 방문자 통계 테이블을 직접 읽지 않고 이 이벤트로 묻는다.
 * 플러그인이 꺼져 있으면 라벨이 비어 원래 키만 보인다.
 */
class CampaignKeyLabelsEvent extends AbstractEvent
{
    /** @var array<int, array<string, string>> [domainId => [campaignKey => label]] */
    private array $labels = [];

    /**
     * @param array<int, string[]> $keysByDomain [domainId => [campaignKey, ...]]
     */
    public function __construct(public readonly array $keysByDomain) {}

    public function setLabel(int $domainId, string $campaignKey, string $label): void
    {
        $this->labels[$domainId][$campaignKey] = $label;
    }

    public function getLabel(int $domainId, string $campaignKey): string
    {
        return $this->labels[$domainId][$campaignKey] ?? '';
    }

    /** @return array<int, array<string, string>> */
    public function getLabels(): array
    {
        return $this->labels;
    }
}
