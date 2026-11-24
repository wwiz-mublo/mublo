<?php
declare(strict_types=1);

namespace Tests\Unit\Core\Context;

use Mublo\Contract\Site\ThemeReadOverlayInterface;
use Mublo\Contract\Site\ThemeReadSnapshot;
use Mublo\Core\Context\ContextBuilder;
use Mublo\Core\Http\Request;
use Mublo\Entity\Domain\Domain;
use Mublo\Service\Site\ThemeReadOverlay;
use PHPUnit\Framework\TestCase;

final class ContextBuilderThemeReadOverlayTest extends TestCase
{
    public function testBuildUsesOverlayConfigsWithoutMutatingResolvedDomain(): void
    {
        $stored = new Domain(
            7,
            'preview.test',
            siteConfig: ['site_title' => '운영 사이트'],
            companyConfig: ['name' => '운영 회사'],
            seoConfig: ['meta_title' => '운영 SEO'],
            themeConfig: ['frame' => 'basic', 'index' => 'basic']
        );
        $overlay = new ThemeReadOverlay();
        $result = $overlay->replace(new ThemeReadSnapshot(
            7,
            ['site_title' => '미리보기 사이트'],
            ['name' => '미리보기 회사'],
            ['meta_title' => '미리보기 SEO'],
            ['frame' => 'basic', 'index' => 'basic', 'frame_edit' => ['parts' => ['header']]]
        ));
        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $builder = new class($stored, $overlay) extends ContextBuilder {
            public function __construct(
                private Domain $resolvedDomain,
                ThemeReadOverlayInterface $overlay
            ) {
                parent::__construct(themeReadOverlay: $overlay);
            }

            protected function resolveDomain(?string $domainName): ?Domain
            {
                return $this->resolvedDomain;
            }
        };

        $context = $builder->build(new Request('GET', '/', server: ['HTTP_HOST' => 'preview.test']));
        $preview = $context->getDomainInfo();
        $this->assertNotNull($preview);
        $this->assertSame('미리보기 사이트', $preview->getSiteConfig()['site_title']);
        $this->assertSame('미리보기 회사', $preview->getCompanyConfig()['name']);
        $this->assertSame('미리보기 SEO', $preview->getSeoConfig()['meta_title']);
        $this->assertSame(['header'], $preview->getThemeConfig()['frame_edit']['parts']);
        $this->assertSame('미리보기 사이트', $context->getSiteLogoText());
        $this->assertSame('운영 사이트', $stored->getSiteConfig()['site_title']);
    }

    /**
     * Context는 확장보다 먼저 만들어진다. 요청 중에 overlay를 등록한 확장은 reapplyThemeOverlay()로
     * build()와 같은 규칙(도메인 설정·로고 문구·스킨)을 다시 적용한다.
     */
    public function testReapplyUsesAnOverlayRegisteredAfterBuild(): void
    {
        $stored = new Domain(
            7,
            'preview.test',
            siteConfig: ['site_title' => '운영 사이트'],
            themeConfig: ['frame' => 'basic', 'index' => 'basic']
        );
        $overlay = new ThemeReadOverlay();
        $builder = new class($stored, $overlay) extends ContextBuilder {
            public function __construct(
                private Domain $resolvedDomain,
                ThemeReadOverlayInterface $overlay
            ) {
                parent::__construct(themeReadOverlay: $overlay);
            }

            protected function resolveDomain(?string $domainName): ?Domain
            {
                return $this->resolvedDomain;
            }
        };
        $context = $builder->build(new Request('GET', '/', server: ['HTTP_HOST' => 'preview.test']));
        $this->assertSame('운영 사이트', $context->getSiteLogoText());

        $this->assertTrue($overlay->replace(new ThemeReadSnapshot(
            7,
            ['site_title' => '미리보기 사이트'],
            [],
            [],
            ['frame' => 'basic', 'index' => 'basic']
        ))->isSuccess());
        $builder->reapplyThemeOverlay($context);

        $this->assertSame('미리보기 사이트', $context->getDomainInfo()?->getSiteConfig()['site_title']);
        $this->assertSame('미리보기 사이트', $context->getSiteLogoText());
        $this->assertSame('운영 사이트', $stored->getSiteConfig()['site_title'], '운영 도메인 객체는 바뀌지 않는다');
    }
}
