<?php
declare(strict_types=1);

namespace Mublo\Core\Event\Member;

use Mublo\Contract\Member\MemberActionDefinition;
use Mublo\Contract\Member\MemberActionStateResolverInterface;
use Mublo\Core\Event\AbstractEvent;

/** 활성 확장이 회원 액션 정의와 상태 해석기를 등록하는 이벤트. */
final class MemberActionBuildingEvent extends AbstractEvent
{
    private string $sourceType = 'core';
    private string $sourceName = '';

    /** @var list<array{sourceType:string, sourceName:string, definition:MemberActionDefinition}> */
    private array $definitions = [];

    /** @var list<array{sourceType:string, sourceName:string, resolver:MemberActionStateResolverInterface}> */
    private array $resolvers = [];

    public function __construct(private readonly int $domainId)
    {
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function setSource(string $sourceType, string $sourceName = ''): self
    {
        $this->sourceType = $sourceType;
        $this->sourceName = $sourceName;
        return $this;
    }

    public function register(MemberActionDefinition $definition): self
    {
        $this->definitions[] = [
            'sourceType' => $this->sourceType,
            'sourceName' => $this->sourceName,
            'definition' => $definition,
        ];
        return $this;
    }

    public function registerStateResolver(MemberActionStateResolverInterface $resolver): self
    {
        $this->resolvers[] = [
            'sourceType' => $this->sourceType,
            'sourceName' => $this->sourceName,
            'resolver' => $resolver,
        ];
        return $this;
    }

    /** @return list<array{sourceType:string, sourceName:string, definition:MemberActionDefinition}> */
    public function getDefinitions(): array
    {
        return $this->definitions;
    }

    /** @return list<array{sourceType:string, sourceName:string, resolver:MemberActionStateResolverInterface}> */
    public function getStateResolvers(): array
    {
        return $this->resolvers;
    }
}
