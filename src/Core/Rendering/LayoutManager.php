<?php
namespace Mublo\Core\Rendering;

use Mublo\Core\Context\Context;

/**
 * Class LayoutManager
 *
 * ============================================================
 * LayoutManager – Body 레이아웃 전담 관리자
 * ============================================================
 *
 * LayoutManager 는 Mublo 프레임워크에서
 * "페이지 body 영역의 레이아웃 구조를 생성하는 역할"만을 담당한다.
 *
 * 이 클래스는 화면을 조립하지 않으며,
 * Header / Footer / Content 의 출력 순서를 결정하지 않는다.
 *
 * ------------------------------------------------------------
 * [이 클래스의 역할]
 * ------------------------------------------------------------
 *
 * - body 영역의 레이아웃 타입을 결정한다.
 *   (예: 좌측 사이드바 + 본문 / 본문 단독 / 양측 사이드바 등)
 *
 * - layout 스킨을 선택하고,
 *   layout wrapper HTML 을 생성한다.
 *
 * - layout 출력에 필요한 데이터만을 구성하여 반환한다.
 *
 * ------------------------------------------------------------
 * [출력 구조 개념]
 * ------------------------------------------------------------
 *
 * LayoutManager 가 담당하는 영역은 다음과 같다.
 *
 *   [Layout Wrapper Open]
 *       └─ (Content 는 외부에서 삽입됨)
 *   [Layout Wrapper Close]
 *
 * Content View 는
 * LayoutManager 외부(Front/AdminViewRenderer)에서
 * 출력된다.
 *
 * ------------------------------------------------------------
 * [Front / Admin Renderer 와의 관계]
 * ------------------------------------------------------------
 *
 * - LayoutManager 는 FrontViewRenderer / AdminViewRenderer 에 의해
 *   호출되는 "도구 클래스"이다.
 *
 * - 페이지 조립의 주도권은
 *   항상 FrontViewRenderer / AdminViewRenderer 에 있다.
 *
 * ------------------------------------------------------------
 * [Context 와의 관계]
 * ------------------------------------------------------------
 *
 * - Context 는 레이아웃 결정에 필요한 정보만 참조할 수 있다.
 *   (예: 페이지 위치, 디바이스 타입 등)
 *
 * - Context 를 수정하거나
 *   출력 흐름을 판단하지 않는다.
 *
 * ------------------------------------------------------------
 * [금지 사항]
 * ------------------------------------------------------------
 *
 * LayoutManager 는 다음을 절대 하지 않는다.
 *
 * - 페이지 전체 조립
 * - Header / Footer 렌더링
 * - ViewResponse 해석
 * - 비즈니스 로직 처리
 * 단, 레이아웃 결정에 필요한 데이터 구조를
 * 생성·제공하는 것은 LayoutManager의 책임에 포함된다
 * ------------------------------------------------------------
 * 이 클래스는
 * "페이지의 중앙 무대 세트(stage)"를 만드는 역할이며,
 * 무대 위에서 무엇이 연출되는지는
 * 다른 렌더러의 책임이다.
 * ------------------------------------------------------------
 */
class LayoutManager
{
    /**
     * 레이아웃 결정
     *
     * @param Context $context 요청 컨텍스트
     * @param array $pageConfig 페이지별 레이아웃 오버라이드 (BlockPage 등)
     * @return array ['type' => string, 'data' => array]
     */
    public function resolve(Context $context, array $pageConfig = []): array
    {
        $siteConfig = $context->getDomainInfo()?->getSiteConfig() ?? [];

        // 페이지별 설정이 있으면 우선 적용 (BlockPage 등)
        if (isset($pageConfig['layout_type'])) {
            $layoutType = (int) $pageConfig['layout_type'];
        } else {
            $layoutType = $this->parseLayoutType($siteConfig['layout_type'] ?? 'full');
        }

        $useFullpage = (int) ($pageConfig['use_fullpage'] ?? 0);
        $customWidth = (int) ($pageConfig['custom_width'] ?? 0);

        // 사이드바 너비: 페이지별 설정 > 사이트 설정 (0이면 사이트 기본값)
        $sidebarLeftWidth = (int) ($pageConfig['sidebar_left_width'] ?? 0)
            ?: (int) ($siteConfig['layout_left_width'] ?? 250);
        $sidebarRightWidth = (int) ($pageConfig['sidebar_right_width'] ?? 0)
            ?: (int) ($siteConfig['layout_right_width'] ?? 250);

        // 사이드바 모바일 출력: 페이지별 설정이 있으면 우선, 없으면 사이트 설정
        $sidebarLeftMobile = isset($pageConfig['sidebar_left_mobile'])
            ? !empty($pageConfig['sidebar_left_mobile'])
            : !empty($siteConfig['sidebar_left_mobile']);
        $sidebarRightMobile = isset($pageConfig['sidebar_right_mobile'])
            ? !empty($pageConfig['sidebar_right_mobile'])
            : !empty($siteConfig['sidebar_right_mobile']);

        return [
            'type' => $this->getTypeName($layoutType),
            'data' => [
                'layoutType' => $layoutType,
                'useFullpage' => $useFullpage,
                'customWidth' => $customWidth,
                'sidebarLeftWidth' => $sidebarLeftWidth,
                'sidebarRightWidth' => $sidebarRightWidth,
                'sidebarLeftMobile' => $sidebarLeftMobile,
                'sidebarRightMobile' => $sidebarRightMobile,
            ],
        ];
    }

    /**
     * 레이아웃 타입 문자열 → integer 변환
     * (siteConfig에는 문자열로 저장됨)
     */
    private function parseLayoutType(string|int $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        return match ($value) {
            'left-sidebar'   => 2,
            'right-sidebar'  => 3,
            'three-column'   => 4,
            default          => 1,
        };
    }

    /**
     * 레이아웃 타입명 반환
     */
    private function getTypeName(int $layoutType): string
    {
        return match ($layoutType) {
            2 => 'left-sidebar',
            3 => 'right-sidebar',
            4 => 'both-sidebar',
            default => 'full',
        };
    }
}
