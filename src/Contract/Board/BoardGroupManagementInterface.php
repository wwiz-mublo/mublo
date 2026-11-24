<?php
declare(strict_types=1);

namespace Mublo\Contract\Board;

use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Core\Result\Result;

/**
 * 기존 게시판 그룹을 도메인 소유권 안에서 안전하게 제거하는 안정 계약.
 *
 * 생성은 BoardProvisioningInterface::ensureGroup()을, 조회는
 * BoardGroupQueryInterface를 사용한다. 바깥 트랜잭션에 묶을 때는 같은
 * SiteMutationContext를 전달하며, 이 경우 구현체는 자원만 수집한다.
 */
interface BoardGroupManagementInterface
{
    /** 소속 게시판이 하나라도 있거나 다른 도메인의 그룹이면 제거를 거절한다. */
    public function removeGroup(
        int $domainId,
        int $groupId,
        ?SiteMutationContext $mutationContext = null
    ): Result;
}
