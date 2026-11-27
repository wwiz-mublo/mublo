<?php
declare(strict_types=1);

namespace Tests\Unit\Controller\Front;

use Mublo\Controller\Front\AdsTxtController;
use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Entity\Domain\Domain;
use Tests\TestCase;

final class AdsTxtControllerTest extends TestCase
{
    public function testPathExistsBeforeAdsensePublisherIdIsConfigured(): void
    {
        $response = (new AdsTxtController())->index($this->context([]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            "# Google AdSense publisher ID is not configured yet.\n",
            $response->getContent()
        );
        self::assertSame('text/plain; charset=utf-8', $response->getHeaders()['Content-Type']);
    }

    public function testConfiguredPublisherIdProducesGoogleAdsenseRecord(): void
    {
        $response = (new AdsTxtController())->index($this->context([
            'adsense_publisher_id' => 'pub-1234567890123456',
        ]));

        self::assertSame(
            "google.com, pub-1234567890123456, DIRECT, f08c47fec0942fa0\n",
            $response->getContent()
        );
    }

    public function testMalformedStoredPublisherIdNeverProducesSellerRecord(): void
    {
        $response = (new AdsTxtController())->index($this->context([
            'adsense_publisher_id' => 'pub-not-valid',
        ]));

        self::assertStringStartsWith('#', (string) $response->getContent());
        self::assertStringNotContainsString('google.com,', (string) $response->getContent());
    }

    /** @param array<string,mixed> $seoConfig */
    private function context(array $seoConfig): Context
    {
        $context = new Context(new Request('GET', '/ads.txt'));
        $context->setDomainInfo(new Domain(
            domainId: 7,
            domain: 'shop.example.test',
            seoConfig: $seoConfig,
        ));
        return $context;
    }
}
