<?php

namespace Mublo\Packages\Shop\Helper;

/**
 * 기획전 프론트 상세 URL 빌더.
 *
 * slug가 있으면 slug 기반, 없으면 id 기반. 이벤트/메뉴 등록 등 여러 곳에서
 * 동일한 규칙을 쓰기 위한 단일 소스.
 */
class ExhibitionUrl
{
    public static function detail(int $exhibitionId, ?string $slug = null): string
    {
        return '/shop/exhibitions/' . ($slug !== null && $slug !== '' ? $slug : $exhibitionId);
    }
}
