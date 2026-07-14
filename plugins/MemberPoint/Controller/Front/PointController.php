<?php
namespace Mublo\Plugin\MemberPoint\Controller\Front;

use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Context\Context;
use Mublo\Contract\Balance\BalanceGatewayInterface;

class PointController
{
    public function __construct(private BalanceGatewayInterface $balanceManager) {}

    public function my(array $params, Context $context): ViewResponse|RedirectResponse
    {
        $member = $context->getMember();
        if (!$member) {
            return RedirectResponse::to('/auth/login');
        }

        $memberId = $member->getMemberId();
        $domainId = $context->getDomainId() ?? 1;
        $request  = $context->getRequest();
        $page     = max(1, (int) ($request->get('page') ?? 1));
        $perPage  = 20;

        $totalPoint = $this->balanceManager->getBalance($memberId, $domainId);
        // 공개 계약 history — domainId 필수, 항목은 배열(엔티티 미노출)
        $history    = $this->balanceManager->history($memberId, $domainId, [], $page, $perPage);

        $points = array_map(fn(array $log) => [
            'created_at' => date('Y-m-d H:i', strtotime((string) ($log['created_at'] ?? ''))),
            'content'    => $log['message'] ?? '',
            'point'      => (int) ($log['amount'] ?? 0),
            'balance'    => (int) ($log['balance_after'] ?? 0),
        ], $history['items']);

        return ViewResponse::absoluteView(
            MUBLO_PLUGIN_PATH . '/MemberPoint/views/Front/My'
        )->withData([
            'pageTitle'  => '내 포인트',
            'member'     => $member,
            'totalPoint' => $totalPoint,
            'points'     => $points,
            'pagination' => $history['pagination'],
        ]);
    }
}
