<?php
declare(strict_types=1);

namespace Mublo\Plugin\VisitorStats\Subscriber;

use Mublo\Contract\Tracking\CampaignKeyLabelsEvent;
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Plugin\VisitorStats\Repository\VisitorCampaignKeyRepository;

/**
 * 캠페인 키 표시 이름 제공 — 등록된 키의 그룹명·메모를 라벨로 돌려준다.
 *
 * 주문·상담 목록의 "유입" 칸처럼 다른 확장이 캠페인 키를 사람이 읽을 이름으로 보여 줄 때 쓴다.
 * 표시용이라 조회 실패는 조용히 넘긴다 (키만 보인다).
 */
class CampaignKeyLabelSubscriber implements EventSubscriberInterface
{
    /** 라벨에 싣는 메모 최대 글자 수 */
    private const MEMO_LIMIT = 40;

    public function __construct(
        private VisitorCampaignKeyRepository $repository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            CampaignKeyLabelsEvent::class => 'onCampaignKeyLabels',
        ];
    }

    public function onCampaignKeyLabels(CampaignKeyLabelsEvent $event): void
    {
        foreach ($event->keysByDomain as $domainId => $keys) {
            $domainId = (int) $domainId;
            if ($domainId <= 0) {
                continue;
            }

            try {
                $rows = $this->repository->findByKeys($domainId, (array) $keys);
            } catch (\Throwable $e) {
                error_log('[VisitorStats] campaign key label lookup failed: ' . $e->getMessage());
                continue;
            }

            foreach ($rows as $row) {
                $label = self::buildLabel((string) ($row['group_name'] ?? ''), (string) ($row['memo'] ?? ''));
                if ($label !== '') {
                    $event->setLabel($domainId, (string) $row['campaign_key'], $label);
                }
            }
        }
    }

    /** "그룹명 · 메모" — 메모는 40자에서 자른다. 둘 다 비면 빈 문자열 */
    public static function buildLabel(string $groupName, string $memo): string
    {
        $memo = trim($memo);
        if (mb_strlen($memo) > self::MEMO_LIMIT) {
            $memo = mb_substr($memo, 0, self::MEMO_LIMIT) . '…';
        }

        return implode(' · ', array_filter(
            [trim($groupName), $memo],
            static fn(string $v): bool => $v !== ''
        ));
    }
}
