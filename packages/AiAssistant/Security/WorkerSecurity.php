<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Security;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Support\CanonicalJson;

final class WorkerSecurity
{
    public function __construct(
        private string $keyId,
        private string $publicKeyPem,
        private string $workerToken,
        private string $signingSecret
    ) {
    }

    /** @return array{key_id: string, algorithm: string, public_key_pem: string} */
    public function publicKey(): array
    {
        if ($this->keyId === '' || $this->publicKeyPem === '') {
            throw new ApiException('WORKER_KEY_UNAVAILABLE', 'Worker 암호화 키가 준비되지 않았습니다.', 503);
        }
        if (openssl_pkey_get_public($this->publicKeyPem) === false) {
            throw new ApiException('WORKER_KEY_INVALID', 'Worker 공개키 설정이 올바르지 않습니다.', 503);
        }
        return [
            'key_id' => $this->keyId,
            'algorithm' => 'RSA-OAEP-256',
            'public_key_pem' => $this->publicKeyPem,
        ];
    }

    public function assertKeyId(string $keyId): void
    {
        $active = $this->publicKey();
        if (!hash_equals($active['key_id'], $keyId)) {
            throw new ApiException('WORKER_KEY_STALE', '최신 Worker 공개키로 다시 암호화해야 합니다.', 409);
        }
    }

    public function authenticate(?string $provided): void
    {
        if ($this->workerToken === '' || $provided === null || !hash_equals($this->workerToken, $provided)) {
            throw new ApiException('WORKER_UNAUTHORIZED', 'Worker 인증에 실패했습니다.', 401);
        }
    }

    /** @param array<string, mixed> $result */
    public function verifyResultSignature(array $result): void
    {
        $signature = (string) ($result['worker_signature'] ?? '');
        unset($result['worker_signature']);
        if ($this->signingSecret === '' || !preg_match('/^hmac-sha256:[a-f0-9]{64}$/', $signature)) {
            throw new ApiException('WORKER_SIGNATURE_INVALID', 'Worker 결과 서명이 올바르지 않습니다.', 422);
        }
        $expected = 'hmac-sha256:' . hash_hmac('sha256', CanonicalJson::encode($result), $this->signingSecret);
        if (!hash_equals($expected, $signature)) {
            throw new ApiException('WORKER_SIGNATURE_INVALID', 'Worker 결과 서명이 올바르지 않습니다.', 422);
        }
    }
}
