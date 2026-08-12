<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Service;

use Mublo\Packages\AiAssistant\Contract\SchedulePushGatewayInterface;

/** AI Assistant 기기의 FCM HTTP v1 데이터 메시지만 담당하는 작은 전송 어댑터. */
final class FirebaseSchedulePushGateway implements SchedulePushGatewayInterface
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** @var array<string, mixed>|null */
    private ?array $serviceAccount = null;

    public function __construct(private string $serviceAccountFile)
    {
    }

    public function send(string $fcmToken, array $data): array
    {
        if ($fcmToken === '') {
            return $this->failure('FCM_TOKEN_MISSING', '활성 기기의 FCM 토큰이 없습니다.', true);
        }
        $account = $this->account();
        if ($account === null) {
            return $this->failure('FCM_CONFIG_MISSING', 'Firebase 서비스 계정 설정이 없습니다.');
        }
        $accessToken = $this->accessToken($account);
        if ($accessToken === null) {
            return $this->failure('FCM_AUTH_FAILED', 'Firebase access token을 발급하지 못했습니다.');
        }

        $payload = [
            'message' => [
                'token' => $fcmToken,
                'data' => array_map(static fn(mixed $value): string => (string) $value, $data),
                'android' => [
                    'priority' => 'high',
                    'ttl' => '300s',
                ],
            ],
        ];
        $response = $this->request(
            'https://fcm.googleapis.com/v1/projects/' . rawurlencode((string) $account['project_id']) . '/messages:send',
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ['Authorization: Bearer ' . $accessToken, 'Content-Type: application/json']
        );
        if ($response['status'] >= 200 && $response['status'] < 300) {
            $body = json_decode($response['body'], true);
            return [
                'success' => true,
                'message_id' => is_array($body) ? (string) ($body['name'] ?? '') : '',
                'error_code' => '',
                'token_invalid' => false,
                'error' => '',
            ];
        }

        $body = json_decode($response['body'], true);
        $code = is_array($body)
            ? (string) ($body['error']['details'][0]['errorCode'] ?? $body['error']['status'] ?? 'FCM_HTTP_ERROR')
            : 'FCM_HTTP_ERROR';
        $message = is_array($body)
            ? (string) ($body['error']['message'] ?? ('HTTP ' . $response['status']))
            : ('HTTP ' . $response['status']);
        return $this->failure(
            $code,
            $message,
            in_array($code, ['UNREGISTERED', 'INVALID_ARGUMENT', 'NOT_FOUND', 'SENDER_ID_MISMATCH'], true)
        );
    }

    /** @return array<string, mixed>|null */
    private function account(): ?array
    {
        if ($this->serviceAccount !== null) return $this->serviceAccount;
        if ($this->serviceAccountFile === '' || !is_file($this->serviceAccountFile)) return null;
        $decoded = json_decode((string) file_get_contents($this->serviceAccountFile), true);
        if (!is_array($decoded)
            || empty($decoded['project_id'])
            || empty($decoded['client_email'])
            || empty($decoded['private_key'])
        ) return null;
        return $this->serviceAccount = $decoded;
    }

    /** @param array<string, mixed> $account */
    private function accessToken(array $account): ?string
    {
        $cache = $this->tokenCacheFile((string) $account['project_id']);
        if (is_file($cache)) {
            $stored = json_decode((string) @file_get_contents($cache), true);
            if (is_array($stored) && (int) ($stored['expires_at'] ?? 0) > time() + 60) {
                return (string) ($stored['access_token'] ?? '');
            }
        }

        $now = time();
        $header = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode([
            'iss' => (string) $account['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signingInput = $header . '.' . $claims;
        $signature = '';
        $key = openssl_pkey_get_private((string) $account['private_key']);
        if ($key === false || !openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) return null;

        $response = $this->request(
            self::TOKEN_URL,
            http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $signingInput . '.' . $this->base64Url($signature),
            ]),
            ['Content-Type: application/x-www-form-urlencoded']
        );
        if ($response['status'] < 200 || $response['status'] >= 300) return null;
        $body = json_decode($response['body'], true);
        $token = is_array($body) ? (string) ($body['access_token'] ?? '') : '';
        if ($token === '') return null;
        @file_put_contents($cache, json_encode([
            'access_token' => $token,
            'expires_at' => $now + max(300, (int) ($body['expires_in'] ?? 3600) - 300),
        ], JSON_THROW_ON_ERROR), LOCK_EX);
        return $token;
    }

    /** @return array{status:int,body:string} */
    private function request(string $url, string $body, array $headers): array
    {
        $curl = curl_init($url);
        if ($curl === false) return ['status' => 0, 'body' => ''];
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['status' => $status, 'body' => is_string($response) ? $response : ''];
    }

    private function tokenCacheFile(string $projectId): string
    {
        $directory = MUBLO_STORAGE_PATH . '/cache';
        if (!is_dir($directory)) @mkdir($directory, 0755, true);
        return $directory . '/ai_fcm_token_' . hash('sha256', $projectId) . '.json';
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** @return array{success:bool,message_id:string,error_code:string,token_invalid:bool,error:string} */
    private function failure(string $code, string $message, bool $invalid = false): array
    {
        return [
            'success' => false,
            'message_id' => '',
            'error_code' => $code,
            'token_invalid' => $invalid,
            'error' => $message,
        ];
    }
}
