<?php
declare(strict_types=1);
namespace Mublo\Plugin\SnsLogin\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Plugin\SnsLogin\Entity\SnsAccount;
use Mublo\Plugin\SnsLogin\Service\SnsConnectionManager;
use Mublo\Service\Member\Event\MemberDeletedEvent;
use Mublo\Service\Member\Event\MemberDeletingEvent;
use Mublo\Service\Member\Event\MemberWithdrawnEvent;

/**
 * 코어 회원 탈퇴·삭제 확장점에서 외부 SNS 연결과 플러그인 소유 데이터를 정리한다.
 *
 * 제공자 폐기(revoke)는 되돌릴 수 없는 외부 호출이므로 코어가 로컬 상태를 확정한
 * 뒤에만 실행한다. 탈퇴 전에 폐기하면 이후 트랜잭션이 실패했을 때 회원은 살아 있는데
 * SNS 로그인만 끊긴 상태가 되고, 되돌릴 방법이 없다.
 *
 * 같은 이유로 이 구독자는 탈퇴·삭제를 차단하지 않는다. 제공자 장애나 설정 누락으로
 * 회원이 탈퇴 자체를 못 하게 되는 편이 훨씬 나쁘다. 폐기 실패는 행 표시와 로그로
 * 남겨 관리자가 재시도한다.
 */
class MemberLifecycleSubscriber implements EventSubscriberInterface
{
    /**
     * 하드 삭제 전에 확보한 연결 스냅샷 (member_id => 계정 목록)
     *
     * @var array<int, SnsAccount[]>
     */
    private array $pendingDeletions = [];

    public function __construct(
        private SnsConnectionManager $connections,
        private Logger $logger,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            // 소프트 삭제라 로컬 행이 남는다 — 커밋 후 조회해 폐기하고 정리한다.
            MemberWithdrawnEvent::class => 'onMemberWithdrawn',
            // 하드 삭제는 FK CASCADE 로 로컬 행이 함께 사라진다. 폐기에 쓸 토큰을
            // 삭제 전에 확보해 두고(차단하지 않음), 삭제가 끝난 뒤 폐기한다.
            MemberDeletingEvent::class => ['onMemberDeleting', -1000],
            MemberDeletedEvent::class => 'onMemberDeleted',
        ];
    }

    public function onMemberWithdrawn(MemberWithdrawnEvent $event): void
    {
        try {
            $summary = $this->connections->revokeAndCleanupForMember($event->getMemberId());
        } catch (\Throwable $e) {
            // 탈퇴는 이미 커밋되었으므로 되돌리지 않고 운영 로그로 후속 조치를 남긴다.
            $this->logger->exception($e, context: [
                'member_id' => $event->getMemberId(),
                'action' => 'revoke_and_cleanup_connections',
            ]);
            return;
        }

        if ($summary['revoked'] > 0 || $summary['failed'] > 0) {
            $this->logger->info('탈퇴 회원 SNS 연결 정리', [
                'member_id' => $event->getMemberId(),
                'revoked' => $summary['revoked'],
                'failed' => $summary['failed'],
            ]);
        }
    }

    /** 삭제 진행 — 폐기에 쓸 토큰만 확보하고, 삭제를 막지 않는다. */
    public function onMemberDeleting(MemberDeletingEvent $event): void
    {
        if ($event->isBlocked()) {
            return;
        }

        $accounts = $this->connections->captureAccounts($event->getMemberId());
        if ($accounts !== []) {
            $this->pendingDeletions[$event->getMemberId()] = $accounts;
        }
    }

    public function onMemberDeleted(MemberDeletedEvent $event): void
    {
        $memberId = $event->getMemberId();
        $accounts = $this->pendingDeletions[$memberId] ?? [];
        unset($this->pendingDeletions[$memberId]);

        if ($accounts === []) {
            return;
        }

        try {
            $summary = $this->connections->revokeDetachedAccounts($accounts);
        } catch (\Throwable $e) {
            $this->logger->exception($e, context: [
                'member_id' => $memberId,
                'action' => 'revoke_detached_connections',
            ]);
            return;
        }

        $this->logger->info('삭제 회원 SNS 연결 폐기', [
            'member_id' => $memberId,
            'revoked' => $summary['revoked'],
            'failed' => $summary['failed'],
        ]);
    }
}
