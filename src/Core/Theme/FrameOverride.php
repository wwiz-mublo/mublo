<?php
declare(strict_types=1);

namespace Mublo\Core\Theme;

/**
 * 패키지 프레임 스킨 오버라이드 — theme_config 내 전용 버킷의 read/write 규격.
 *
 * - 코어 프레임 선택(theme_config['frame'])은 건드리지 않는다.
 * - 별도 버킷 theme_config['frame_overrides']['packages'][{pkg}] 만 다룬다.
 *   (네임스페이스 'packages' 는 extension_config 의 plugins/packages 복수형 컨벤션과 일치)
 * - 저장은 dumb(JSON 값만), 해석/적용은 렌더 배선(Context.frameBasePath + FrontViewRenderer)이 담당.
 *
 * 값 규약: 문자열 스킨명 = 그 패키지 프레임 사용 / null·미설정 = 코어 프레임.
 */
final class FrameOverride
{
    /** theme_config 내 버킷 키 */
    public const BUCKET = 'frame_overrides';

    /**
     * 패키지에 선택된 프레임 스킨명 (미설정/빈값이면 null = 코어 프레임).
     */
    public static function resolve(array $themeConfig, string $package): ?string
    {
        $skin = $themeConfig[self::BUCKET]['packages'][$package] ?? null;

        return (is_string($skin) && $skin !== '') ? $skin : null;
    }

    /**
     * 선택값을 병합해 갱신된 theme_config 를 반환한다 (다른 키 보존).
     * null 또는 빈 문자열이면 해당 오버라이드 제거(= 코어 프레임으로 복귀).
     */
    public static function apply(array $themeConfig, string $package, ?string $skin): array
    {
        if ($skin === null || $skin === '') {
            unset($themeConfig[self::BUCKET]['packages'][$package]);
        } else {
            $themeConfig[self::BUCKET]['packages'][$package] = $skin;
        }

        return $themeConfig;
    }
}
