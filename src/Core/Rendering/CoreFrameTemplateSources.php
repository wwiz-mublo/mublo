<?php

namespace Mublo\Core\Rendering;

/**
 * 코어 프레임 템플릿 소스
 *
 * 계획문서 §3.1 사양표(변수 14종 + 슬롯 9종)를 FrameTemplateRenderer에
 * 등록한다. 슬롯은 파일 스킨과 공유하는 공용 컴포넌트(views/Components/frame/*)를
 * 재사용한다 — 슬롯 출력 = 파일 스킨 출력, 별도 마크업 없음.
 *
 * $data는 FrontViewRenderer::collectCommonData()가 만드는 공통 데이터 배열이다.
 */
final class CoreFrameTemplateSources
{
    /**
     * 에디터 변수 팔레트용 코어 목록 (§3.1 사양표와 1:1)
     *
     * @return array<array{name: string, kind: string, label: string, source: string}>
     */
    public static function palette(): array
    {
        $variables = [
            'site_name' => '사이트명',
            'logo_url' => 'PC 로고 이미지 URL',
            'logo_mobile_url' => '모바일 로고 이미지 URL',
            'site_url' => '사이트 루트 URL',
            'year' => '현재 연도',
            'company.name' => '상호',
            'company.owner' => '대표자',
            'company.business_number' => '사업자등록번호',
            'company.tongsin_number' => '통신판매업 신고번호',
            'company.address' => '전체 주소',
            'company.tel' => '대표 전화',
            'company.fax' => '팩스',
            'company.email' => '대표 이메일',
            'company.privacy_officer' => '개인정보보호책임자',
        ];

        $slots = [
            'menu_main' => '메인 메뉴 (GNB)',
            'menu_utility' => '유틸리티 메뉴',
            'menu_footer' => '푸터 메뉴',
            'login_area' => '로그인/알림 영역',
            'search' => '통합 검색 폼',
            'sns' => 'SNS 채널 링크',
            'business_info' => '사업자 정보 블록',
            'cs_info' => '고객센터 블록',
            'theme_switch' => '테마 모드 스위치',
            'mobile_panel' => '모바일 패널 (토글 버튼과 한 쌍)',
        ];

        $palette = [];
        foreach ($variables as $name => $label) {
            $palette[] = ['name' => $name, 'kind' => 'variable', 'label' => $label, 'source' => 'core'];
        }
        foreach ($slots as $name => $label) {
            $palette[] = ['name' => $name, 'kind' => 'slot', 'label' => $label, 'source' => 'core'];
        }

        return $palette;
    }

    /**
     * @param FrameTemplateRenderer $renderer 등록 대상
     * @param array $data 프레임 공통 데이터 (예약 키 mublo)
     * @param ViewContext $view 컴포넌트 렌더용 뷰 컨텍스트
     */
    public static function apply(FrameTemplateRenderer $renderer, array $data, ViewContext $view): void
    {
        $mublo = $data['mublo'] ?? [];
        $site = $mublo['site'] ?? [];
        $viewer = $mublo['viewer'] ?? [];
        $navigation = $mublo['navigation'] ?? [];
        $siteConfig = $site['config'] ?? [];
        $siteImages = $site['images'] ?? [];
        $company = $site['company'] ?? [];

        // ---- 변수 (렌더러가 htmlspecialchars 이스케이프) ----

        $renderer->setVariable('site_name', (string) ($siteConfig['site_title'] ?? ''));
        $renderer->setVariable('logo_url', (string) ($siteImages['logo_pc'] ?? ''));
        $renderer->setVariable('logo_mobile_url', (string) ($siteImages['logo_mobile'] ?? ''));
        $renderer->setVariable('site_url', (string) ($site['url'] ?? '/'));
        $renderer->setVariable('year', fn(): string => date('Y'));

        $renderer->setVariable('company.name', (string) ($company['name'] ?? ''));
        $renderer->setVariable('company.owner', (string) ($company['owner'] ?? ''));
        $renderer->setVariable('company.business_number', (string) ($company['business_number'] ?? ''));
        $renderer->setVariable('company.tongsin_number', (string) ($company['tongsin_number'] ?? ''));
        $renderer->setVariable('company.address', self::fullAddress($company));
        $renderer->setVariable('company.tel', (string) ($company['tel'] ?? ''));
        $renderer->setVariable('company.fax', (string) ($company['fax'] ?? ''));
        $renderer->setVariable('company.email', (string) ($company['email'] ?? ''));
        $renderer->setVariable('company.privacy_officer', (string) ($company['privacy_officer'] ?? ''));

        // ---- 슬롯 (공용 컴포넌트 재사용, 지연 해석) ----

        $renderer->setSlot('menu_main', function () use ($navigation, $viewer, $view): string {
            $isLogin = !empty($viewer['authenticated']);
            $menus = self::filterMenuTree($navigation['menuTree'] ?? [], $isLogin);

            return $view->menu($menus, (string) ($navigation['currentMenuCode'] ?? ''));
        });

        $renderer->setSlot('menu_utility', fn(): string => $view->component('frame/menu_utility', [
            'utilityMenus' => $navigation['utilityMenus'] ?? [],
            'viewer' => $viewer,
        ]));

        $renderer->setSlot('menu_footer', fn(): string => $view->component('frame/menu_footer', [
            'footerMenus' => $navigation['footerMenus'] ?? [],
            'viewer' => $viewer,
        ]));

        $renderer->setSlot('login_area', fn(): string => $view->component('frame/login_area', [
            'viewer' => $viewer,
        ]));

        $renderer->setSlot('search', fn(): string => $view->component('frame/search'));

        $renderer->setSlot('sns', fn(): string => $view->component('frame/sns', [
            'seoConfig' => $site['seo'] ?? [],
        ]));

        $renderer->setSlot('business_info', fn(): string => $view->component('frame/business_info', [
            'companyConfig' => $company,
            'csInfo' => $site['customerService'] ?? [],
        ]));

        $renderer->setSlot('cs_info', fn(): string => $view->component('frame/cs_info', [
            'csInfo' => $site['customerService'] ?? [],
            'companyConfig' => $company,
        ]));

        $renderer->setSlot('theme_switch', fn(): string => $view->component('frame/theme_switch'));

        $renderer->setSlot('mobile_panel', function () use ($navigation, $viewer, $view): string {
            $isLogin = !empty($viewer['authenticated']);

            return $view->component('frame/mobile_panel', [
                'menus' => self::filterMenuTree($navigation['menuTree'] ?? [], $isLogin),
                'activeMenuCode' => $navigation['currentMenuCode'] ?? '',
                'utilityMenus' => $navigation['utilityMenus'] ?? [],
                'viewer' => $viewer,
            ]);
        });
    }

    /**
     * 전체 주소 조립 — basic Footer(business_info 컴포넌트)와 동일 규칙:
     * "(우편번호) 주소 상세주소", 빈 조각은 생략
     */
    private static function fullAddress(array $company): string
    {
        $parts = array_filter([
            !empty($company['zipcode']) ? '(' . $company['zipcode'] . ')' : '',
            $company['address'] ?? '',
            $company['address_detail'] ?? '',
        ]);

        return implode(' ', $parts);
    }

    /**
     * 메인 메뉴 트리 visibility(guest/member/all) 재귀 필터 —
     * basic Header.php의 필터와 동일 규칙
     */
    private static function filterMenuTree(array $items, bool $isLogin): array
    {
        $filtered = [];
        foreach ($items as $item) {
            $vis = $item['visibility'] ?? 'all';
            if ($vis === 'guest' && $isLogin) {
                continue;
            }
            if ($vis === 'member' && !$isLogin) {
                continue;
            }
            if (!empty($item['children'])) {
                $item['children'] = self::filterMenuTree($item['children'], $isLogin);
            }
            $filtered[] = $item;
        }

        return $filtered;
    }
}
