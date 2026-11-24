<?php
declare(strict_types=1);

namespace Mublo\Contract\Block;

use Mublo\Core\Result\Result;

/**
 * 운영 DB를 바꾸지 않고 한 요청의 최종 블록 행 목록을 덧씌우는 계약.
 *
 * 플러그인은 change set을 최종 BlockRowSnapshot 목록으로 계산해 등록하고,
 * 프런트 렌더러는 해당 scope에서 DB·HTML 캐시 대신 이 목록을 사용한다.
 * 장기 실행 프로세스에서는 응답이 끝날 때 clear()를 반드시 호출해야 한다.
 */
interface BlockReadOverlayInterface
{
    /** @param list<BlockRowSnapshot> $rows */
    public function replacePositionRows(
        int $domainId,
        string $position,
        ?string $menuCode,
        bool $mainScreen,
        array $rows
    ): Result;

    /** @param list<BlockRowSnapshot> $rows */
    public function replacePageRows(int $domainId, int $pageId, array $rows): Result;

    /** @return list<BlockRowSnapshot>|null null이면 이 scope에 overlay가 없음 */
    public function positionRows(
        int $domainId,
        string $position,
        ?string $menuCode,
        bool $mainScreen
    ): ?array;

    /** @return list<BlockRowSnapshot>|null null이면 이 page에 overlay가 없음 */
    public function pageRows(int $pageId): ?array;

    public function clear(): void;
}
