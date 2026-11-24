<?php
declare(strict_types=1);

namespace Mublo\Contract\Site;

/**
 * 사이트·회사·SEO·테마의 최종 미리보기 설정을 함께 운반하는 읽기 모델.
 *
 * 각 배열은 patch가 아니라 운영 설정 위에 change set 계산을 끝낸 전체 최종값이다.
 */
final readonly class ThemeReadSnapshot
{
    /**
     * @param array<string, mixed> $siteConfig
     * @param array<string, mixed> $companyConfig
     * @param array<string, mixed> $seoConfig
     * @param array<string, mixed> $themeConfig
     */
    public function __construct(
        public int $domainId,
        public array $siteConfig,
        public array $companyConfig,
        public array $seoConfig,
        public array $themeConfig
    ) {
    }
}
