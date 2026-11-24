<?php
declare(strict_types=1);

namespace Mublo\Plugin\VisitorStats\Subscriber;

use Mublo\Contract\Tracking\VisitorRefererLookupEvent;
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Plugin\VisitorStats\Repository\VisitorLogRepository;

/**
 * 방문 유입경로 제공 — 세션·IP 로 그날 방문 로그를 찾아 레퍼러를 돌려준다.
 *
 * 주문·상담 목록에서 "이 고객이 어디서 들어왔나" 를 보여 줄 때 쓴다. 방문 로그는
 * 보관 기간(기본 30일)이 지나면 지워지므로 오래된 건은 결과가 없다.
 * 표시용이라 조회 실패는 조용히 넘긴다 (유입경로가 안 보일 뿐).
 */
class VisitorRefererLookupSubscriber implements EventSubscriberInterface
{
    /** 한 번에 조회하는 최대 건수 (목록 한 페이지 분량) — 넘는 건 답하지 않는다 */
    public const MAX_LOOKUPS = 200;

    public function __construct(
        private VisitorLogRepository $repository,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            VisitorRefererLookupEvent::class => 'onLookup',
        ];
    }

    public function onLookup(VisitorRefererLookupEvent $event): void
    {
        $count = 0;
        foreach ($event->lookups as $ref => $lookup) {
            if (++$count > self::MAX_LOOKUPS) {
                break;
            }
            $domainId = (int) ($lookup['domain_id'] ?? 0);
            $at = (string) ($lookup['at'] ?? '');
            if ($domainId <= 0 || $at === '') {
                continue;
            }

            try {
                $row = $this->repository->findVisitAt(
                    $domainId,
                    isset($lookup['session_id']) ? (string) $lookup['session_id'] : null,
                    isset($lookup['ip']) ? (string) $lookup['ip'] : null,
                    $at
                );
            } catch (\Throwable $e) {
                // DB 문제면 나머지도 실패한다 — 한 번만 남기고 그만둔다.
                error_log('[VisitorStats] referer lookup failed: ' . $e->getMessage());
                return;
            }

            if ($row === null) {
                continue;
            }

            $type = (string) ($row['referer_type'] ?? '');
            $event->setResult(
                $ref,
                $type !== '' ? $type : 'direct',
                (string) ($row['referer_domain'] ?? ''),
                (string) ($row['referer_url'] ?? ''),
                (string) ($row['landing_url'] ?? '')
            );
        }
    }
}
