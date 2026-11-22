<?php
declare(strict_types=1);

namespace Mublo\Contract\Board;

use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Core\Result\Result;

/** 기존 게시판의 조회·명시적 수정·안전한 삭제를 위한 안정 계약. */
interface BoardManagementInterface
{
    /** @return list<BoardDescriptor> */
    public function listBoards(int $domainId): array;

    public function getBoard(int $domainId, int $boardId): ?BoardDescriptor;

    public function findBoardBySlug(int $domainId, string $slug): ?BoardDescriptor;

    /**
     * 허용된 게시판 설정만 부분 갱신한다. slug·domain·sort·시스템 ID는 수정할 수 없다.
     */
    public function updateBoard(
        int $domainId,
        int $boardId,
        array $values,
        ?SiteMutationContext $mutationContext = null
    ): Result;

    /** 게시글이 하나라도 있으면 삭제를 거절한다. */
    public function removeBoard(
        int $domainId,
        int $boardId,
        ?SiteMutationContext $mutationContext = null
    ): Result;
}
