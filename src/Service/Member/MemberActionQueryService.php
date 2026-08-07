<?php
declare(strict_types=1);

namespace Mublo\Service\Member;

use Mublo\Contract\Member\MemberActionDefinition;
use Mublo\Contract\Member\MemberActionQueryInterface;
use Mublo\Contract\Member\MemberActionScope;
use Mublo\Contract\Member\MemberActionStateScope;
use Mublo\Contract\Member\MemberActionVariant;
use Mublo\Contract\Member\MemberActionView;

/** 공통 노출 정책과 상태 해석을 적용하는 회원 액션 조회 구현. */
final class MemberActionQueryService implements MemberActionQueryInterface
{
    /**
     * source scope cache:
     * domain/viewer/source/member => {sourceFailure, values, failures}
     *
     * @var array<string, array{sourceFailure:bool,values:array<string,string>,failures:array<string,bool>}>
     */
    private array $stateCache = [];

    /** @var callable():int|null */
    private $currentDomainId;

    public function __construct(
        private MemberActionRegistry $registry,
        callable $currentDomainId,
    ) {
        $this->currentDomainId = $currentDomainId;
    }

    public function forMember(MemberActionScope $scope, int $targetMemberId): array
    {
        return $this->forMembers($scope, [$targetMemberId])[$targetMemberId] ?? [];
    }

    public function forMembers(MemberActionScope $scope, array $targetMemberIds): array
    {
        $targetMemberIds = array_values(array_unique(array_filter(
            array_map('intval', $targetMemberIds),
            static fn (int $memberId): bool => $memberId > 0
        )));
        $result = array_fill_keys($targetMemberIds, []);
        if ($targetMemberIds === []) {
            return [];
        }

        $currentDomainId = ($this->currentDomainId)();
        if (!is_int($currentDomainId) || $currentDomainId <= 0 || $currentDomainId !== $scope->domainId) {
            return $result;
        }

        $collection = $this->registry->collect($scope->domainId);
        $eligible = [];
        $statefulBySource = [];

        foreach ($collection['definitions'] as $entry) {
            $definition = $entry['definition'];
            if (!$this->isEligible($definition, $scope)) {
                continue;
            }
            $eligible[] = $entry;
            if ($definition->stateful) {
                $statefulBySource[$entry['sourceId']] = true;
            }
        }

        foreach (array_keys($statefulBySource) as $sourceId) {
            $this->resolveSource(
                $scope,
                $sourceId,
                $targetMemberIds,
                $collection['definitions'],
                $collection['resolvers'][$sourceId] ?? null
            );
        }

        foreach ($targetMemberIds as $targetMemberId) {
            foreach ($eligible as $entry) {
                $definition = $entry['definition'];
                if (!$definition->allowSelf && $scope->viewerMemberId === $targetMemberId) {
                    continue;
                }

                $variantKey = MemberActionVariant::DEFAULT;
                if ($definition->stateful) {
                    $state = $this->stateCache[$this->cacheKey($scope, $entry['sourceId'], $targetMemberId)]
                        ?? ['sourceFailure' => true, 'values' => [], 'failures' => []];
                    if ($state['sourceFailure'] || isset($state['failures'][$definition->key])) {
                        $variantKey = $definition->onResolveFailure;
                    } else {
                        $variantKey = $state['values'][$definition->key] ?? MemberActionVariant::DEFAULT;
                    }
                }

                if ($variantKey === MemberActionVariant::HIDDEN) {
                    continue;
                }

                $label = $definition->label;
                $icon = $definition->icon;
                $endpoint = $definition->endpoint;
                if ($variantKey !== MemberActionVariant::DEFAULT) {
                    $variant = $definition->variants[$variantKey] ?? null;
                    if (!$variant instanceof MemberActionVariant) {
                        if ($definition->onResolveFailure === MemberActionVariant::HIDDEN) {
                            continue;
                        }
                    } else {
                        $label = $variant->label;
                        $icon = $variant->icon;
                        $endpoint = $variant->endpoint;
                    }
                }

                $result[$targetMemberId][] = new MemberActionView(
                    $entry['id'],
                    $label,
                    $icon,
                    $endpoint,
                    $definition->targetTransport,
                );
            }
        }

        return $result;
    }

    private function isEligible(MemberActionDefinition $definition, MemberActionScope $scope): bool
    {
        if ($definition->requiresLogin && $scope->viewerMemberId === null) {
            return false;
        }
        if (!$definition->allowProxyLogin && $scope->proxyLogin) {
            return false;
        }
        if ($scope->viewerLevel < $definition->minViewerLevel) {
            return false;
        }
        if ($definition->placements !== []
            && array_intersect($scope->placements, $definition->placements) === []
        ) {
            return false;
        }
        return true;
    }

    /**
     * @param list<int> $targetMemberIds
     * @param array<string,array{id:string,sourceId:string,sourceType:string,sourceName:string,definition:MemberActionDefinition}> $definitions
     */
    private function resolveSource(
        MemberActionScope $scope,
        string $sourceId,
        array $targetMemberIds,
        array $definitions,
        mixed $resolver,
    ): void {
        $missing = [];
        foreach ($targetMemberIds as $targetMemberId) {
            if (!isset($this->stateCache[$this->cacheKey($scope, $sourceId, $targetMemberId)])) {
                $missing[] = $targetMemberId;
            }
        }
        if ($missing === []) {
            return;
        }

        $sourceDefinitions = [];
        foreach ($definitions as $entry) {
            if ($entry['sourceId'] === $sourceId) {
                $sourceDefinitions[$entry['definition']->key] = $entry['definition'];
            }
        }

        foreach ($missing as $targetMemberId) {
            $this->stateCache[$this->cacheKey($scope, $sourceId, $targetMemberId)] = [
                'sourceFailure' => false,
                'values' => [],
                'failures' => [],
            ];
        }

        if (!$resolver instanceof \Mublo\Contract\Member\MemberActionStateResolverInterface) {
            foreach ($missing as $targetMemberId) {
                $this->stateCache[$this->cacheKey($scope, $sourceId, $targetMemberId)]['sourceFailure'] = true;
            }
            return;
        }

        try {
            $resolved = $resolver->resolve(
                new MemberActionStateScope($scope->domainId, $scope->viewerMemberId),
                $missing
            );
        } catch (\Error $error) {
            throw $error;
        } catch (\Throwable $throwable) {
            foreach ($missing as $targetMemberId) {
                $this->stateCache[$this->cacheKey($scope, $sourceId, $targetMemberId)]['sourceFailure'] = true;
            }
            $this->registry->diagnoseRuntime('resolver exception', [
                'sourceId' => $sourceId,
                'exception' => $throwable::class,
            ]);
            return;
        }

        $requested = array_fill_keys($missing, true);
        foreach ($resolved as $actionKey => $states) {
            $definition = is_string($actionKey) ? ($sourceDefinitions[$actionKey] ?? null) : null;
            if (!$definition instanceof MemberActionDefinition || !$definition->stateful || !is_array($states)) {
                $this->registry->diagnoseRuntime('invalid resolver action key or state map', ['sourceId' => $sourceId]);
                continue;
            }

            foreach ($states as $targetMemberId => $variantKey) {
                $targetMemberId = is_int($targetMemberId) ? $targetMemberId : (ctype_digit((string) $targetMemberId) ? (int) $targetMemberId : 0);
                if (!isset($requested[$targetMemberId])) {
                    $this->registry->diagnoseRuntime('resolver returned an unrequested member', ['sourceId' => $sourceId]);
                    continue;
                }
                if (!is_string($variantKey)) {
                    $this->registry->diagnoseRuntime('resolver variant is not a string', ['sourceId' => $sourceId, 'actionKey' => $actionKey]);
                    continue;
                }
                if ($variantKey !== MemberActionVariant::DEFAULT
                    && $variantKey !== MemberActionVariant::HIDDEN
                    && !isset($definition->variants[$variantKey])
                ) {
                    $this->stateCache[$this->cacheKey($scope, $sourceId, $targetMemberId)]['failures'][$actionKey] = true;
                    $this->registry->diagnoseRuntime('resolver returned an unknown variant', ['sourceId' => $sourceId, 'actionKey' => $actionKey]);
                    continue;
                }

                $this->stateCache[$this->cacheKey($scope, $sourceId, $targetMemberId)]['values'][$actionKey] = $variantKey;
            }
        }
    }

    private function cacheKey(MemberActionScope $scope, string $sourceId, int $targetMemberId): string
    {
        return $scope->domainId . ':' . ($scope->viewerMemberId ?? 0) . ':' . $sourceId . ':' . $targetMemberId;
    }
}
