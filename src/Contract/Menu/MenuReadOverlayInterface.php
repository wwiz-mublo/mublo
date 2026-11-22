<?php
declare(strict_types=1);

namespace Mublo\Contract\Menu;

use Mublo\Core\Result\Result;

/**
 * 운영 DB를 바꾸지 않고 한 요청의 최종 메뉴 트리를 덧씌우는 계약.
 *
 * null은 overlay 미등록, 빈 배열은 메뉴가 없는 미리보기를 뜻한다.
 * 장기 실행 프로세스에서는 응답이 끝날 때 clear()를 반드시 호출해야 한다.
 */
interface MenuReadOverlayInterface
{
    /** @param list<MenuTreeEntry> $entries */
    public function replaceTree(int $domainId, array $entries): Result;

    /** @return list<MenuTreeEntry>|null */
    public function tree(int $domainId, bool $activeOnly = true): ?array;

    public function clear(): void;
}
