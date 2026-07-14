<?php

namespace Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Mublo\Service\Domain\DomainSettingsService;
use Mublo\Service\Domain\DomainResolver;
use Mublo\Repository\Domain\DomainRepository;

/**
 * SettingsServiceTest
 *
 * 설정 서비스 테스트
 * - 사이트 설정 조회/저장
 * - 회사 정보 조회/저장
 * - 설정값 유효성 검증
 */
class SettingsServiceTest extends TestCase
{
    private DomainSettingsService $settingsService;
    private MockObject $domainRepositoryMock;
    private MockObject $domainResolverMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->domainRepositoryMock = $this->createMock(DomainRepository::class);
        $this->domainResolverMock = $this->createMock(DomainResolver::class);
        $this->settingsService = new DomainSettingsService($this->domainRepositoryMock, $this->domainResolverMock);
    }

    /**
     * 기본 사이트 설정 조회
     */
    public function testGetDefaultSiteConfig(): void
    {
        // When: 기본 설정 조회
        $config = $this->settingsService->getDefaultSiteConfig();

        // Then: 기본값 확인
        $this->assertArrayHasKey('site_title', $config);
        $this->assertArrayHasKey('site_subtitle', $config);
        $this->assertArrayHasKey('timezone', $config);
        $this->assertArrayHasKey('language', $config);
        $this->assertEquals('Asia/Seoul', $config['timezone']);
        $this->assertEquals('ko', $config['language']);
    }

    /**
     * 도메인별 사이트 설정 조회 (도메인 없음)
     */
    public function testGetSiteConfigWhenDomainNotFound(): void
    {
        // Given: 도메인이 없음
        $this->domainRepositoryMock->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn(null);

        // When: 설정 조회
        $config = $this->settingsService->getSiteConfig(1);

        // Then: 기본값 반환
        $this->assertArrayHasKey('site_title', $config);
        $this->assertEquals('Asia/Seoul', $config['timezone']);
    }

    /**
     * 도메인별 사이트 설정 조회 (도메인 있음)
     */
    public function testGetSiteConfigWhenDomainExists(): void
    {
        // Given: 도메인이 있고 설정이 있음
        $mockDomain = $this->createMock(\Mublo\Entity\Domain\Domain::class);
        $mockDomain->expects($this->once())
            ->method('getSiteConfig')
            ->willReturn([
                'site_title' => 'My Shop',
                'timezone' => 'America/New_York',
            ]);

        $this->domainRepositoryMock->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($mockDomain);

        // When: 설정 조회
        $config = $this->settingsService->getSiteConfig(1);

        // Then: 도메인 설정과 기본값 병합
        $this->assertEquals('My Shop', $config['site_title']);
        $this->assertEquals('America/New_York', $config['timezone']);
        $this->assertEquals('ko', $config['language']); // 기본값
    }

    /**
     * 기본 회사 설정 조회
     */
    public function testGetDefaultCompanyConfig(): void
    {
        // When: 기본 설정 조회
        $config = $this->settingsService->getDefaultCompanyConfig();

        // Then: 기본값 확인
        $this->assertArrayHasKey('name', $config);
        $this->assertArrayHasKey('owner', $config);
        $this->assertArrayHasKey('business_number', $config);
        $this->assertArrayHasKey('address', $config);
        $this->assertCount(14, $config); // 14개 필드
    }

    /**
     * 도메인별 회사 설정 조회 (도메인 없음)
     */
    public function testGetCompanyConfigWhenDomainNotFound(): void
    {
        // Given: 도메인이 없음
        $this->domainRepositoryMock->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn(null);

        // When: 설정 조회
        $config = $this->settingsService->getCompanyConfig(1);

        // Then: 기본값 반환
        $this->assertArrayHasKey('name', $config);
        $this->assertEmpty($config['name']); // 기본값은 빈 문자열
    }

    /**
     * 도메인별 회사 설정 조회 (도메인 있음)
     */
    public function testGetCompanyConfigWhenDomainExists(): void
    {
        // Given: 도메인이 있고 설정이 있음
        $mockDomain = $this->createMock(\Mublo\Entity\Domain\Domain::class);
        $mockDomain->expects($this->once())
            ->method('getCompanyConfig')
            ->willReturn([
                'name' => 'ABC Company',
                'owner' => 'John Doe',
                'business_number' => '123-45-67890',
            ]);

        $this->domainRepositoryMock->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($mockDomain);

        // When: 설정 조회
        $config = $this->settingsService->getCompanyConfig(1);

        // Then: 도메인 설정과 기본값 병합
        $this->assertEquals('ABC Company', $config['name']);
        $this->assertEquals('John Doe', $config['owner']);
        $this->assertEquals('123-45-67890', $config['business_number']);
        $this->assertEmpty($config['tel']); // 기본값
    }

    /**
     * 설정 필드 구조 검증
     */
    public function testSiteConfigContainsAllRequiredFields(): void
    {
        // When: 기본 설정 조회
        $config = $this->settingsService->getDefaultSiteConfig();

        // Then: 모든 필드 존재
        // logo/favicon은 SEO 설정(getDefaultSeoConfig)에 있으므로
        // 사이트 설정에서는 core 키들만 검증
        $requiredFields = [
            'site_title', 'site_subtitle', 'admin_email',
            'timezone', 'language',
        ];
        
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $config);
        }
    }

    /**
     * 회사 설정 필드 구조 검증
     */
    public function testCompanyConfigContainsAllRequiredFields(): void
    {
        // When: 기본 설정 조회
        $config = $this->settingsService->getDefaultCompanyConfig();

        // Then: 모든 필드 존재
        $requiredFields = [
            'name', 'owner', 'tel', 'fax', 'email',
            'business_number', 'tongsin_number', 'zipcode',
            'address', 'address_detail', 'privacy_officer', 'privacy_email'
        ];
        
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $config);
        }
    }

    #[DataProvider('multiDomainProvider')]
    public function testMultipleDomainConfigs(int $domainId, string $expectedTitle): void
    {
        // Given: 다양한 도메인 설정
        $mockDomain = $this->createMock(\Mublo\Entity\Domain\Domain::class);
        $mockDomain->expects($this->once())
            ->method('getSiteConfig')
            ->willReturn(['site_title' => $expectedTitle]);

        $this->domainRepositoryMock->expects($this->once())
            ->method('find')
            ->with($domainId)
            ->willReturn($mockDomain);

        // When: 설정 조회
        $config = $this->settingsService->getSiteConfig($domainId);

        // Then: 올바른 설정 반환
        $this->assertEquals($expectedTitle, $config['site_title']);
    }

    public static function multiDomainProvider(): array
    {
        return [
            'domain 1' => [1, 'Shop A'],
            'domain 2' => [2, 'Shop B'],
            'domain 3' => [3, 'Shop C'],
        ];
    }

    public function testDefaultThemeConfigUsesPolicyKeyInsteadOfUnusedPageKey(): void
    {
        $config = $this->settingsService->getDefaultThemeConfig();

        $this->assertArrayHasKey('policy', $config);
        $this->assertArrayNotHasKey('page', $config);
        $this->assertSame(DomainSettingsService::THEME_KEYS, array_keys($config));
    }

    public function testThemeSaveRejectsTraversalWithoutPersistingIt(): void
    {
        $domain = $this->createMock(\Mublo\Entity\Domain\Domain::class);
        $domain->method('getDomain')->willReturn('example.test');
        $this->domainRepositoryMock->method('find')->with(7)->willReturn($domain);
        $this->domainRepositoryMock->expects($this->never())->method('updateThemeConfig');
        $this->domainResolverMock->expects($this->once())->method('invalidate')->with('example.test');

        $result = $this->settingsService->saveSettings(7, [
            'theme' => ['frame' => '..\\basic'],
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertArrayHasKey('theme.frame', $result->getData()['errors']);
    }

    public function testThemeSaveRejectsSyntacticallyValidButMissingSkin(): void
    {
        $domain = $this->createMock(\Mublo\Entity\Domain\Domain::class);
        $domain->method('getDomain')->willReturn('example.test');
        $this->domainRepositoryMock->method('find')->with(7)->willReturn($domain);
        $this->domainRepositoryMock->expects($this->never())->method('updateThemeConfig');

        $result = $this->settingsService->saveSettings(7, [
            'theme' => ['frame' => 'no-such-skin'],
        ]);

        $this->assertTrue($result->isFailure());
        $this->assertSame('존재하지 않는 스킨입니다.', $result->getData()['errors']['theme.frame']);
    }

    public function testThemeSavePersistsPolicySelectionAndNoLegacyPageKey(): void
    {
        $domain = $this->createMock(\Mublo\Entity\Domain\Domain::class);
        $domain->method('getDomain')->willReturn('example.test');
        $domain->method('getThemeConfig')->willReturn([]);
        $this->domainRepositoryMock->method('find')->with(7)->willReturn($domain);
        $this->domainRepositoryMock->expects($this->once())
            ->method('updateThemeConfig')
            ->with(7, $this->callback(function (array $config): bool {
                return ($config['policy'] ?? null) === 'basic' && !array_key_exists('page', $config);
            }));

        $result = $this->settingsService->saveSettings(7, [
            'theme' => array_fill_keys(DomainSettingsService::THEME_KEYS, 'basic'),
        ]);

        $this->assertTrue($result->isSuccess());
    }
}
