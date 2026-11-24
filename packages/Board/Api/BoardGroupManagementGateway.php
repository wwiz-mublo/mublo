<?php
declare(strict_types=1);

namespace Mublo\Packages\Board\Api;

use Mublo\Contract\Board\BoardGroupManagementInterface;
use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Core\Result\Result;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Packages\Board\Service\BoardGroupService;

final class BoardGroupManagementGateway implements BoardGroupManagementInterface
{
    public function __construct(
        private readonly BoardGroupService $service,
        private readonly BoardGroupRepository $groups
    ) {
    }

    public function removeGroup(
        int $domainId,
        int $groupId,
        ?SiteMutationContext $mutationContext = null
    ): Result {
        if ($this->groups->findByIdForDomain($domainId, $groupId) === null) {
            return Result::failure('게시판 그룹을 찾을 수 없습니다.');
        }
        return $this->service->deleteGroup($groupId, $mutationContext);
    }
}
