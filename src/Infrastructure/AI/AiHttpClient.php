<?php
namespace Mublo\Infrastructure\AI;

use Mublo\Core\AiConfig;

class AiHttpClient
{
    /**
     * 일시 장애로 판단해 1회 재시도하는 조건 — 요청 타임아웃(28)은 제외한다
     * (45초 타임아웃을 재시도하면 관리자 요청 총 시간이 배가된다).
     */
    private const RETRYABLE_STATUS = [429, 500, 502, 503, 529];
    private const RETRYABLE_CURL_ERRNO = [6 /* resolve */, 7 /* connect */, 35 /* ssl connect */, 52 /* empty reply */, 56 /* recv */];

    public function post(string $url, array $headers, array $payload): array
    {
        $config = AiConfig::load();
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $attempt = $this->execute($url, $headers, $body, $config);
        if ($attempt['retryable']) {
            usleep(1_000_000); // 1초 후 1회 재시도 (429/5xx·연결 단계 실패 한정)
            $attempt = $this->execute($url, $headers, $body, $config);
        }

        $status = $attempt['status'];
        if ($attempt['ok'] === false || $status < 200 || $status >= 300) {
            $kind = $status === 401 || $status === 403 ? 'AI API 키를 확인해주세요.'
                : ($status === 429 ? 'AI 공급자의 요청 한도를 초과했습니다.' : 'AI 공급자 요청에 실패했습니다.');
            throw new \RuntimeException($kind . ($attempt['error'] !== '' ? ' 네트워크 연결을 확인해주세요.' : ''));
        }

        $decoded = json_decode($attempt['response'], true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('AI 공급자가 올바르지 않은 응답을 반환했습니다.');
        }
        return $decoded;
    }

    /**
     * @return array{ok: bool, status: int, error: string, response: string, retryable: bool}
     */
    private function execute(string $url, array $headers, string $body, array $config): array
    {
        $response = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADER => false,
            CURLOPT_CONNECTTIMEOUT => (int) $config['connect_timeout_seconds'],
            CURLOPT_TIMEOUT => (int) $config['request_timeout_seconds'],
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$response, $config): int {
                $response .= $chunk;
                if (strlen($response) > (int) $config['max_response_bytes']) {
                    return 0;
                }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        $retryable = ($ok === false && in_array($errno, self::RETRYABLE_CURL_ERRNO, true))
            || in_array($status, self::RETRYABLE_STATUS, true);

        return [
            'ok' => $ok !== false,
            'status' => $status,
            'error' => $error,
            'response' => $response,
            'retryable' => $retryable,
        ];
    }
}
