<?php

namespace Mublo\Packages\Shop\Helper;

/**
 * 카테고리 메뉴 아이템 URL 빌더.
 *
 * 카테고리 코드는 불변이므로 메뉴 URL도 코드 기반으로 안정적이다.
 * 등록/동기화 여러 곳에서 동일 규칙을 쓰기 위한 단일 소스.
 */
class CategoryUrl
{
    /** 카테고리 메뉴 아이템 URL 프리픽스 */
    public const MENU_PREFIX = '/shop/category/';

    public static function menu(string $categoryCode): string
    {
        return self::MENU_PREFIX . $categoryCode;
    }
}
