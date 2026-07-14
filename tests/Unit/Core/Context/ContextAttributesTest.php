<?php

namespace Tests\Unit\Core\Context;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Entity\Domain\Domain;

/**
 * ContextAttributesTest
 *
 * Context의 동적 속성, 사이트 이미지, 사이트 오버라이드 테스트
 * - setAttribute / getAttribute / hasAttribute / lockAttributes
 * - setSiteImageUrls / getSiteImageUrl
 * - setSiteLogoText / getSiteLogoText
 * - setSiteOverride / getSiteOverride
 */
class ContextAttributesTest extends TestCase
{
    private Context $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->context = new Context(new Request('GET', '/'));
    }

    // =========================================================================
    // Dynamic Attributes
    // =========================================================================

    public function testAttributesAreEmptyByDefault(): void
    {
        $this->assertEmpty($this->context->getAttributes());
        $this->assertFalse($this->context->hasAttribute('any.key'));
    }

    public function testSetAndGetAttribute(): void
    {
        // Given / When
        $this->context->setAttribute('shop.is_checkout', true);

        // Then
        $this->assertTrue($this->context->hasAttribute('shop.is_checkout'));
        $this->assertTrue($this->context->getAttribute('shop.is_checkout'));
    }

    public function testGetAttributeReturnsDefaultWhenMissing(): void
    {
        $this->assertNull($this->context->getAttribute('missing.key'));
        $this->assertEquals('fallback', $this->context->getAttribute('missing.key', 'fallback'));
    }

    public function testGetAttributesReturnsAll(): void
    {
        $this->context->setAttribute('shop.mode', 'active');
        $this->context->setAttribute('rental.category', 42);

        $attributes = $this->context->getAttributes();

        $this->assertEquals('active', $attributes['shop.mode']);
        $this->assertEquals(42, $attributes['rental.category']);
    }

    public function testSetAttributeAcceptsVariousTypes(): void
    {
        $this->context->setAttribute('key.string', 'hello');
        $this->context->setAttribute('key.int', 123);
        $this->context->setAttribute('key.array', ['a' => 1]);
        $this->context->setAttribute('key.null', null);

        $this->assertEquals('hello', $this->context->getAttribute('key.string'));
        $this->assertEquals(123, $this->context->getAttribute('key.int'));
        $this->assertEquals(['a' => 1], $this->context->getAttribute('key.array'));
        $this->assertNull($this->context->getAttribute('key.null'));
    }

    public function testLockAttributesPreventsSetAttribute(): void
    {
        // Given: 속성 설정 후 잠금
        $this->context->setAttribute('shop.mode', 'active');
        $this->context->lockAttributes();

        // Then: 잠금 이후 setAttribute 호출 시 LogicException 발생
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/locked/i');

        $this->context->setAttribute('shop.mode', 'modified');
    }

    public function testLockAttributesDoesNotPreventGetAttribute(): void
    {
        // Given
        $this->context->setAttribute('shop.mode', 'active');
        $this->context->lockAttributes();

        // When / Then: 읽기는 잠금 후에도 허용
        $this->assertEquals('active', $this->context->getAttribute('shop.mode'));
        $this->assertTrue($this->context->hasAttribute('shop.mode'));
    }

    public function testMultipleLockCallsAreSafe(): void
    {
        $this->context->lockAttributes();
        $this->context->lockAttributes(); // 두 번 호출해도 에러 없음

        $this->expectException(\LogicException::class);
        $this->context->setAttribute('key', 'value');
    }

    // =========================================================================
    // Site Image URLs
    // =========================================================================

    public function testSiteImageUrlsAreEmptyByDefault(): void
    {
        $this->assertEmpty($this->context->getSiteImageUrls());
        $this->assertEquals('', $this->context->getSiteImageUrl('logo_pc'));
    }

    public function testSetSiteImageUrls(): void
    {
        // Given
        $urls = [
            'logo_pc' => 'https://example.com/logo-pc.png',
            'logo_mobile' => 'https://example.com/logo-mobile.png',
            'favicon' => 'https://example.com/favicon.ico',
            'og_image' => 'https://example.com/og.png',
        ];

        // When
        $this->context->setSiteImageUrls($urls);

        // Then
        $this->assertEquals($urls, $this->context->getSiteImageUrls());
        $this->assertEquals('https://example.com/logo-pc.png', $this->context->getSiteImageUrl('logo_pc'));
        $this->assertEquals('https://example.com/favicon.ico', $this->context->getSiteImageUrl('favicon'));
    }

    public function testGetSiteImageUrlReturnsEmptyStringForMissingKey(): void
    {
        $this->context->setSiteImageUrls(['logo_pc' => 'https://example.com/logo.png']);

        $this->assertEquals('', $this->context->getSiteImageUrl('non_existent_key'));
    }

    public function testSetSiteImageUrlOverridesSpecificKey(): void
    {
        // Given: 전체 URL 설정
        $this->context->setSiteImageUrls(['logo_pc' => 'https://example.com/logo.png']);

        // When: 특정 키만 교체
        $this->context->setSiteImageUrl('logo_pc', 'https://partner.com/custom-logo.png');

        // Then
        $this->assertEquals('https://partner.com/custom-logo.png', $this->context->getSiteImageUrl('logo_pc'));
    }

    public function testSetSiteImageUrlAddsNewKey(): void
    {
        // When: 새 키 추가
        $this->context->setSiteImageUrl('app_icon', 'https://example.com/icon.png');

        // Then
        $this->assertEquals('https://example.com/icon.png', $this->context->getSiteImageUrl('app_icon'));
    }

    // =========================================================================
    // Site Logo Text
    // =========================================================================

    public function testSiteLogoTextIsEmptyByDefault(): void
    {
        $this->assertEquals('', $this->context->getSiteLogoText());
    }

    public function testSetSiteLogoText(): void
    {
        $this->context->setSiteLogoText('My Awesome Site');
        $this->assertEquals('My Awesome Site', $this->context->getSiteLogoText());
    }

    public function testSiteLogoTextCanBeReplaced(): void
    {
        $this->context->setSiteLogoText('Default Site');
        $this->context->setSiteLogoText('Partner Name'); // 오버라이드

        $this->assertEquals('Partner Name', $this->context->getSiteLogoText());
    }

    // =========================================================================
    // Site Overrides
    // =========================================================================

    public function testSiteOverrideReturnsNullForMissingKey(): void
    {
        $this->assertNull($this->context->getSiteOverride('cs_tel'));
    }

    public function testSiteOverrideReturnsDefaultForMissingKey(): void
    {
        $this->assertEquals('N/A', $this->context->getSiteOverride('cs_tel', 'N/A'));
    }

    public function testSetAndGetSiteOverride(): void
    {
        $this->context->setSiteOverride('cs_tel', '02-1234-5678');
        $this->assertEquals('02-1234-5678', $this->context->getSiteOverride('cs_tel'));
    }

    public function testGetAllSiteOverrides(): void
    {
        $this->context->setSiteOverride('cs_tel', '02-1234-5678');
        $this->context->setSiteOverride('cs_time', '09:00~18:00');

        $overrides = $this->context->getSiteOverrides();

        $this->assertArrayHasKey('cs_tel', $overrides);
        $this->assertArrayHasKey('cs_time', $overrides);
        $this->assertEquals('02-1234-5678', $overrides['cs_tel']);
        $this->assertEquals('09:00~18:00', $overrides['cs_time']);
    }

    public function testSiteOverrideCanStoreMixedTypes(): void
    {
        $this->context->setSiteOverride('count', 42);
        $this->context->setSiteOverride('active', true);
        $this->context->setSiteOverride('config', ['key' => 'value']);

        $this->assertEquals(42, $this->context->getSiteOverride('count'));
        $this->assertTrue($this->context->getSiteOverride('active'));
        $this->assertEquals(['key' => 'value'], $this->context->getSiteOverride('config'));
    }

    public function testSiteOverrideIsIndependentFromAttributeLock(): void
    {
        // Given: attributes 잠금
        $this->context->lockAttributes();

        // When / Then: siteOverride는 잠금과 무관하게 작동
        $this->context->setSiteOverride('cs_tel', '02-9999-9999');
        $this->assertEquals('02-9999-9999', $this->context->getSiteOverride('cs_tel'));
    }

    // =========================================================================
    // Block Skin
    // =========================================================================

    public function testBlockSkinReturnsNullByDefault(): void
    {
        $this->assertNull($this->context->getBlockSkin('board'));
    }

    public function testSetAndGetBlockSkin(): void
    {
        $this->context->setBlockSkin('board', 'modern');
        $this->assertEquals('modern', $this->context->getBlockSkin('board'));
    }

    public function testDeprecatedTemplateSkinMapsToBlockSkin(): void
    {
        // setTemplateSkin is deprecated but should still work
        $this->context->setTemplateSkin('banner', 'slider');
        $this->assertEquals('slider', $this->context->getBlockSkin('banner'));
        $this->assertEquals('slider', $this->context->getTemplateSkin('banner'));
    }

    // =========================================================================
    // Menu code
    // =========================================================================

    public function testCurrentMenuCodeIsNullByDefault(): void
    {
        $this->assertNull($this->context->getCurrentMenuCode());
    }

    public function testSetCurrentMenuCode(): void
    {
        $this->context->setCurrentMenuCode('001');
        $this->assertEquals('001', $this->context->getCurrentMenuCode());
    }

    public function testSetCurrentMenuCodeToNull(): void
    {
        $this->context->setCurrentMenuCode('001');
        $this->context->setCurrentMenuCode(null);
        $this->assertNull($this->context->getCurrentMenuCode());
    }

    public function testCurrentMenuLayoutIsNullByDefault(): void
    {
        $this->assertNull($this->context->getCurrentMenuLayout());
    }

    public function testSetCurrentMenuLayout(): void
    {
        $override = ['layout_type' => 1];
        $this->context->setCurrentMenuLayout($override);
        $this->assertSame($override, $this->context->getCurrentMenuLayout());
    }

    public function testSetCurrentMenuLayoutToNull(): void
    {
        $this->context->setCurrentMenuLayout(['layout_type' => 3]);
        $this->context->setCurrentMenuLayout(null);
        $this->assertNull($this->context->getCurrentMenuLayout());
    }
}
