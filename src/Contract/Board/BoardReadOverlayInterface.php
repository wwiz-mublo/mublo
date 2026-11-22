<?php
declare(strict_types=1);

namespace Mublo\Contract\Board;

use Mublo\Core\Result\Result;

/** 운영 DB를 바꾸지 않고 한 요청의 최종 게시판 설정 목록을 덧씌우는 계약. */
interface BoardReadOverlayInterface
{
    /** @param list<BoardDescriptor> $boards */
    public function replaceBoards(int $domainId, array $boards): Result;

    /** @return list<BoardDescriptor>|null null이면 해당 도메인에 overlay가 없음 */
    public function boards(int $domainId, bool $activeOnly = false): ?array;

    public function findBySlug(int $domainId, string $slug): ?BoardDescriptor;

    /** 등록된 모든 도메인의 overlay에서 ID를 찾는다. */
    public function findById(int $boardId): ?BoardDescriptor;

    public function clear(): void;
}
