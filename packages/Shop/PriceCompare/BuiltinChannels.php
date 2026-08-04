<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\PriceCompare;

use Mublo\Packages\Shop\Contract\PriceCompare\PriceCompareChannelInterface;
use Mublo\Packages\Shop\PriceCompare\Channel\GoogleShopping;
use Mublo\Packages\Shop\PriceCompare\Channel\KakaoShoppingHow;
use Mublo\Packages\Shop\PriceCompare\Channel\NaverShopping;

/**
 * Shop 이 기본 제공하는 채널 목록
 *
 * 두 곳이 이 목록을 본다 — 부팅(등록)과 관리자 화면(사용 여부 편집). 목록이 두 벌이
 * 되면 등록은 됐는데 화면에 없는 채널이 생긴다.
 *
 * 외부 확장이 등록한 채널은 여기 없다. 그쪽은 자기 설정을 자기가 관리하므로
 * 관리자 화면에서도 사용 여부를 손대지 않는다(레지스트리에서 읽어 표시만 한다).
 */
final class BuiltinChannels
{
    /** @return list<PriceCompareChannelInterface> */
    public static function all(): array
    {
        return [
            new NaverShopping(),
            new KakaoShoppingHow(),
            new GoogleShopping(),
        ];
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_map(
            static fn(PriceCompareChannelInterface $channel): string => $channel->code(),
            self::all()
        );
    }
}
