<?php
declare(strict_types=1);

namespace Mublo\Helper\View;

use Mublo\Core\Context\Context;
use Mublo\Core\Rendering\ThemeSkinPolicy;

/**
 * 프론트 미리보기 토큰 조립기
 *
 * 블록 에디터 캔버스·미리보기 iframe 은 프론트의 토큰 캐스케이드
 * (tokens.css → 프레임 스킨 변수 rebind → 도메인 브랜드색)를 재현해야 한다.
 * 그 재현에 필요한 두 값을 도메인 설정에서 뽑아 뷰에 넘길 형태로 만든다.
 *
 * 뷰가 window.MubloFrontPreviewTokens 로 찍고,
 * /assets/js/admin/front-preview-tokens.js (MubloFrontPreviewCss) 가 읽는다.
 *
 * frameCssUrl 은 완성된 URL 로 돌려준다 — 뷰가 경로 조립·파일시스템 접근을
 * 하지 않게 하고, 이 로직이 소비 화면마다 복제되지 않게 하기 위함.
 */
class FrontPreviewTokensHelper
{
    /**
     * @return array{primaryColor: string, frameCssUrl: string}
     */
    public static function forContext(Context $context): array
    {
        $domainInfo = $context->getDomainInfo();

        $primaryColor = (string) ($domainInfo?->getSiteConfig()['primary_color'] ?? '');

        // admin 요청은 ContextBuilder 가 frame 스킨을 세팅하지 않아 theme_config 에서 직접 읽는다.
        $frameSkin = ThemeSkinPolicy::resolveExisting(
            'frame',
            $domainInfo?->getThemeConfig()['frame'] ?? null
        );

        $cssFile = MUBLO_VIEW_PATH . "/Front/frame/{$frameSkin}/_assets/css/front.css";
        $cssUrl = '/serve/front/' . rawurlencode($frameSkin) . '/css/front.css'
            . (file_exists($cssFile) ? '?' . filemtime($cssFile) : '');

        return [
            'primaryColor' => $primaryColor,
            'frameCssUrl' => $cssUrl,
        ];
    }
}
