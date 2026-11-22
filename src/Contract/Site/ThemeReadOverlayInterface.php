<?php
declare(strict_types=1);

namespace Mublo\Contract\Site;

use Mublo\Core\Result\Result;

/** 운영 DB를 바꾸지 않고 한 요청의 최종 사이트·테마 설정을 덧씌우는 계약. */
interface ThemeReadOverlayInterface
{
    public function replace(ThemeReadSnapshot $snapshot): Result;

    public function snapshot(int $domainId): ?ThemeReadSnapshot;

    public function clear(): void;
}
