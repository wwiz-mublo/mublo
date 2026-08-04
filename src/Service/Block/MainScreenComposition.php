<?php
declare(strict_types=1);
namespace Mublo\Service\Block;

/**
 * MainScreenComposition
 *
 * 방문자가 메인화면을 볼 때 조립되는 위치 슬롯의 목록과 순서를 계산한다.
 *
 * 이 규칙은 FrontViewRenderer::render()(실제 조립부)를 반영한 사본이며, 지금까지
 * BlockRowController::mainScreenSlots()("메인화면 구성" 필터)에 중복되어 있었다.
 * 블록 에디터가 세 번째 호출처가 되면서 한 곳으로 모았다(블록 에디터 설계 10.1).
 * 프론트 조립이 바뀌면 여기만 고치면 된다.
 *
 *   - use_main_layout 이 꺼져 있으면 메인화면은 layout_type=1(전체폭)로 강제된다
 *     → 사이드바 없음. 켜져 있으면 사이트 layout_type 을 따른다.
 *   - left 는 layout_type 2·4, right 는 3·4 에서만 조립된다.
 *   - topbar 는 헤더 '위'(최상단), subhead/subfoot 은 헤더/푸터가 있는 일반 화면
 *     전제로 항상 포함한다.
 */
class MainScreenComposition
{
    /**
     * 메인화면이 조립하는 슬롯 목록을 위→아래 순서로 반환한다.
     *
     * @param array<string, mixed> $siteConfig
     * @return string[]
     */
    public function slots(array $siteConfig): array
    {
        $layoutType = $this->resolveMainLayoutType($siteConfig);

        $slots = ['topbar', 'subhead'];
        if ($layoutType === 2 || $layoutType === 4) {
            $slots[] = 'left';
        }
        $slots[] = 'contenthead';
        $slots[] = 'index';
        $slots[] = 'contentfoot';
        if ($layoutType === 3 || $layoutType === 4) {
            $slots[] = 'right';
        }
        $slots[] = 'subfoot';

        return $slots;
    }

    /**
     * 특정 위치가 현재 설정의 메인화면에서 렌더되는지.
     */
    public function rendersOnMain(string $position, array $siteConfig): bool
    {
        return in_array($position, $this->slots($siteConfig), true);
    }

    /**
     * 서브페이지(메뉴 페이지)가 조립하는 슬롯 목록을 위→아래 순서로 반환한다.
     *
     * 메인화면과 달리 use_main_layout 강제가 없다 — 사이트 layout_type 을 그대로
     * 따른다(FrontViewRenderer::render() 의 일반 화면 조립부). index 는 메인 전용
     * 슬롯이므로 포함되지 않는다.
     *
     * @param array<string, mixed> $siteConfig
     * @return string[]
     */
    public function subSlots(array $siteConfig): array
    {
        $layoutType = $this->parseLayoutType($siteConfig['layout_type'] ?? 'full');

        $slots = ['topbar', 'subhead'];
        if ($layoutType === 2 || $layoutType === 4) {
            $slots[] = 'left';
        }
        $slots[] = 'contenthead';
        $slots[] = 'contentfoot';
        if ($layoutType === 3 || $layoutType === 4) {
            $slots[] = 'right';
        }
        $slots[] = 'subfoot';

        return $slots;
    }

    /**
     * 메인화면에 적용되는 레이아웃 타입 (use_main_layout 강제 규칙 포함).
     *
     * @param array<string, mixed> $siteConfig
     */
    public function resolveMainLayoutType(array $siteConfig): int
    {
        if (empty($siteConfig['use_main_layout'])) {
            return 1;
        }

        return $this->parseLayoutType($siteConfig['layout_type'] ?? 'full');
    }

    /**
     * 레이아웃 타입 문자열 → 정수. LayoutManager::parseLayoutType() 과 같은 규칙이다.
     */
    public function parseLayoutType(string|int $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return match ($value) {
            'left-sidebar'  => 2,
            'right-sidebar' => 3,
            'three-column'  => 4,
            default         => 1,
        };
    }
}
