<?php
namespace Mublo\Packages\Board\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Packages\Board\Event\ReactionAddedEvent;
use Mublo\Packages\Board\Event\ReactionRemovedEvent;
use Mublo\Packages\Board\Service\BoardPointService;

/**
 * ReactionPointSubscriber
 *
 * 반응(좋아요 등) 이벤트 발생 시 포인트 적립/회수 처리
 */
class ReactionPointSubscriber implements EventSubscriberInterface
{
    public function __construct(private BoardPointService $pointService) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ReactionAddedEvent::class   => 'onReactionAdded',
            ReactionRemovedEvent::class => 'onReactionRemoved',
        ];
    }

    public function onReactionAdded(ReactionAddedEvent $event): void
    {
        $targetAuthorId = $event->getTargetAuthorId();
        if ($targetAuthorId === null) return;
        if ($targetAuthorId === $event->getMemberId()) return; // 자추 방지

        $reaction = $event->getReaction();
        $domainId = $event->getTargetDomainId();

        // 멱등키를 (대상, 반응자) 기준으로 고정한다. reactionId(인스턴스)로 키를 잡으면
        // 반응 취소 후 재부여 시 새 reactionId → 새 키 → 재적립되어, revoke 액션이 꺼져 있으면
        // add→remove→add 반복으로 무제한 파밍이 가능하다. (대상,반응자) 키는 한 반응자가 한 대상에
        // 대해 최초 1회만 적립되게 하여(재부여는 동일 키 no-op) revoke 설정과 무관하게 파밍을 막는다.
        $reactorKey = "{$event->getTargetType()}_{$event->getTargetId()}_{$event->getMemberId()}";

        $this->pointService->award($domainId, $targetAuthorId, 'reaction_received', [
            'board_id'        => $reaction->getBoardId(),
            'reaction_type'   => $reaction->getReactionType(),
            'reference_type'  => $event->getTargetType(),
            'reference_id'    => (string) $event->getTargetId(),
            'idempotency_key' => "bp_reaction_{$domainId}_{$reactorKey}",
        ]);
    }

    public function onReactionRemoved(ReactionRemovedEvent $event): void
    {
        $targetAuthorId = $event->getTargetAuthorId();
        if ($targetAuthorId === null) return;
        if ($targetAuthorId === $event->getMemberId()) return;

        $domainId = $event->getDomainId();
        if ($domainId === null) return;

        // award 와 동일한 (대상, 반응자) 키로 짝을 맞춘다. add(+1)→remove(-1) 최초 한 쌍만
        // 실효되고, 이후 toggle 은 양쪽 모두 동일 키 no-op 이라 순잔액이 흔들리지 않는다.
        $reactorKey = "{$event->getTargetType()}_{$event->getTargetId()}_{$event->getMemberId()}";

        $this->pointService->revoke($domainId, $targetAuthorId, 'reaction_received', [
            'board_id'        => $event->getBoardId(),
            'reaction_type'   => $event->getReactionType(),
            'reference_type'  => $event->getTargetType(),
            'reference_id'    => (string) $event->getTargetId(),
            'idempotency_key' => "bp_reaction_revoke_{$domainId}_{$reactorKey}",
        ]);
    }
}
