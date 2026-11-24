<?php
declare(strict_types=1);

namespace Mublo\Contract\Menu;

use Mublo\Contract\Site\SiteMutationContext;

/**
 * 코어 메뉴 서비스를 거치지 않고 메뉴 항목을 만든 확장(설치 시드 등)이, 코어와 같은 지문 규칙으로
 * 그 항목을 사이트 변경 문맥에 담는 계약.
 *
 * 문맥을 기록하는 것은 호출자다(같은 트랜잭션 안에서 `SiteMutationRecorderInterface::record()`).
 */
interface MenuRevisionCollectorInterface
{
    /** @param list<string> $menuCodes 새로 만들거나 바꾼 메뉴 코드 */
    public function collectMenuItems(SiteMutationContext $context, array $menuCodes): void;
}
