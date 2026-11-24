<?php
declare(strict_types=1);

namespace Tests\Unit\Contract\SiteEditor;

use Mublo\Contract\Site\SiteMutationContext;
use Mublo\Contract\SiteEditor\SiteEditContext;
use PHPUnit\Framework\TestCase;

final class SiteEditContextTest extends TestCase
{
    public function testItCarriesPreviewStateAndAddsMatchingMutationContextForApply(): void
    {
        $context = new SiteEditContext(
            7,
            '123e4567-e89b-42d3-a456-426614174000',
            ['site_revision' => 4]
        );
        $mutation = SiteMutationContext::create(7, SiteMutationContext::SOURCE_EXTENSION, $context->changeSetId);
        $apply = $context->forApply($mutation)->withPriorResults(['op_01' => ['created' => true]]);

        self::assertNull($context->mutationContext);
        self::assertSame($mutation, $apply->mutationContext);
        self::assertTrue($apply->priorResults['op_01']['created']);
    }

    public function testItRejectsMutationContextFromAnotherDomain(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SiteEditContext(
            7,
            '123e4567-e89b-42d3-a456-426614174000',
            [],
            [],
            SiteMutationContext::create(8, SiteMutationContext::SOURCE_EXTENSION)
        );
    }
}
