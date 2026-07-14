<?php

namespace Mublo\Controller\Front;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\FileResponse;

/**
 * RobotsController
 *
 * /robots.txt 요청 처리
 *
 * 우선순위:
 * 1. 도메인 seo_config.robots_txt 값이 있으면 해당 내용 반환
 * 2. 없으면 public/robots.txt 파일 폴백
 * 3. 파일도 없으면 코드 내장 안전 기본값
 *
 * 3번이 중요하다. 파일 경로를 그대로 서빙하면 배포본에 남은 `Disallow: /` 한 줄이
 * 사이트 전체를 크롤링 차단하고, 내용을 후처리할 수도 없다. 파일은 읽어서 문자열로
 * 다루고, 없으면 안전한 기본값으로 떨어진다.
 *
 * 공통 후처리: 내용에 "Sitemap:" 라인이 없으면 현재 요청 호스트 기준으로 자동
 * 주입한다(멀티도메인이라 도메인마다 호스트가 다르다).
 */
class RobotsController
{
    public function index(Request $request, Context $context): FileResponse
    {
        $domainInfo = $context->getDomainInfo();
        $seoConfig  = $domainInfo?->getSeoConfig() ?? [];
        $robotsTxt  = trim($seoConfig['robots_txt'] ?? '');

        if ($robotsTxt !== '') {
            $content = $robotsTxt;
        } else {
            $fallback = @file_get_contents(MUBLO_PUBLIC_PATH . '/robots.txt');
            $content = $fallback !== false
                ? $fallback
                : implode("\n", [
                    'User-agent: Googlebot',
                    'Disallow:',
                    '',
                    'User-agent: Googlebot-Image',
                    'Disallow:',
                    '',
                    'User-agent: AdsBot-Google',
                    'Disallow:',
                    '',
                    'User-agent: *',
                    'Disallow: /admin/',
                    'Disallow: /api/',
                    'Disallow: /install/',
                    'Disallow: /auth/',
                    '',
                ]);
        }

        // Sitemap 라인 자동 주입 (이미 있으면 생략)
        if (stripos($content, 'Sitemap:') === false) {
            $baseUrl = $request->getScheme() . '://' . $request->getHost();
            $content = rtrim($content) . "\n\nSitemap: {$baseUrl}/sitemap.xml\n";
        }

        return new FileResponse(
            filePath: null,
            statusCode: 200,
            headers: ['Content-Type' => 'text/plain; charset=utf-8'],
            content: $content
        );
    }
}
