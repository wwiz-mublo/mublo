<?php
declare(strict_types=1);

namespace Mublo\Plugin\Innopay\Service;

use Mublo\Contract\Payment\PaymentConsumerInterface;
use Mublo\Contract\Payment\PreparedPayment;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Plugin\Innopay\InnopayGateway;
use Mublo\Plugin\Innopay\Repository\InnopayAttemptRepository as Attempts;

/**
 * 결제창 복귀(returnUrl) 처리
 *
 * 이노페이는 인증만 끝낸 채 돌아오고, 승인은 가맹점 서버가 5분 안에 해야 한다.
 *
 *   RETURN → 사전 대조(MID·금액·주문 상태) → CAS(READY→APPROVING) → 승인 API
 *          → (결과 불명이면 거래 조회로 복구) → APPROVED → 소비 패키지 검증(verifyPayment)
 *          → COMPLETED
 *
 * 승인은 됐는데 주문 반영이 실패하면 "돈은 빠졌는데 주문이 없는" 상태가 된다.
 * 설정에 따라 결제를 자동 취소하고, 취소도 못 하면 NEEDS_ACTION 으로 남겨 관리자가 보게 한다.
 */
class InnopayReturnService
{
    /** 승인 응답이 이렇게 오면 실제 결과를 조회로 확인한다 (요청중 / 승인완료 / 승인중복) */
    private const RECOVERABLE_CODES = ['1004', '1005', '1651'];

    /** @var callable(int): InnopayGateway */
    private $gatewayFactory;

    public function __construct(
        private InnopayConfigService $configService,
        private Attempts $attempts,
        private InnopayLogger $logger,
        private ContractRegistry $registry,
        callable $gatewayFactory,
    ) {
        $this->gatewayFactory = $gatewayFactory;
    }

    /**
     * @param array<string, mixed> $params returnUrl 로 받은 값 (paymentToken, tid, mid, amt, taxFreeAmt, moid)
     */
    public function handle(int $domainId, array $params, string $ip = '', string $userAgent = ''): InnopayReturnOutcome
    {
        $moid = trim((string) ($params['moid'] ?? ''));
        $tid = trim((string) ($params['tid'] ?? ''));
        $token = trim((string) ($params['paymentToken'] ?? ''));
        $amt = (int) ($params['amt'] ?? 0);
        $taxFree = (int) ($params['taxFreeAmt'] ?? 0);
        $total = $amt + $taxFree;

        $this->logger->log($domainId, 'RETURN', [
            'moid' => $moid,
            'tid' => $tid,
            'amount' => $total,
            'request' => $params,
            'ip' => $ip,
            'user_agent' => $userAgent,
        ]);

        if ($moid === '') {
            return $this->stop($domainId, '', $tid, 'NO_MOID', '결제 정보를 확인할 수 없습니다.');
        }

        $attempt = $this->attempts->find($domainId, $moid);
        if ($attempt === null) {
            return $this->stop($domainId, $moid, $tid, 'NO_ATTEMPT', '결제 요청 기록을 찾을 수 없습니다. 주문 내역을 확인해주세요.');
        }

        $okUrl = (string) $attempt['success_url'];
        $failUrl = (string) $attempt['fail_url'];
        $status = (string) $attempt['status'];

        // ── 재진입(새로고침·뒤로가기) ─────────────────────────────────────────
        if ($status === Attempts::COMPLETED) {
            return $this->succeed($okUrl);
        }
        if ($status === Attempts::APPROVING) {
            return InnopayReturnOutcome::message(false, '결제를 처리하고 있습니다. 잠시 후 주문 내역에서 확인해주세요.');
        }
        if ($status === Attempts::NEEDS_ACTION) {
            return InnopayReturnOutcome::message(false, '결제 확인이 필요한 주문입니다. 고객센터로 문의해주세요.');
        }

        $config = $this->configService->getConfig($domainId);
        $consumer = $this->resolveConsumer((string) $attempt['consumer']);
        if ($consumer === null) {
            $this->attempts->update($domainId, $moid, ['status' => Attempts::FAILED, 'result_code' => 'NO_CONSUMER', 'result_msg' => '결제 소비자 없음']);
            return $this->fail($domainId, $moid, $tid, $failUrl, 'NO_CONSUMER', '결제를 처리할 수 없습니다. (결제 소비자 미확인)');
        }

        // 이전 요청이 승인까지 하고 끊겼다 — 승인을 다시 하지 않고 주문 반영만 이어간다
        if ($status === Attempts::APPROVED) {
            return $this->finalize($domainId, $moid, (string) $attempt['tid'], $consumer, $config, $okUrl, $failUrl);
        }

        // ── 인증 실패/취소로 돌아온 경우 ───────────────────────────────────────
        if ($token === '' || $tid === '') {
            $reason = trim((string) ($params['resultMsg'] ?? $params['message'] ?? '')) ?: '결제가 완료되지 않았습니다.';
            $code = (string) ($params['resultCode'] ?? $params['code'] ?? 'NOT_AUTHENTICATED');
            $this->attempts->transition($domainId, $moid, [Attempts::READY], Attempts::FAILED, ['result_code' => $code, 'result_msg' => $reason]);
            return $this->fail($domainId, $moid, $tid, $failUrl, $code, $reason, 'warning');
        }

        // ── 사전 대조 — 하나라도 어긋나면 승인하지 않는다 ─────────────────────────
        if ((string) ($params['mid'] ?? '') !== (string) $config['mid']) {
            return $this->fail($domainId, $moid, $tid, $failUrl, 'MID_MISMATCH', '가맹점 정보가 일치하지 않습니다.');
        }
        if ($total !== (int) $attempt['amount']) {
            return $this->fail($domainId, $moid, $tid, $failUrl, 'AMOUNT_MISMATCH',
                "결제 금액이 요청 금액과 다릅니다. (요청 {$attempt['amount']} / 인증 {$total})");
        }

        $prepared = $this->findPrepared($consumer, $domainId, $moid);
        if ($prepared !== null && $prepared->paid) {
            $this->attempts->update($domainId, $moid, ['status' => Attempts::COMPLETED]);
            return $this->succeed($okUrl);
        }
        if ($prepared !== null && $prepared->amount !== $total) {
            return $this->fail($domainId, $moid, $tid, $failUrl, 'AMOUNT_MISMATCH',
                "결제 금액이 주문 금액과 다릅니다. (주문 {$prepared->amount} / 인증 {$total})");
        }

        // ── 승인 — 같은 복귀가 두 번 와도 한 번만 승인한다 ─────────────────────────
        if (!$this->attempts->transition($domainId, $moid, [Attempts::READY], Attempts::APPROVING, ['tid' => $tid])) {
            $fresh = $this->attempts->find($domainId, $moid);
            if (($fresh['status'] ?? '') === Attempts::COMPLETED) {
                return $this->succeed($okUrl);
            }
            return $this->fail($domainId, $moid, $tid, $failUrl, 'STATE_CONFLICT',
                '결제 상태를 확인할 수 없습니다. 주문 내역을 확인해주세요. (' . ($fresh['status'] ?? '-') . ')');
        }

        $client = ($this->gatewayFactory)($domainId)->createClient();
        $result = $client->approve($token, $tid, $moid, $amt, $taxFree);
        $approved = $this->isApproved($result, $tid, $moid);
        $approvedData = $approved ? $result['data'] : [];

        $errorCode = (string) ($result['error']['code'] ?? '');
        if (!$approved && (!empty($result['_ambiguous']) || in_array($errorCode, self::RECOVERABLE_CODES, true))) {
            // 응답을 못 받았거나 "이미 승인/승인 중" — 실제로 승인됐는지 조회해서 판단한다
            $query = $client->findByTid($tid, $moid);
            $approved = $this->isApproved($query, $tid, $moid);
            $approvedData = $approved ? $query['data'] : [];
            $this->logger->log($domainId, 'APPROVE_RECOVER', [
                'moid' => $moid, 'tid' => $tid, 'amount' => $total,
                'level' => $approved ? 'warning' : 'error',
                'result_code' => $approved ? 'RECOVERED' : 'NOT_APPROVED',
                'result_msg' => $approved ? '승인 응답은 실패였으나 조회 결과 승인 완료' : '조회 결과 승인되지 않음',
            ]);
        }

        if (!$approved) {
            $message = (string) ($result['error']['message'] ?? '') ?: '결제 승인에 실패했습니다.';
            $code = $errorCode !== '' ? $errorCode : 'APPROVE_FAILED';
            $this->attempts->transition($domainId, $moid, [Attempts::APPROVING], Attempts::FAILED, ['result_code' => $code, 'result_msg' => $message]);
            return $this->fail($domainId, $moid, $tid, $failUrl, $code, $message);
        }

        // 가상계좌는 승인이 "계좌 발급"일 뿐이다 — 입금 확인 연동 전이라 주문에 반영하지 않는다.
        // (결제수단 선택창에서 구매자가 고를 수 있다. 발급된 계좌는 입금 없이 만료된다)
        if (InnopayGateway::isVirtualAccount((string) ($approvedData['payMethod'] ?? ''))) {
            $message = '가상계좌 결제는 지원하지 않습니다. 다른 결제수단으로 다시 결제해주세요.';
            $this->attempts->transition($domainId, $moid, [Attempts::APPROVING], Attempts::FAILED, ['result_code' => 'VBANK_UNSUPPORTED', 'result_msg' => $message]);
            return $this->fail($domainId, $moid, $tid, $failUrl, 'VBANK_UNSUPPORTED', $message, 'warning');
        }

        $this->attempts->transition($domainId, $moid, [Attempts::APPROVING], Attempts::APPROVED, ['result_code' => 'APPROVED', 'result_msg' => '승인 완료']);

        return $this->finalize($domainId, $moid, $tid, $consumer, $config, $okUrl, $failUrl);
    }

    /**
     * 이미 승인된 결제를 주문에 반영한다 — 결제결과 통보(Noti)가 복귀 과정이 끊긴 결제를 이어갈 때 쓴다.
     * 호출자는 거래조회로 승인을 확인하고 시도를 APPROVED 로 옮긴 상태여야 한다.
     */
    public function completeApproved(int $domainId, string $moid, string $tid): InnopayReturnOutcome
    {
        $attempt = $this->attempts->find($domainId, $moid);
        $consumer = $attempt !== null ? $this->resolveConsumer((string) $attempt['consumer']) : null;
        if ($attempt === null || $consumer === null) {
            return InnopayReturnOutcome::message(false, '결제 시도 또는 결제 소비자를 찾을 수 없습니다.');
        }

        return $this->finalize(
            $domainId, $moid, $tid, $consumer,
            $this->configService->getConfig($domainId),
            (string) $attempt['success_url'], (string) $attempt['fail_url']
        );
    }

    public function resolveConsumerFor(array $attempt): ?PaymentConsumerInterface
    {
        return $this->resolveConsumer((string) ($attempt['consumer'] ?? ''));
    }

    /**
     * 승인된 결제를 주문에 반영한다.
     */
    private function finalize(int $domainId, string $moid, string $tid, PaymentConsumerInterface $consumer, array $config, string $okUrl, string $failUrl): InnopayReturnOutcome
    {
        $ok = false;
        $message = '';
        try {
            $result = $consumer->verifyPayment($domainId, $moid, InnopayGateway::PG_KEY, $tid);
            $ok = $result->isSuccess();
            $message = $result->getMessage();
        } catch (\Throwable $e) {
            $message = '예외: ' . $e->getMessage();
        }

        $this->logger->log($domainId, 'CONSUMER_RESULT', [
            'moid' => $moid, 'tid' => $tid,
            'level' => $ok ? 'info' : 'error',
            'result_code' => $ok ? 'OK' : 'FAILED',
            'result_msg' => $message,
        ]);

        if ($ok) {
            $this->attempts->update($domainId, $moid, ['status' => Attempts::COMPLETED, 'result_code' => 'COMPLETED', 'result_msg' => '주문 반영 완료']);
            return $this->succeed($okUrl);
        }

        // 실패로 보였지만 다른 경로가 이미 결제 완료로 반영했을 수 있다
        $prepared = $this->findPrepared($consumer, $domainId, $moid);
        if ($prepared !== null && $prepared->paid) {
            $this->attempts->update($domainId, $moid, ['status' => Attempts::COMPLETED, 'result_code' => 'COMPLETED', 'result_msg' => '주문 반영 확인']);
            return $this->succeed($okUrl);
        }

        // 돈은 빠졌는데 주문이 없다 — 자동 취소를 시도한다
        if (!empty($config['auto_cancel']) && (string) $config['cancel_pwd'] !== '') {
            $cancel = ($this->gatewayFactory)($domainId)->cancel($tid, 0, '주문 반영 실패 자동취소');
            $this->logger->log($domainId, 'AUTO_CANCEL', [
                'moid' => $moid, 'tid' => $tid,
                'level' => $cancel->success ? 'warning' : 'error',
                'result_code' => $cancel->success ? 'CANCELLED' : ($cancel->errorCode ?? 'CANCEL_FAILED'),
                'result_msg' => $cancel->success ? '주문 반영 실패로 결제 자동 취소' : ($cancel->errorMessage ?? ''),
                'amount' => $cancel->cancelAmount,
            ]);
            if ($cancel->success) {
                $this->attempts->update($domainId, $moid, ['status' => Attempts::CANCELLED, 'result_code' => 'AUTO_CANCELLED', 'result_msg' => $message]);
                return $this->redirectFail($failUrl, '결제 처리 중 문제가 생겨 결제를 취소했습니다. 다시 시도해주세요.');
            }
        }

        $this->attempts->update($domainId, $moid, ['status' => Attempts::NEEDS_ACTION, 'result_code' => 'CONSUMER_FAILED', 'result_msg' => $message]);
        error_log("[Innopay][CRITICAL] 승인 후 주문 반영 실패: domain={$domainId} moid={$moid} tid={$tid} — {$message}");
        $this->logger->log($domainId, 'NEEDS_ACTION', [
            'moid' => $moid, 'tid' => $tid, 'level' => 'error',
            'result_code' => 'NEEDS_ACTION', 'result_msg' => '승인됐으나 주문 반영·자동취소 모두 실패 — 관리자 확인 필요',
        ]);

        return $this->redirectFail($failUrl, '결제는 승인되었지만 주문 처리에 실패했습니다. 고객센터로 문의해주세요.');
    }

    private function isApproved(array $result, string $tid, string $moid): bool
    {
        if (($result['success'] ?? false) !== true || !is_array($result['data'] ?? null)) {
            return false;
        }
        $data = $result['data'];

        return (int) ($data['status'] ?? -1) === InnopayGateway::STATUS_APPROVED
            && (string) ($data['tid'] ?? '') === $tid
            && (string) ($data['moid'] ?? $moid) === $moid;
    }

    private function findPrepared(PaymentConsumerInterface $consumer, int $domainId, string $moid): ?PreparedPayment
    {
        try {
            return $consumer->findPreparedPayment($domainId, $moid);
        } catch (\Throwable $e) {
            error_log('[Innopay] findPreparedPayment 실패: ' . $e->getMessage());
            return null;
        }
    }

    private function resolveConsumer(string $key): ?PaymentConsumerInterface
    {
        if ($key === '' || !$this->registry->hasKey(PaymentConsumerInterface::class, $key)) {
            return null;
        }
        try {
            $consumer = $this->registry->get(PaymentConsumerInterface::class, $key);
        } catch (\Throwable $e) {
            return null;
        }
        return $consumer instanceof PaymentConsumerInterface ? $consumer : null;
    }

    private function succeed(string $okUrl): InnopayReturnOutcome
    {
        return $okUrl !== ''
            ? InnopayReturnOutcome::redirect(true, $okUrl)
            : InnopayReturnOutcome::message(true, '결제가 완료되었습니다. 주문 내역에서 확인해주세요.');
    }

    private function fail(int $domainId, string $moid, string $tid, string $failUrl, string $code, string $message, string $level = 'error'): InnopayReturnOutcome
    {
        $this->logger->log($domainId, 'RETURN_FAIL', [
            'moid' => $moid, 'tid' => $tid, 'level' => $level,
            'result_code' => $code, 'result_msg' => $message,
        ]);
        return $this->redirectFail($failUrl, $message);
    }

    private function stop(int $domainId, string $moid, string $tid, string $code, string $message): InnopayReturnOutcome
    {
        $this->logger->log($domainId, 'RETURN_FAIL', [
            'moid' => $moid, 'tid' => $tid, 'level' => 'error',
            'result_code' => $code, 'result_msg' => $message,
        ]);
        return InnopayReturnOutcome::message(false, $message);
    }

    private function redirectFail(string $failUrl, string $message): InnopayReturnOutcome
    {
        if ($failUrl === '') {
            return InnopayReturnOutcome::message(false, $message);
        }
        $sep = str_contains($failUrl, '?') ? '&' : '?';
        return InnopayReturnOutcome::redirect(false, $failUrl . $sep . 'pay_error=' . rawurlencode($message), $message);
    }
}
