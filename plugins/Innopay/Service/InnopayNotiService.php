<?php
declare(strict_types=1);

namespace Mublo\Plugin\Innopay\Service;

use Mublo\Plugin\Innopay\InnopayGateway;
use Mublo\Plugin\Innopay\Repository\InnopayAttemptRepository as Attempts;
use Mublo\Plugin\Innopay\Repository\InnopayCancelRepository;

/**
 * 결제결과 통보(Noti) 수신
 *
 * 이노페이는 승인·취소가 일어나면 상점관리에 등록한 URL 로 결과를 보낸다.
 * "0000" 을 돌려주면 수신 완료, 그 밖의 값이면 1분 간격으로 10번까지 다시 보낸다.
 *
 * 통보에는 서명이 없다. 그래서 통보 내용을 그대로 믿지 않고, 반영 전에 반드시
 * 거래조회 API 로 실제 상태를 확인한다(통보는 "확인해 보라"는 신호로만 쓴다).
 *
 *  - 승인(status 25): 복귀 과정이 끊겨 주문에 반영되지 않은 결제를 이어서 반영한다
 *  - 취소(status 85 / stateCd 1·2): 우리가 API 로 한 취소면 건너뛰고, 이노페이 상점관리 등
 *    외부에서 일어난 취소면 소비 패키지에 외부 환불로 기록한다
 */
class InnopayNotiService
{
    public const ACK = '0000';
    public const RETRY = 'FAIL';

    private const STATUS_APPROVE = '25';
    private const STATUS_CANCEL = '85';

    /** @var callable(int): InnopayGateway */
    private $gatewayFactory;

    public function __construct(
        private InnopayConfigService $configService,
        private Attempts $attempts,
        private InnopayCancelRepository $cancels,
        private InnopayLogger $logger,
        private InnopayReturnService $returnService,
        callable $gatewayFactory,
    ) {
        $this->gatewayFactory = $gatewayFactory;
    }

    /**
     * @param array<string, mixed> $params 통보 파라미터
     * @return string 이노페이에 돌려줄 응답 본문
     */
    public function handle(int $domainId, array $params, string $ip = ''): string
    {
        $params = self::toUtf8($params);

        $moid = trim((string) ($params['moid'] ?? ''));
        $tid = trim((string) ($params['transSeq'] ?? ''));
        $status = trim((string) ($params['status'] ?? ''));
        $stateCd = trim((string) ($params['stateCd'] ?? ''));
        $isCancel = $status === self::STATUS_CANCEL || in_array($stateCd, ['1', '2'], true);
        $amount = $isCancel
            ? (int) ($params['cancelAmt'] ?? $params['cancelApprovalAmt'] ?? 0)
            : (int) ($params['approvalAmt'] ?? $params['goodsAmt'] ?? 0);

        $this->logger->log($domainId, 'NOTI', [
            'moid' => $moid,
            'tid' => $tid,
            'amount' => $amount,
            'result_code' => "status:{$status}/state:{$stateCd}",
            'result_msg' => ($isCancel ? '취소 통보 ' : '승인 통보 ') . (string) ($params['payMethod'] ?? ''),
            'request' => $params,
            'ip' => $ip,
        ]);

        $config = $this->configService->getConfig($domainId);

        if (!self::ipAllowed($ip, (string) ($config['noti_ip_allowlist'] ?? ''))) {
            return $this->done($domainId, $moid, $tid, 'IP_REJECTED', "허용되지 않은 발신 IP: {$ip}", 'error', self::RETRY);
        }
        if ((string) ($params['shopCode'] ?? '') !== (string) $config['mid']) {
            return $this->done($domainId, $moid, $tid, 'MID_MISMATCH', '상점아이디가 설정과 다릅니다: ' . (string) ($params['shopCode'] ?? ''), 'error', self::RETRY);
        }
        if ($moid === '') {
            return $this->done($domainId, $moid, $tid, 'NO_MOID', '주문번호 없음', 'error');
        }

        $attempt = $this->attempts->find($domainId, $moid);
        if ($attempt === null) {
            // 이 사이트에서 준비하지 않은 결제 — 같은 MID 를 쓰는 다른 시스템의 거래일 수 있다
            return $this->done($domainId, $moid, $tid, 'NOT_OURS', '결제 시도 기록이 없는 주문번호', 'warning');
        }

        return $isCancel
            ? $this->handleCancel($domainId, $attempt, $tid, $amount, $params)
            : $this->handleApprove($domainId, $attempt, $tid);
    }

    private function handleApprove(int $domainId, array $attempt, string $notiTid): string
    {
        $moid = (string) $attempt['moid'];
        $status = (string) $attempt['status'];

        if ($status === Attempts::COMPLETED) {
            return $this->done($domainId, $moid, $notiTid, 'ALREADY_COMPLETED', '이미 주문에 반영됨');
        }
        if ($status === Attempts::APPROVING) {
            // 복귀 처리가 지금 승인 중이다 — 1분 뒤 재통보로 다시 확인한다
            return $this->done($domainId, $moid, $notiTid, 'IN_PROGRESS', '복귀 처리 진행 중 — 재통보 대기', 'info', self::RETRY);
        }
        if (in_array($status, [Attempts::CANCELLED, Attempts::NEEDS_ACTION], true)) {
            return $this->done($domainId, $moid, $notiTid, 'SKIPPED', "처리하지 않는 상태: {$status}", 'warning');
        }

        $isDepositWait = in_array($status, [Attempts::DEPOSIT_WAITING, Attempts::DEPOSITED], true);

        [$tx, $retry] = $this->findTransaction($domainId, $notiTid, $moid);
        if ($retry) {
            return $this->done($domainId, $moid, $notiTid, 'QUERY_FAILED', '거래조회 실패 — 재통보 대기', 'error', self::RETRY);
        }
        if ($tx === null || (int) ($tx['status'] ?? -1) !== InnopayGateway::STATUS_APPROVED || (string) ($tx['moid'] ?? '') !== $moid) {
            if ($isDepositWait) {
                // 입금 전 가상계좌는 조회에 나오지 않는다. 입금 통보 직후 반영이 늦을 수 있어 재통보로 다시 확인한다
                return $this->done($domainId, $moid, $notiTid, 'DEPOSIT_NOT_CONFIRMED', '입금 통보를 받았으나 거래조회로 입금이 확인되지 않음 — 재통보 대기', 'error', self::RETRY);
            }
            return $this->done($domainId, $moid, $notiTid, 'NOT_APPROVED', '거래조회 결과 승인 상태가 아님', 'warning');
        }

        if (InnopayGateway::isVirtualAccount((string) ($tx['payMethod'] ?? ''))) {
            return $this->handleDeposit($domainId, $attempt, $tx);
        }

        $tid = (string) $tx['tid'];
        $paid = (int) ($tx['amt'] ?? 0) + (int) ($tx['taxFreeAmt'] ?? 0);
        if ($paid !== (int) $attempt['amount']) {
            $this->attempts->update($domainId, $moid, ['status' => Attempts::NEEDS_ACTION, 'tid' => $tid,
                'result_code' => 'AMOUNT_MISMATCH', 'result_msg' => "승인 금액 {$paid} / 요청 금액 {$attempt['amount']}"]);
            return $this->done($domainId, $moid, $tid, 'AMOUNT_MISMATCH', "승인 금액({$paid})이 요청 금액({$attempt['amount']})과 다름 — 관리자 확인 필요", 'error');
        }

        // 복귀가 끊긴 시도를 승인 상태로 옮기고 주문 반영을 이어간다
        if (!$this->attempts->transition($domainId, $moid, [Attempts::READY, Attempts::FAILED, Attempts::APPROVED], Attempts::APPROVED,
            ['tid' => $tid, 'result_code' => 'APPROVED', 'result_msg' => '결제결과 통보로 승인 확인'])) {
            $fresh = $this->attempts->find($domainId, $moid);
            if (($fresh['status'] ?? '') === Attempts::COMPLETED) {
                return $this->done($domainId, $moid, $tid, 'ALREADY_COMPLETED', '이미 주문에 반영됨');
            }
            return $this->done($domainId, $moid, $tid, 'STATE_CONFLICT', '시도 상태 변경 경합 — 재통보 대기', 'warning', self::RETRY);
        }

        $outcome = $this->returnService->completeApproved($domainId, $moid, $tid);

        // 반영 실패여도 자동 취소 또는 NEEDS_ACTION 으로 이미 처리됐다 — 재통보로 바뀔 것이 없다
        return $this->done($domainId, $moid, $tid,
            $outcome->success ? 'COMPLETED' : 'CONSUMER_FAILED',
            $outcome->success ? '결제결과 통보로 주문 반영' : '주문 반영 실패: ' . $outcome->message,
            $outcome->success ? 'warning' : 'error');
    }

    /**
     * 가상계좌 입금 — 통보 + 거래조회(승인 상태·금액·거래번호) 가 모두 맞을 때만 결제 완료로 옮긴다.
     */
    private function handleDeposit(int $domainId, array $attempt, array $tx): string
    {
        $moid = (string) $attempt['moid'];
        $tid = (string) ($tx['tid'] ?? '');

        if (!in_array((string) $attempt['status'], [Attempts::DEPOSIT_WAITING, Attempts::DEPOSITED], true)) {
            return $this->done($domainId, $moid, $tid, 'VBANK_NOT_WAITING', '입금 대기 중이 아닌 결제의 가상계좌 입금 통보: ' . $attempt['status'], 'error');
        }
        if ((string) ($attempt['tid'] ?? '') !== $tid) {
            return $this->done($domainId, $moid, $tid, 'TID_MISMATCH', "발급한 계좌의 거래번호({$attempt['tid']})와 다른 입금", 'error');
        }

        $paid = (int) ($tx['amt'] ?? 0) + (int) ($tx['taxFreeAmt'] ?? 0);
        if ($paid !== (int) $attempt['amount']) {
            $this->attempts->update($domainId, $moid, ['status' => Attempts::NEEDS_ACTION,
                'result_code' => 'AMOUNT_MISMATCH', 'result_msg' => "입금 금액 {$paid} / 요청 금액 {$attempt['amount']}"]);
            return $this->done($domainId, $moid, $tid, 'AMOUNT_MISMATCH', "입금 금액({$paid})이 요청 금액({$attempt['amount']})과 다름 — 관리자 확인 필요", 'error');
        }

        // 입금 확인을 먼저 기록한다 — 게이트웨이 verify 는 이 기록이 있어야 가상계좌를 결제 완료로 본다
        if (!$this->attempts->transition($domainId, $moid, [Attempts::DEPOSIT_WAITING, Attempts::DEPOSITED], Attempts::DEPOSITED,
            ['result_code' => 'DEPOSITED', 'result_msg' => '입금 확인'])) {
            return $this->done($domainId, $moid, $tid, 'STATE_CONFLICT', '시도 상태 변경 경합 — 재통보 대기', 'warning', self::RETRY);
        }

        $outcome = $this->returnService->completeApproved($domainId, $moid, $tid);

        return $this->done($domainId, $moid, $tid,
            $outcome->success ? 'DEPOSIT_COMPLETED' : 'CONSUMER_FAILED',
            $outcome->success ? "가상계좌 입금 {$paid}원 확인 — 주문 결제 완료" : '입금 확인 후 주문 반영 실패: ' . $outcome->message,
            $outcome->success ? 'info' : 'error');
    }

    private function handleCancel(int $domainId, array $attempt, string $notiTid, int $cancelAmt, array $params): string
    {
        $moid = (string) $attempt['moid'];

        [$tx, $retry] = $this->findTransaction($domainId, $notiTid, $moid);
        if ($retry || $tx === null) {
            return $this->done($domainId, $moid, $notiTid, 'QUERY_FAILED', '취소 거래 조회 실패 — 재통보 대기', 'error', self::RETRY);
        }

        // 부분취소 통보는 취소 거래 자체를 가리킬 수 있다 — 원거래(otid)를 기준으로 본다
        $origTid = (string) (($tx['otid'] ?? '') ?: ($tx['tid'] ?? ''));
        $orig = $tx;
        if ($origTid !== '' && $origTid !== (string) ($tx['tid'] ?? '')) {
            [$orig, $retry] = $this->findTransaction($domainId, $origTid, $moid);
            if ($retry || $orig === null) {
                return $this->done($domainId, $moid, $origTid, 'QUERY_FAILED', '원거래 조회 실패 — 재통보 대기', 'error', self::RETRY);
            }
        }

        if (!empty($attempt['tid']) && (string) $attempt['tid'] !== $origTid) {
            return $this->done($domainId, $moid, $origTid, 'TID_MISMATCH', "결제 시도의 거래번호({$attempt['tid']})와 다른 거래의 취소", 'warning');
        }

        $origStatus = (int) ($orig['status'] ?? -1);
        $fullCancel = in_array($origStatus, [InnopayGateway::STATUS_CANCELLED_BEFORE, InnopayGateway::STATUS_CANCELLED_AFTER], true);
        if (!$fullCancel && empty($orig['partialCancels'])) {
            return $this->done($domainId, $moid, $origTid, 'NOT_CANCELLED', '거래조회 결과 취소 내역이 없음', 'warning');
        }
        if ($cancelAmt <= 0) {
            $cancelAmt = (int) ($orig['cancel']['amt'] ?? 0);
        }

        // 우리가 API 로 한 취소(관리자 환불·자동 취소)는 소비 패키지에 이미 반영됐다
        if ($cancelAmt > 0 && $this->cancels->claimApiMatch($domainId, $origTid, $cancelAmt)) {
            if ($fullCancel) {
                $this->attempts->update($domainId, $moid, ['status' => Attempts::CANCELLED]);
            }
            return $this->done($domainId, $moid, $origTid, 'OWN_CANCEL', '우리가 요청한 취소의 통보 — 반영 생략');
        }

        $notiKey = trim((string) ($params['cancelPgTid'] ?? ''));
        if ($notiKey === '') {
            $notiKey = $notiTid . '|' . $cancelAmt . '|' . ($params['cancelAppDate'] ?? '') . ($params['cancelAppTime'] ?? '');
        }
        if ($this->cancels->existsNoti($domainId, $notiKey)) {
            return $this->done($domainId, $moid, $origTid, 'DUPLICATE', '이미 반영한 취소 통보');
        }

        $consumer = $this->returnService->resolveConsumerFor($attempt);
        if ($consumer === null) {
            return $this->done($domainId, $moid, $origTid, 'NO_CONSUMER', '결제 소비자를 찾을 수 없음', 'error');
        }

        $reason = trim((string) ($params['cancelMsg'] ?? '')) ?: '이노페이 취소 통보';
        // 전체취소인데 금액을 못 받았으면 남은 금액 전부 (소비 패키지가 잔액으로 자른다)
        $amount = $cancelAmt > 0 ? $cancelAmt : ($fullCancel ? PHP_INT_MAX : 0);
        if ($amount <= 0) {
            return $this->done($domainId, $moid, $origTid, 'NO_AMOUNT', '부분취소 금액 없음', 'error');
        }

        try {
            $result = $consumer->recordExternalRefund($domainId, $moid, InnopayGateway::PG_KEY, $origTid, $amount, $reason, $fullCancel);
        } catch (\Throwable $e) {
            return $this->done($domainId, $moid, $origTid, 'CONSUMER_EXCEPTION', $e->getMessage(), 'error', self::RETRY);
        }

        if ($result->isFailure()) {
            return $this->done($domainId, $moid, $origTid, 'CONSUMER_FAILED', '외부 환불 기록 실패: ' . $result->getMessage(), 'error', self::RETRY);
        }

        $this->cancels->recordNoti($domainId, $moid, $origTid, $cancelAmt, $notiKey);
        if ($fullCancel) {
            $this->attempts->update($domainId, $moid, ['status' => Attempts::CANCELLED, 'result_code' => 'EXTERNAL_CANCEL', 'result_msg' => $reason]);
        }

        return $this->done($domainId, $moid, $origTid, 'EXTERNAL_REFUND',
            ($fullCancel ? '전체' : '부분') . " 취소 {$cancelAmt}원을 외부 환불로 기록: " . $result->getMessage(), 'warning');
    }

    /**
     * 통보의 거래번호로 조회하고, 안 되면 주문번호로 조회한다.
     *
     * @return array{0: ?array, 1: bool} [거래 데이터, 재시도 필요(네트워크 오류 등)]
     */
    private function findTransaction(int $domainId, string $tid, string $moid): array
    {
        $client = ($this->gatewayFactory)($domainId)->createClient();

        if ($tid !== '') {
            $result = $client->findByTid($tid, $moid);
            if (($result['success'] ?? false) === true && is_array($result['data'] ?? null)) {
                return [$result['data'], false];
            }
            if (!empty($result['_ambiguous'])) {
                return [null, true];
            }
        }

        $result = $client->findByMoid($moid);
        if (($result['success'] ?? false) === true && is_array($result['data'] ?? null)) {
            return [$result['data'], false];
        }

        return [null, !empty($result['_ambiguous'])];
    }

    private function done(int $domainId, string $moid, string $tid, string $code, string $message, string $level = 'info', string $reply = self::ACK): string
    {
        $this->logger->log($domainId, 'NOTI_RESULT', [
            'moid' => $moid,
            'tid' => $tid,
            'level' => $level,
            'result_code' => $code,
            'result_msg' => $message . ($reply !== self::ACK ? ' [응답 ' . $reply . ']' : ''),
        ]);
        return $reply;
    }

    /**
     * 발신 IP 허용목록 검사 — 비어 있으면 모두 허용(거래조회로 검증하므로 선택 사항).
     * 쉼표·공백으로 구분, CIDR(1.2.3.0/24) 지원.
     */
    public static function ipAllowed(string $ip, string $allowlist): bool
    {
        $allowlist = trim($allowlist);
        if ($allowlist === '') {
            return true;
        }
        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return false;
        }

        foreach (preg_split('/[\s,]+/', $allowlist) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }
            if (!str_contains($entry, '/')) {
                if ($entry === $ip) {
                    return true;
                }
                continue;
            }
            [$subnet, $bits] = explode('/', $entry, 2);
            $subnetLong = ip2long($subnet);
            $bits = (int) $bits;
            if ($subnetLong === false || $bits < 0 || $bits > 32) {
                continue;
            }
            $mask = $bits === 0 ? 0 : (~0 << (32 - $bits)) & 0xFFFFFFFF;
            if (($ipLong & $mask) === ($subnetLong & $mask)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 통보가 EUC-KR 로 올 수 있어 UTF-8 이 아닌 값은 CP949 로 보고 변환한다.
     */
    public static function toUtf8(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                $params[$key] = self::toUtf8($value);
            } elseif (is_string($value) && $value !== '' && !mb_check_encoding($value, 'UTF-8')) {
                $params[$key] = mb_convert_encoding($value, 'UTF-8', 'CP949');
            }
        }
        return $params;
    }
}
