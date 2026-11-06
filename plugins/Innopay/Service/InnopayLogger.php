<?php
declare(strict_types=1);

namespace Mublo\Plugin\Innopay\Service;

use Mublo\Plugin\Innopay\Repository\InnopayLogRepository;

/**
 * 결제 단계별 로그 기록기
 *
 * 결제 처리 도중 로그 기록이 실패해도 결제 흐름은 멈추지 않는다 — 로그는 보조 수단이다.
 * 대신 기록 실패는 error_log 로 남긴다.
 *
 * 비밀값(Merchant-Key, Payment-Token, 취소 비밀번호, 환불 계좌번호)은 저장 전에 가린다.
 */
class InnopayLogger
{
    /** 값 전체를 가리는 키 (대소문자 무시) */
    private const SECRET_KEYS = ['merchant-key', 'merchantkey', 'merchant_key', 'payment-token',
        'paymenttoken', 'cancelpwd', 'cancel_pwd'];

    /** 뒤 4자리만 남기는 키 */
    private const PARTIAL_KEYS = ['refundacctno', 'tel', 'buyertel', 'customer_phone'];

    private const MAX_BODY = 60000;

    public function __construct(private InnopayLogRepository $repository)
    {
    }

    /**
     * @param array{
     *   moid?: string, tid?: string, level?: string, amount?: int|null,
     *   result_code?: string|null, result_msg?: string|null, http_status?: int|null,
     *   duration_ms?: int|null, request?: mixed, response?: mixed, ip?: string|null, user_agent?: string|null
     * } $entry
     */
    public function log(int $domainId, string $step, array $entry = []): void
    {
        try {
            $this->repository->insert([
                'domain_id' => $domainId,
                'moid' => mb_substr((string) ($entry['moid'] ?? ''), 0, 40),
                'tid' => mb_substr((string) ($entry['tid'] ?? ''), 0, 40),
                'step' => mb_substr($step, 0, 30),
                'level' => $entry['level'] ?? 'info',
                'amount' => isset($entry['amount']) ? (int) $entry['amount'] : null,
                'result_code' => isset($entry['result_code']) ? mb_substr((string) $entry['result_code'], 0, 20) : null,
                'result_msg' => isset($entry['result_msg']) ? mb_substr((string) $entry['result_msg'], 0, 255) : null,
                'http_status' => isset($entry['http_status']) ? (int) $entry['http_status'] : null,
                'duration_ms' => isset($entry['duration_ms']) ? (int) $entry['duration_ms'] : null,
                'request' => $this->encode($entry['request'] ?? null),
                'response' => $this->encode($entry['response'] ?? null),
                'ip' => isset($entry['ip']) ? mb_substr((string) $entry['ip'], 0, 45) : null,
                'user_agent' => isset($entry['user_agent']) ? mb_substr((string) $entry['user_agent'], 0, 255) : null,
            ]);
        } catch (\Throwable $e) {
            error_log('[Innopay] 로그 기록 실패 step=' . $step . ' moid=' . ($entry['moid'] ?? '') . ': ' . $e->getMessage());
        }
    }

    /**
     * 비밀값을 가린 사본을 돌려준다 (중첩 배열 포함).
     */
    public static function mask(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $masked = [];
        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);
            if (in_array($lower, self::SECRET_KEYS, true)) {
                $masked[$key] = ($value === null || $value === '') ? $value : '***';
            } elseif (in_array($lower, self::PARTIAL_KEYS, true) && is_scalar($value)) {
                $masked[$key] = self::maskTail((string) $value);
            } else {
                $masked[$key] = self::mask($value);
            }
        }
        return $masked;
    }

    private static function maskTail(string $value): string
    {
        $len = strlen($value);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }
        return str_repeat('*', $len - 4) . substr($value, -4);
    }

    private function encode(mixed $data): ?string
    {
        if ($data === null) {
            return null;
        }

        $json = is_string($data)
            ? $data
            : json_encode(self::mask($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            return null;
        }

        return strlen($json) > self::MAX_BODY ? mb_strcut($json, 0, self::MAX_BODY) . '…(truncated)' : $json;
    }
}
