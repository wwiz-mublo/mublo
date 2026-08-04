<?php
declare(strict_types=1);

namespace Mublo\Plugin\VisitorStats\Subscriber;

use Mublo\Contract\Tracking\ConversionRecordedEvent;
use Mublo\Contract\Tracking\ConversionSourceTypes;
use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Plugin\VisitorStats\Repository\ConversionEventRepository;

/**
 * ConversionRecorderSubscriber
 *
 * ConversionRecordedEvent 를 받아 전환을 기록한다.
 *
 * 발행자는 각 소스 서비스(렌탈 주문·상담 신청 등)이고, VisitorStats 는 그들이
 * 누구인지 알지 못한다 — 계약이 실어 온 sourceType 문자열만 보고 쌓는다.
 * VisitorStats 가 설치되지 않은 환경에서는 구독자가 없어 이벤트가 조용히 무시된다.
 *
 * 수집 실패는 삼킨다. 통계는 부가 기능이고, 주문·상담 트랜잭션을 통계 때문에
 * 깨뜨리면 안 된다 — VisitorTrackingSubscriber 와 같은 방어 태도다.
 */
class ConversionRecorderSubscriber implements EventSubscriberInterface
{
    public function __construct(private ConversionEventRepository $repository) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ConversionRecordedEvent::class => 'onConversionRecorded',
        ];
    }

    public function onConversionRecorded(ConversionRecordedEvent $event): void
    {
        try {
            $sourceType = trim($event->sourceType);
            $sourceId = trim($event->sourceId);

            // 소스를 특정할 수 없으면 멱등키가 성립하지 않는다. 익명 행을 쌓느니 버린다.
            if ($sourceType === '' || $sourceId === '' || $event->domainId <= 0) {
                return;
            }

            $this->repository->record([
                'domain_id'    => $event->domainId,
                'source_type'  => mb_substr($sourceType, 0, 50),
                'source_id'    => mb_substr($sourceId, 0, 100),
                // 라벨을 안 실어 보냈으면 코어 상수의 한글 라벨로 채운다.
                // 미등록 타입이면 label() 이 타입 문자열을 그대로 돌려준다.
                'source_label' => mb_substr(
                    $event->sourceLabel ?? ConversionSourceTypes::label($sourceType),
                    0,
                    100
                ),
                // 캠페인 키는 유입 통계와 같은 축이다. 길이도 같게 맞춰 조인이 어긋나지 않게 한다.
                'campaign_key' => $this->nullableText($event->campaignKey, 100),
                'status'       => mb_substr($event->status !== '' ? $event->status : 'success', 0, 20),
                'member_id'    => $event->memberId !== null && $event->memberId > 0 ? $event->memberId : null,
                'value_amount' => $event->valueAmount,
                'currency'     => mb_substr($event->currency !== '' ? $event->currency : 'KRW', 0, 10),
                'occurred_at'  => $this->resolveOccurredAt($event->occurredAt),
            ]);
        } catch (\Throwable $e) {
            error_log('[VisitorStats] conversion record failed: ' . $e->getMessage());
        }
    }

    private function nullableText(?string $value, int $limit): ?string
    {
        $value = $value !== null ? trim($value) : '';

        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    /**
     * 발행자가 시각을 안 실어 보냈거나 형식이 깨졌으면 수신 시각으로 갈음한다.
     * 통계에서 빠지는 것보다 근사한 시각으로라도 남는 편이 낫다.
     */
    private function resolveOccurredAt(?string $occurredAt): string
    {
        $occurredAt = $occurredAt !== null ? trim($occurredAt) : '';
        if ($occurredAt !== '') {
            $parsed = date_create($occurredAt);
            if ($parsed !== false) {
                return $parsed->format('Y-m-d H:i:s');
            }
        }

        return date('Y-m-d H:i:s');
    }
}
