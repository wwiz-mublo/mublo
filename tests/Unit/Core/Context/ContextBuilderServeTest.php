<?php

namespace Tests\Unit\Core\Context;

use Mublo\Core\Context\ContextBuilder;
use Mublo\Core\Http\Request;
use Mublo\Entity\Domain\Domain;
use Mublo\Service\Domain\DomainResolver;
use PHPUnit\Framework\TestCase;

final class ContextBuilderServeTest extends TestCase
{
    public function testServeRequestSkipsDomainAndMenuResolution(): void
    {
        $resolver = $this->createMock(DomainResolver::class);
        $resolver->expects(self::never())->method('resolve');

        $builder = new ContextBuilder($resolver);
        $request = new Request('GET', '/serve/front/basic/app.css', server: [
            'HTTP_HOST' => 'example.com',
            'HTTPS' => 'on',
        ]);

        $context = $builder->build($request);

        self::assertSame('example.com', $context->getDomain());
        self::assertNull($context->getDomainId());
        self::assertSame('basic', $context->getFrameSkin());
        self::assertNull($context->getCurrentMenuCode());
    }

    public function testExtensionAssetStillResolvesDomainForActivationCheck(): void
    {
        $domain = Domain::fromArray([
            'domain_id' => 7,
            'domain' => 'example.com',
            'domain_group' => '7',
        ]);
        $resolver = $this->createMock(DomainResolver::class);
        $resolver->expects(self::once())
            ->method('resolve')
            ->with('example.com')
            ->willReturn($domain);

        $context = (new ContextBuilder($resolver))->build(new Request(
            'GET',
            '/serve/package/Example/app.css',
            server: ['HTTP_HOST' => 'example.com']
        ));

        self::assertSame(7, $context->getDomainId());
        self::assertSame($domain, $context->getDomainInfo());
    }
}
