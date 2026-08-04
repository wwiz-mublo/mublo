<?php
declare(strict_types=1);

namespace Mublo\Contract\Site;

use Mublo\Core\Result\Result;

/** Stable Core boundary used by packages that provision managed child Domains. */
interface ManagedSiteGatewayInterface
{
    /** @return array<int, string> level value => label */
    public function operatorLevelOptions(): array;

    /** Success data contains member_id. */
    public function prepareOwner(ManagedSiteOwnerRequest $request): Result;

    public function findByDomain(string $domain): ?ManagedSite;

    public function findById(int $domainId): ?ManagedSite;

    /** Success data contains domain_id. */
    public function createChildSite(
        int $operatorDomainId,
        string $operatorDomainGroup,
        int $actorMemberId,
        int $ownerMemberId,
        string $domain,
        string $siteTitle,
        string $status = 'inactive',
    ): Result;

    public function enablePackage(int $domainId, string $package): Result;

    /**
     * 도메인에 플러그인을 멱등 활성화한다.
     *
     * enablePackage() 는 extension_config.packages 만 갱신하므로 플러그인에는
     * 쓸 수 없다. 이미 활성이면 성공(no-op)이고, 설치되지 않은 플러그인은 실패한다.
     */
    public function enablePlugin(int $domainId, string $plugin): Result;

    public function changeStatus(int $domainId, string $status): Result;

    /** Success data contains token. */
    public function issueProxyToken(
        int $sourceDomainId,
        int $targetDomainId,
        int $actorMemberId,
        string $redirectUrl,
    ): Result;
}
