<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Helper;

/**
 * 가격 공개 대상 판정
 *
 * 쇼핑몰 설정의 price_display 로 "누구에게 가격을 보여줄지" 정한다.
 *  - ALL    : 모든 방문자 (기본값 — 기존 동작)
 *  - MEMBER : 로그인 회원만
 *  - LEVEL  : price_display_level(등급값) 이상 회원만
 *
 * 가격을 볼 수 없는 방문자에게는 가격 자리에 price_hidden_text 를 보여주고,
 * 장바구니·바로구매·주문도 막는다(가격을 모른 채 결제 화면에서 금액을 보게 되므로).
 *
 * 판정 입력은 등급값(levelValue)이다 — 비회원은 null.
 */
final class PriceVisibility
{
    public const ALL = 'ALL';
    public const MEMBER = 'MEMBER';
    public const LEVEL = 'LEVEL';

    public const DEFAULT_HIDDEN_TEXT = '회원인증시 가격 확인가능';

    /** @return array<string,string> 설정 화면 선택지 */
    public static function options(): array
    {
        return [
            self::ALL    => '모든 방문자',
            self::MEMBER => '로그인 회원만',
            self::LEVEL  => '지정 등급 이상 회원만',
        ];
    }

    /**
     * @param array $shopConfig 쇼핑몰 설정
     * @param int|null $levelValue 보고 있는 회원의 등급값 (비회원이면 null)
     */
    public static function allows(array $shopConfig, ?int $levelValue): bool
    {
        return match ((string) ($shopConfig['price_display'] ?? self::ALL)) {
            self::MEMBER => $levelValue !== null,
            self::LEVEL  => $levelValue !== null && $levelValue >= (int) ($shopConfig['price_display_level'] ?? 0),
            default      => true,
        };
    }

    /** 가격 대신 보여줄 문구 */
    public static function hiddenText(array $shopConfig): string
    {
        $text = trim((string) ($shopConfig['price_hidden_text'] ?? ''));

        return $text !== '' ? $text : self::DEFAULT_HIDDEN_TEXT;
    }
}
