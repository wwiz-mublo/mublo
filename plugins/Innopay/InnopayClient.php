<?php
declare(strict_types=1);

namespace Mublo\Plugin\Innopay;

use Mublo\Plugin\Innopay\Service\InnopayLogger;

/**
 * 이노페이 API 클라이언트
 *
 * 모든 호출은 요청 직전(*_REQ)과 응답 직후(*_RES) 두 줄의 로그를 남긴다.
 * 요청 로그를 먼저 남기는 이유: 응답을 기다리다 프로세스가 죽어도 "보냈다"는 흔적은 남아야
 * PG 쪽 결과를 대조할 수 있다.
 *
 * 반환값은 이노페이 응답 JSON 을 배열로 푼 것에 아래 메타 키를 더한 것이다.
 *   _http_code : HTTP 상태 (네트워크 오류면 0)
 *   _error     : 전송 오류 메시지 (없으면 '')
 *   _ambiguous : 결과를 알 수 없음 (타임아웃·5xx·JSON 아님) — 승인 결과 복구 판단에 쓴다
 */
class InnopayClient
{
    public const API_BASE = 'https://api.innopay.co.kr';

    /** 통합취소 성공 코드 */
    public const CANCEL_SUCCESS_CODE = '2001';

    /** @var callable(string, string, array<string,string>, ?string): array{0:int,1:string,2:string} */
    private $transport;

    public function __construct(
        private string $mid,
        private string $merchantKey,
        private string $cancelPwd,
        private InnopayLogger $logger,
        private int $domainId,
        ?callable $transport = null,
        private int $timeout = 30,
    ) {
        $this->transport = $transport ?? $this->curlTransport(...);
    }

    public function getMid(): string
    {
        return $this->mid;
    }

    /**
     * 결제 승인 — returnUrl 로 받은 값으로 서버에서 승인한다 (인증 후 5분 이내).
     */
    public function approve(string $paymentToken, string $tid, string $moid, int $amt, int $taxFreeAmt): array
    {
        return $this->send('APPROVE', 'POST', '/v1/transactions/pay', [
            'Payment-Token' => $paymentToken,
            'Merchant-Key' => $this->merchantKey,
        ], [
            'tid' => $tid,
            'mid' => $this->mid,
            'moid' => $moid,
            'amt' => $amt,
            'taxFreeAmt' => $taxFreeAmt,
        ], $moid, $tid, $amt + $taxFreeAmt);
    }

    /** 거래번호로 조회 */
    public function findByTid(string $tid, string $moid = ''): array
    {
        return $this->send('QUERY_TID', 'GET', '/v1/transactions/' . rawurlencode($tid), $this->queryHeaders(), null, $moid, $tid);
    }

    /** 주문번호로 조회 — 승인 응답을 못 받았을 때 실제 결과를 확인하는 용도 */
    public function findByMoid(string $moid): array
    {
        return $this->send('QUERY_MOID', 'GET', '/v1/transactions/orders/' . rawurlencode($moid), $this->queryHeaders(), null, $moid, '');
    }

    /**
     * 통합취소 (전체/부분)
     *
     * 인증은 헤더가 아니라 본문의 취소 비밀번호(cancelPwd)로 한다.
     * 본문은 반드시 application/json 이어야 한다(form 헤더는 415).
     *
     * @param string $svcCd 01 신용카드, 02 계좌이체, 04 현금영수증, 07 휴대폰
     */
    public function cancel(string $tid, string $svcCd, int $cancelAmt, bool $partial, string $reason, string $moid = ''): array
    {
        return $this->send('CANCEL', 'POST', '/api/cancelApi', [], [
            'mid' => $this->mid,
            'tid' => $tid,
            'svcCd' => $svcCd,
            'partialCancelCode' => $partial ? '1' : '0',
            'cancelAmt' => (string) $cancelAmt,
            'cancelMsg' => mb_substr($reason !== '' ? $reason : '가맹점 취소', 0, 100),
            'cancelPwd' => $this->cancelPwd,
        ], $moid, $tid, $cancelAmt);
    }

    /**
     * 주문취소 — 입금 전 가상계좌(SMS·ARS 주문 포함)를 주문번호로 닫는다. 결제 취소(환불)가 아니다.
     * 같은 주문번호가 여러 건이면 최신 1건이 취소된다.
     */
    public function cancelOrder(string $moid): array
    {
        return $this->send('ORDER_CANCEL', 'POST', '/v1/order/cancel', $this->queryHeaders(), ['moid' => $moid], $moid, '');
    }

    /** @return array<string,string> */
    private function queryHeaders(): array
    {
        return ['MID' => $this->mid, 'Merchant-Key' => $this->merchantKey];
    }

    private function send(string $step, string $method, string $path, array $headers, ?array $body, string $moid, string $tid, ?int $amount = null): array
    {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        $headers['Accept'] = 'application/json';
        $json = $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null;

        $this->logger->log($this->domainId, $step . '_REQ', [
            'moid' => $moid,
            'tid' => $tid,
            'amount' => $amount,
            'request' => ['method' => $method, 'path' => $path, 'headers' => $headers, 'body' => $body],
        ]);

        $started = microtime(true);
        try {
            [$httpCode, $raw, $error] = ($this->transport)($method, self::API_BASE . $path, $headers, $json);
        } catch (\Throwable $e) {
            [$httpCode, $raw, $error] = [0, '', $e->getMessage()];
        }
        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $decoded = $raw !== '' ? json_decode($raw, true) : null;
        $isJson = is_array($decoded);
        $result = $isJson ? $decoded : [];

        $ambiguous = $error !== '' || $httpCode === 0 || $httpCode >= 500 || !$isJson;
        $result['_http_code'] = $httpCode;
        $result['_error'] = $error;
        $result['_ambiguous'] = $ambiguous;

        [$code, $message, $ok] = self::summarize($step, $result);
        if ($error !== '') {
            $message = '전송 오류: ' . $error;
        } elseif (!$isJson && $httpCode > 0) {
            $message = 'JSON 이 아닌 응답 (HTTP ' . $httpCode . ')';
        }

        $this->logger->log($this->domainId, $step . '_RES', [
            'moid' => $moid !== '' ? $moid : (string) ($result['data']['moid'] ?? ''),
            'tid' => $tid !== '' ? $tid : (string) ($result['data']['tid'] ?? ''),
            'amount' => $amount,
            'level' => $ok ? 'info' : ($ambiguous ? 'error' : 'warning'),
            'result_code' => $code,
            'result_msg' => $message,
            'http_status' => $httpCode,
            'duration_ms' => $durationMs,
            'response' => $isJson ? $decoded : mb_strcut($raw, 0, 2000),
        ]);

        return $result;
    }

    /**
     * 응답에서 (결과코드, 메시지, 성공여부) 를 뽑는다.
     * v1 API 는 {success, data|error}, 통합취소는 {resultCode, resultMsg} 형식이다.
     *
     * @return array{0: ?string, 1: ?string, 2: bool}
     */
    public static function summarize(string $step, array $result): array
    {
        if ($step === 'CANCEL') {
            $code = isset($result['resultCode']) ? (string) $result['resultCode'] : null;
            return [$code, isset($result['resultMsg']) ? (string) $result['resultMsg'] : null, $code === self::CANCEL_SUCCESS_CODE];
        }

        if (($result['success'] ?? false) === true) {
            $status = $result['data']['status'] ?? null;
            return [$status !== null ? 'status:' . $status : 'OK', 'success', true];
        }

        return [
            isset($result['error']['code']) ? (string) $result['error']['code'] : null,
            isset($result['error']['message']) ? (string) $result['error']['message'] : null,
            false,
        ];
    }

    /**
     * @param array<string,string> $headers
     * @return array{0:int,1:string,2:string}
     */
    private function curlTransport(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        return [$httpCode, is_string($raw) ? $raw : '', $error];
    }
}
