<?php
declare(strict_types=1);

namespace Mublo\Core\Rendering;

/**
 * 테마 스킨 이름과 뷰 디렉터리 경계를 한 곳에서 관리한다.
 *
 * 스킨 값은 설정 데이터이지만 최종적으로 파일 경로에 사용된다. 따라서 저장
 * 시에는 이름과 실제 디렉터리를 모두 검증하고, 과거에 저장된 오염 값은 렌더
 * 경계에서 basic으로 닫힌 형태로 폴백한다.
 */
final class ThemeSkinPolicy
{
    public const DEFAULT_SKIN = 'basic';

    /** @var array<string, string> */
    private const COMPONENT_DIRECTORIES = [
        'admin' => 'Admin/frame',
        'frame' => 'Front/frame',
        'index' => 'Front/Index',
        'member' => 'Front/Member',
        'auth' => 'Front/Auth',
        'mypage' => 'Front/Mypage',
        'policy' => 'Front/Policy',
        'search' => 'Front/Search',
    ];

    public static function isValidName(mixed $skin): bool
    {
        return is_string($skin)
            && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/D', $skin) === 1;
    }

    /**
     * SUPER 전용 스킨 관례 — `super_` 프리픽스
     *
     * `_` 프리픽스(스킨이 아닌 디렉터리 숨김 — `_assets`·`_shared`, 모든
     * 목록에서 제외)와는 **별개의 선언**이다: `super_` 스킨은 스킨이다.
     * SUPER 도메인의 목록에는 나타나고 선택할 수 있으며, SUPER 가 아닌 도메인의
     * 목록에서는 빠지고 저장이 거부된다.
     *
     * 이름이 곧 선언이라 메타데이터가 필요 없고, 폴더를 배포하면 제약이
     * 함께 이동한다. 테마 컴포넌트·블록 스킨·프레임 스킨 등 스킨 목록을
     * 만드는 모든 경계가 이 판정을 쓴다.
     *
     * DB 를 직접 만지는 운영자는 방어 대상이 아니다 — 그건 권한이다.
     */
    public static function isSuperOnly(mixed $skin): bool
    {
        return is_string($skin) && str_starts_with($skin, 'super_');
    }

    public static function normalizeName(mixed $skin): string
    {
        return self::isValidName($skin) ? (string) $skin : self::DEFAULT_SKIN;
    }

    public static function componentExists(string $component, mixed $skin, ?string $viewPath = null): bool
    {
        if (!isset(self::COMPONENT_DIRECTORIES[$component]) || !self::isValidName($skin)) {
            return false;
        }

        $validSkin = (string) $skin;
        $base = $viewPath ?? MUBLO_VIEW_PATH;
        $path = rtrim($base, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::COMPONENT_DIRECTORIES[$component])
            . DIRECTORY_SEPARATOR
            . $validSkin;

        return is_dir($path);
    }

    /**
     * 저장소의 기존 값은 신뢰하지 않고 안전하며 실제 존재하는 스킨만 반환한다.
     */
    public static function resolveExisting(string $component, mixed $skin, ?string $viewPath = null): string
    {
        if (self::componentExists($component, $skin, $viewPath)) {
            return (string) $skin;
        }

        if ($skin !== null && $skin !== '' && $skin !== self::DEFAULT_SKIN) {
            $encoded = json_encode($skin, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $display = is_string($encoded) ? substr($encoded, 0, 200) : get_debug_type($skin);
            error_log(sprintf(
                '[ThemeSkinPolicy] Invalid or missing %s skin rejected: %s',
                $component,
                $display
            ));
        }

        return self::DEFAULT_SKIN;
    }

    /** @return string[] */
    public static function components(): array
    {
        return array_keys(self::COMPONENT_DIRECTORIES);
    }
}
