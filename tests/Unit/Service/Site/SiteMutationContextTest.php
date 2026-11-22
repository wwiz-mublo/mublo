<?php

namespace Tests\Unit\Service\Site;

use Mublo\Contract\Site\SiteMutationContext;
use PHPUnit\Framework\TestCase;

final class SiteMutationContextTest extends TestCase
{
    public function testSameResourceKeepsOnlyFinalFingerprintInStableOrder(): void
    {
        $context = new SiteMutationContext(3, SiteMutationContext::SOURCE_EXTENSION, 'change-set-1');
        $context
            ->changed('menu_children:root', 'menu_children', str_repeat('a', 64))
            ->changed('block:12', 'block', str_repeat('b', 64))
            ->changed('menu_children:root', 'menu_children', str_repeat('c', 64));

        $resources = $context->resources();
        self::assertSame(['block:12', 'menu_children:root'], array_column($resources, 'resourceId'));
        self::assertSame(str_repeat('c', 64), $resources[1]->fingerprint);
    }

    public function testRejectsUnknownSourceAndInvalidFingerprint(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SiteMutationContext(1, 'site_editor');
    }

    public function testRejectsNonSha256Fingerprint(): void
    {
        $context = new SiteMutationContext(1, SiteMutationContext::SOURCE_ADMIN);

        $this->expectException(\InvalidArgumentException::class);
        $context->changed('block:1', 'block', 'not-a-hash');
    }
}
