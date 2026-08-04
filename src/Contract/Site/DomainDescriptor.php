<?php
declare(strict_types=1);

namespace Mublo\Contract\Site;

/** 확장이 도메인 정책과 표시 정보를 읽는 데 필요한 최소 모델. */
final readonly class DomainDescriptor
{
    public function __construct(
        public int $domainId,
        public string $hostname,
        public string $domainGroup,
        public ?int $ownerMemberId,
        public string $status,
        public string $siteTitle,
        public array $companyConfig,
        public array $seoConfig
    ) {
    }
}
