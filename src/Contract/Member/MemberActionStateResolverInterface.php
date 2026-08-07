<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

interface MemberActionStateResolverInterface
{
    /**
     * @param list<int> $targetMemberIds
     * @return array<string, array<int, string>> local action key => member id => variant key
     */
    public function resolve(MemberActionStateScope $scope, array $targetMemberIds): array;
}
