<?php
declare(strict_types=1);

namespace Mublo\Contract\Site;

/**
 * 하나의 사이트 변경 트랜잭션에서 실제로 바뀐 자원을 모은다.
 *
 * 같은 resource_id를 여러 번 기록하면 최종 fingerprint 하나만 감사 revision에
 * 반영한다. 호출자는 DB 변경과 같은 Database::transaction() 안에서 recorder에
 * 이 문맥을 전달해야 한다. 여러 mutation 서비스에 같은 문맥을 넘긴 경우 서비스는
 * 자원만 수집하며, 최상위 호출자가 모든 변경 뒤 recorder::record()를 한 번 호출한다.
 */
final class SiteMutationContext
{
    public const SOURCE_ADMIN = 'admin';
    public const SOURCE_AUTOMATION = 'automation';
    public const SOURCE_API = 'api';
    public const SOURCE_EXTENSION = 'extension';

    /** @var array<string, SiteMutationResource> */
    private array $resources = [];

    public static function create(int $domainId, string $source, ?string $correlationId = null): self
    {
        return new self($domainId, $source, $correlationId);
    }

    public function __construct(
        public readonly int $domainId,
        public readonly string $source,
        public readonly ?string $correlationId = null
    ) {
        if ($domainId < 1) {
            throw new \InvalidArgumentException('domainId must be a positive integer.');
        }
        if (!in_array($source, self::sources(), true)) {
            throw new \InvalidArgumentException('Unsupported site mutation source: ' . $source);
        }
        if ($correlationId !== null && ($correlationId === '' || strlen($correlationId) > 191)) {
            throw new \InvalidArgumentException('correlationId must contain 1 to 191 ASCII characters.');
        }
        if ($correlationId !== null && preg_match('/[^\x20-\x7E]/', $correlationId) === 1) {
            throw new \InvalidArgumentException('correlationId must contain printable ASCII characters only.');
        }
    }

    public function changed(string $resourceId, string $resourceType, string $fingerprint): self
    {
        $resourceId = trim($resourceId);
        $resourceType = trim($resourceType);
        $fingerprint = strtolower(trim($fingerprint));

        if ($resourceId === '' || strlen($resourceId) > 191 || preg_match('/[^\x20-\x7E]/', $resourceId) === 1) {
            throw new \InvalidArgumentException('resourceId must contain 1 to 191 printable ASCII characters.');
        }
        if ($resourceType === '' || strlen($resourceType) > 50 || preg_match('/[^a-z0-9_.-]/', $resourceType) === 1) {
            throw new \InvalidArgumentException('resourceType must use lowercase ASCII letters, numbers, dot, dash, or underscore.');
        }
        if (preg_match('/^[a-f0-9]{64}$/', $fingerprint) !== 1) {
            throw new \InvalidArgumentException('fingerprint must be a SHA-256 hex digest.');
        }

        $this->resources[$resourceId] = new SiteMutationResource($resourceId, $resourceType, $fingerprint);
        ksort($this->resources, SORT_STRING);

        return $this;
    }

    /** @return list<SiteMutationResource> */
    public function resources(): array
    {
        return array_values($this->resources);
    }

    public function isEmpty(): bool
    {
        return $this->resources === [];
    }

    /** @return list<string> */
    public static function sources(): array
    {
        return [self::SOURCE_ADMIN, self::SOURCE_AUTOMATION, self::SOURCE_API, self::SOURCE_EXTENSION];
    }
}
