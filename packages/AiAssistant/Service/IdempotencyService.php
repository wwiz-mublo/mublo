<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant\Service;

use Mublo\Packages\AiAssistant\Exception\ApiException;
use Mublo\Packages\AiAssistant\Repository\IdempotencyRepository;
use Mublo\Packages\AiAssistant\Support\CanonicalJson;
use Mublo\Packages\AiAssistant\Support\TokenCodec;

final class IdempotencyService
{
    private const TTL = 86400;

    public function __construct(private IdempotencyRepository $repository)
    {
    }

    /**
     * @param array<string, mixed> $request
     * @param callable(): array<string, mixed> $operation
     * @return array{data: array<string, mixed>, status: int, replayed: bool}
     */
    public function execute(
        string $companyId,
        string $endpoint,
        ?string $key,
        array $request,
        int $successStatus,
        callable $operation
    ): array {
        if ($key === null || strlen($key) < 8 || strlen($key) > 128) {
            throw new ApiException('IDEMPOTENCY_KEY_REQUIRED', 'Idempotency-Key 헤더가 필요합니다.', 422);
        }
        $keyHash = TokenCodec::hash($key);
        $requestHash = hash('sha256', CanonicalJson::encode($request));
        $existing = $this->repository->find($companyId, $endpoint, $keyHash);
        if ($existing !== null) {
            return $this->replay($existing, $requestHash);
        }

        $data = $operation();
        $json = CanonicalJson::encode($data);
        try {
            $this->repository->store(
                $companyId,
                $endpoint,
                $keyHash,
                $requestHash,
                $json,
                $successStatus,
                time() + self::TTL
            );
        } catch (\Throwable $exception) {
            $raced = $this->repository->find($companyId, $endpoint, $keyHash);
            if ($raced === null) {
                throw $exception;
            }
            return $this->replay($raced, $requestHash);
        }

        return ['data' => $data, 'status' => $successStatus, 'replayed' => false];
    }

    /** @param array<string, mixed> $row @return array{data: array<string, mixed>, status: int, replayed: bool} */
    private function replay(array $row, string $requestHash): array
    {
        if (!hash_equals((string) $row['request_hash'], $requestHash)) {
            throw new ApiException(
                'IDEMPOTENCY_KEY_REUSED',
                '같은 Idempotency-Key가 다른 요청에 사용되었습니다.',
                409
            );
        }
        $data = json_decode((string) $row['response_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \RuntimeException('Stored idempotency response must be an object');
        }
        return ['data' => $data, 'status' => (int) $row['response_status'], 'replayed' => true];
    }
}
