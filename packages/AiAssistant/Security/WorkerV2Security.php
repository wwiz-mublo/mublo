<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Security;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Support\CanonicalJson;

final class WorkerV2Security implements WorkerResultVerifierInterface
{
    public function __construct(private string $signingKeyId, private string $publicKeyBase64)
    {
    }

    /** @return array{ready: bool, signing_key_id: string} */
    public function readiness(): array
    {
        $publicKey = base64_decode($this->publicKeyBase64, true);
        return [
            'ready' => function_exists('sodium_crypto_sign_verify_detached')
                && $this->signingKeyId !== ''
                && is_string($publicKey)
                && defined('SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES')
                && strlen($publicKey) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
            'signing_key_id' => $this->signingKeyId,
        ];
    }

    /** @param array<string, mixed> $result */
    public function verifyResultSignature(array $result): void
    {
        if (!$this->readiness()['ready']) {
            throw new ApiException('WORKER_SIGNATURE_UNAVAILABLE', 'Ed25519 검증 기능이 준비되지 않았습니다.', 503);
        }
        if (($result['signature_algorithm'] ?? null) !== 'Ed25519'
            || !hash_equals($this->signingKeyId, (string) ($result['signing_key_id'] ?? ''))) {
            throw new ApiException('WORKER_SIGNATURE_INVALID', 'Worker 서명 키가 올바르지 않습니다.', 422);
        }
        $signature = base64_decode((string) ($result['worker_signature'] ?? ''), true);
        $publicKey = base64_decode($this->publicKeyBase64, true);
        unset($result['worker_signature']);
        if (!is_string($signature) || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !is_string($publicKey) || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || !sodium_crypto_sign_verify_detached($signature, CanonicalJson::encode($result), $publicKey)) {
            throw new ApiException('WORKER_SIGNATURE_INVALID', 'Worker 결과 서명이 올바르지 않습니다.', 422);
        }
    }
}
