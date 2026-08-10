<?php
declare(strict_types=1);

namespace Mublo\Contract\Tracking;

/**
 * 전환 소스 타입 상수 — 오타·표기 불일치 방지.
 *
 * **코어 자신의 개념만 등재한다.** 확장이 발행하는 타입은 그 확장이 소유하며 여기에
 * 등재하지 않는다 — 코어가 확장 목록을 들고 있으면 그 확장이 없는 설치본에서 죽은
 * 이름이 되고, 확장이 이름을 바꿔도 코어는 알 길이 없다.
 *
 * 등재는 강제가 아니다. `label()` 이 미등재 타입을 그대로 돌려주므로 어떤 문자열이든
 * 집계된다. 표시 이름은 발행 쪽이 `ConversionRecordedEvent::$sourceLabel` 에 실어
 * 보내는 값이 우선하며(폼 제목·상품군처럼 한 타입 안의 갈래를 구분할 수 있다),
 * 그게 없을 때만 아래 라벨로 떨어진다. 확장은 자기 라벨을 직접 실어 보내면 된다.
 */
class ConversionSourceTypes
{
    const MEMBER_SIGNUP       = 'member_signup';

    /**
     * 관리자 화면 라벨 (한글 표시용)
     */
    public static function label(string $type): string
    {
        return match ($type) {
            self::MEMBER_SIGNUP       => '회원가입',
            default                   => $type,
        };
    }
}
