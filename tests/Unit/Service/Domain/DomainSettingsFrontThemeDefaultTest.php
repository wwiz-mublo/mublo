<?php

namespace Tests\Unit\Service\Domain;

use Mublo\Repository\Domain\DomainRepository;
use Mublo\Service\Domain\DomainResolver;
use Mublo\Service\Domain\DomainSettingsService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 프론트 기본 화면 모드(site_config.front_theme_default) 저장 규칙.
 *
 * 핵심 계약: 기본값은 기존 동작과 같은 auto(기기 설정 따름)이고,
 * auto·light·dark 밖의 값은 auto 로 저장한다.
 */
class DomainSettingsFrontThemeDefaultTest extends TestCase
{
    private function service(): DomainSettingsService
    {
        return new DomainSettingsService(
            $this->createMock(DomainRepository::class),
            $this->createMock(DomainResolver::class),
        );
    }

    /** @param array<string, mixed> $data */
    private function sanitize(array $data): array
    {
        $method = new ReflectionMethod(DomainSettingsService::class, 'sanitizeSiteConfig');

        return $method->invoke($this->service(), $data);
    }

    public function testDefaultKeepsFollowingDeviceSetting(): void
    {
        $this->assertSame('auto', $this->service()->getDefaultSiteConfig()['front_theme_default']);
        $this->assertContains('front_theme_default', DomainSettingsService::SITE_KEYS);
    }

    public function testMissingValueIsSavedAsAuto(): void
    {
        $this->assertSame('auto', $this->sanitize([])['front_theme_default']);
    }

    /** @return array<string, array{string}> */
    public static function knownModes(): array
    {
        return ['auto' => ['auto'], 'light' => ['light'], 'dark' => ['dark']];
    }

    #[DataProvider('knownModes')]
    public function testKnownModeIsSavedAsIs(string $mode): void
    {
        $this->assertSame($mode, $this->sanitize(['front_theme_default' => $mode])['front_theme_default']);
    }

    public function testUnknownModeFallsBackToAuto(): void
    {
        foreach (['system', 'LIGHT', '<script>', ''] as $value) {
            $this->assertSame('auto', $this->sanitize(['front_theme_default' => $value])['front_theme_default'], $value);
        }
    }
}
