<?php
declare(strict_types=1);

namespace Mublo\Service\Domain;

use Mublo\Contract\Site\ManagedSite;
use Mublo\Contract\Site\ManagedSiteGatewayInterface;
use Mublo\Contract\Site\ManagedSiteOwnerRequest;
use Mublo\Core\Result\Result;
use Mublo\Entity\Member\Member;
use Mublo\Entity\Domain\Domain;
use Mublo\Repository\Member\MemberLevelRepository;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Service\Auth\ProxyLoginService;
use Mublo\Service\Extension\ExtensionService;
use Mublo\Service\Member\MemberAdminService;

final class ManagedSiteGateway implements ManagedSiteGatewayInterface
{
    public function __construct(
        private readonly DomainService $domains,
        private readonly ExtensionService $extensions,
        private readonly MemberRepository $members,
        private readonly MemberAdminService $memberAdmin,
        private readonly MemberLevelRepository $levels,
        private readonly ProxyLoginService $proxyLogin,
    ) {
    }

    public function operatorLevelOptions(): array
    {
        $options = [];
        foreach ($this->levels->getOperatorLevels() as $level) {
            if (!$level->isSuper() && $level->canAccessAdmin()) {
                $options[$level->getLevelValue()] = $level->getLevelName();
            }
        }
        return $options;
    }

    public function prepareOwner(ManagedSiteOwnerRequest $request): Result
    {
        if (($request->existingMemberId ?? 0) > 0) {
            $validation = $this->domains->validateDomainOwnerById(
                $request->operatorDomainId,
                (int) $request->existingMemberId,
                $request->operatorDomainGroup,
            );
            return $validation->isFailure()
                ? $validation
                : Result::success('Owner is ready.', ['member_id' => (int) $request->existingMemberId]);
        }

        $userId = trim((string) $request->userId);
        $existing = $this->findOwnerByOrigin($request->operatorDomainId, $userId);
        if ($existing instanceof Member) {
            return Result::success('Owner is ready.', ['member_id' => $existing->getMemberId()]);
        }
        if ($userId === '' || $request->password === null || $request->password === '' || $request->levelValue === null) {
            return Result::failure('New owner account information is incomplete.');
        }

        $created = $this->memberAdmin->register([
            'domain_id' => $request->operatorDomainId,
            'domain_group' => $request->operatorDomainGroup,
            'user_id' => $userId,
            'password' => $request->password,
            'nickname' => trim((string) $request->nickname),
            'level_value' => $request->levelValue,
            'status' => 'active',
            'admin_id' => $request->actorMemberId,
            'admin_is_super' => $request->actorIsSuper,
            'admin_level_value' => $request->actorLevelValue,
            'admin_domain_group' => $request->operatorDomainGroup,
        ]);
        if ($created->isFailure()) {
            return $created;
        }
        $memberId = (int) $created->get('member_id');
        $validation = $this->domains->validateDomainOwnerById(
            $request->operatorDomainId,
            $memberId,
            $request->operatorDomainGroup,
        );
        return $validation->isFailure()
            ? $validation
            : Result::success('Owner is ready.', ['member_id' => $memberId]);
    }

    public function findByDomain(string $domain): ?ManagedSite
    {
        return $this->map($this->domains->findByDomain($domain));
    }

    public function findById(int $domainId): ?ManagedSite
    {
        return $this->map($this->domains->findById($domainId));
    }

    public function createChildSite(
        int $operatorDomainId,
        string $operatorDomainGroup,
        int $actorMemberId,
        int $ownerMemberId,
        string $domain,
        string $siteTitle,
        string $status = 'inactive',
    ): Result {
        return $this->domains->create([
            'member_id' => $ownerMemberId,
            'domain' => $domain,
            'status' => $status,
            'site_title' => $siteTitle,
        ], $operatorDomainGroup, $actorMemberId, $operatorDomainId);
    }

    public function enablePackage(int $domainId, string $package): Result
    {
        $config = $this->extensions->getExtensionConfig($domainId);
        $config['packages'] = array_values(array_unique(array_merge($config['packages'] ?? [], [$package])));
        return $this->extensions->saveExtensionConfig($domainId, $config);
    }

    public function enablePlugin(int $domainId, string $plugin): Result
    {
        // togglePlugin(enabled: true) 은 존재 검사 후 이미 활성이면 목록을 그대로 둔다.
        return $this->extensions->togglePlugin($domainId, $plugin, true);
    }

    public function changeStatus(int $domainId, string $status): Result
    {
        return $this->domains->changeStatus($domainId, $status);
    }

    public function issueProxyToken(int $sourceDomainId, int $targetDomainId, int $actorMemberId, string $redirectUrl): Result
    {
        return $this->proxyLogin->generateToken($sourceDomainId, $targetDomainId, $actorMemberId, $redirectUrl);
    }

    private function findOwnerByOrigin(int $operatorDomainId, string $userId): ?Member
    {
        if ($userId === '') {
            return null;
        }
        $row = $this->members->getDb()->selectOne(
            'SELECT member_id FROM members WHERE origin_domain_id = ? AND user_id = ?',
            [$operatorDomainId, $userId],
        );
        return $row === null ? null : $this->members->find((int) $row['member_id']);
    }

    private function map(?Domain $domain): ?ManagedSite
    {
        if ($domain === null) {
            return null;
        }
        return new ManagedSite(
            $domain->getDomainId(),
            $domain->getDomain(),
            $domain->getDomainGroup(),
            $domain->getMemberId(),
            $domain->getStatus(),
        );
    }
}
