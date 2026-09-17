<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Packages\Shop\Enum\ClaimStatus;
use Mublo\Packages\Shop\Repository\WithdrawalBlockerRepository;
use Mublo\Packages\Shop\Service\OrderStateResolver;
use Mublo\Service\Member\Event\MemberWithdrawingEvent;

/**
 * 이행이 끝나지 않은 거래가 있으면 탈퇴를 보류시킨다.
 *
 * 경고만 하고 통과시키자는 선택지도 있었지만, 잃는 것이 본인 것뿐일 때만 그렇게 할 수
 * 있다. 진행 중인 거래는 상대가 있는 관계다 — 탈퇴는 포인트 잔액을 0으로 정리하고
 * 회원 쪽 창구를 닫으므로, 이후 취소·반품이 나오면 환불할 곳이 사라진다. 사용자가
 * 동의를 눌러도 환불 의무가 없어지지는 않는다.
 *
 * 대신 막히는 범위는 좁게 둔다. 배송이 끝났고 클레임도 없으면 통과한다.
 */
class WithdrawalGuardSubscriber implements EventSubscriberInterface
{
    /**
     * 결제 전 주문을 결제 진행 중으로 보는 시간(초).
     *
     * 결제창이 떠 있는 동안 탈퇴하면 결제는 들어오는데 회원이 없다. 그 창만 막는다.
     */
    private const PENDING_GRACE_SECONDS = 3600;

    /**
     * 이행 판정에서 빼는 상태.
     *
     * 나머지는 설정에서 읽는다 — 주문 상태는 도메인마다 바뀌므로 목록을 코드에 적으면
     * 사이트를 조금만 바꿔도 판정이 어긋난다. terminal 상태(구매확정·주문취소·반품완료)는
     * 더 이행할 것이 없고, 결제 전 상태는 아래에서 따로 다룬다.
     */
    private const PRE_PAYMENT_STATES = ['received'];

    public function __construct(
        private WithdrawalBlockerRepository $blockers,
        private OrderStateResolver $states,
        private Logger $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            MemberWithdrawingEvent::class => 'onMemberWithdrawing',
        ];
    }

    public function onMemberWithdrawing(MemberWithdrawingEvent $event): void
    {
        // 이미 다른 구독자가 막았다면 그 사유가 더 구체적일 수 있다 — 덮어쓰지 않는다.
        if ($event->isBlocked()) {
            return;
        }

        try {
            $blocking = $this->collect($event->getDomainId(), $event->getMemberId());
        } catch (\Throwable $e) {
            // 조회에 실패했다고 탈퇴를 막으면, 쇼핑을 쓰지 않는 사이트까지 탈퇴가 멈춘다.
            // 이 구독자는 쇼핑 데이터를 지키려는 것이지 탈퇴를 관문으로 삼는 게 아니다.
            $this->logger->exception($e, context: [
                'member_id' => $event->getMemberId(),
                'action' => 'check_withdrawal_blockers',
            ]);
            return;
        }

        if ($blocking === []) {
            return;
        }

        $event->setBlocked(true, $this->describe($blocking));
    }

    /**
     * 주문과 클레임을 함께 본다 — 주문은 배송완료인데 반품 심사가 열려 있을 수 있다.
     *
     * @return list<array{order_no: string, label: string}>
     */
    private function collect(int $domainId, int $memberId): array
    {
        $unfinished = [];
        $pending = [];

        foreach ($this->states->getAllStates($domainId) as $state) {
            $id = (string) ($state['id'] ?? '');
            if ($id === '' || ($state['terminal'] ?? false)) {
                continue;
            }

            if (in_array($id, self::PRE_PAYMENT_STATES, true)) {
                $pending[] = $id;
                continue;
            }

            $unfinished[] = $id;
        }

        $blocking = [];

        foreach ($this->blockers->findUnfinishedOrders($memberId, $unfinished, $pending, self::PENDING_GRACE_SECONDS) as $row) {
            $blocking[$row['order_no']] = [
                'order_no' => $row['order_no'],
                'label' => $this->states->getLabel($domainId, $row['status']) ?: $row['status'],
            ];
        }

        foreach ($this->blockers->findOpenClaims($memberId) as $row) {
            // 같은 주문이 이미 잡혔어도 클레임이 더 구체적인 사유다.
            $blocking[$row['order_no']] = [
                'order_no' => $row['order_no'],
                'label' => ClaimStatus::tryFrom($row['status'])?->label() ?? $row['status'],
            ];
        }

        return array_values($blocking);
    }

    /**
     * 어느 주문 때문인지 알려준다.
     *
     * "탈퇴할 수 없습니다" 만으로는 무엇을 해야 하는지 알 수 없어 고객센터로 간다.
     * 주문번호와 상태를 함께 주면 스스로 확인하고 기다릴 수 있다.
     *
     * @param list<array{order_no: string, label: string}> $blocking
     */
    private function describe(array $blocking): string
    {
        $shown = array_slice($blocking, 0, 5);
        $lines = [];

        foreach ($shown as $item) {
            $lines[] = $item['order_no'] . ' (' . $item['label'] . ')';
        }

        $rest = count($blocking) - count($shown);
        if ($rest > 0) {
            $lines[] = '외 ' . $rest . '건';
        }

        // 첫 줄은 상황과 해야 할 일, 그 아래는 주문 한 건씩. 한 줄로 이어 붙이면 몇 건인지,
        // 어디서 번호가 끊기는지 읽기 어렵다.
        //
        // 글머리 기호는 붙이지 않는다. 알림창은 가운데 정렬이라 기호를 붙이면 들쭉날쭉하고,
        // 목록으로 보여야 하는 자리(탈퇴 화면)는 이 줄들을 항목으로 쪼개 그린다.
        return '처리가 끝나지 않은 주문이 있어 지금은 탈퇴할 수 없습니다.'
            . ' 배송과 교환·반품이 모두 끝난 뒤 다시 시도해주세요.' . "\n"
            . implode("\n", $lines);
    }
}
