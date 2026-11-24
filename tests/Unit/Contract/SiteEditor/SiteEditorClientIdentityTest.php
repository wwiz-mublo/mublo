<?php
declare(strict_types=1);

namespace Tests\Unit\Contract\SiteEditor;

use Mublo\Contract\SiteEditor\SiteEditorAuthenticationException;
use Mublo\Contract\SiteEditor\SiteEditorClientIdentity;
use PHPUnit\Framework\TestCase;

final class SiteEditorClientIdentityTest extends TestCase
{
    public function testScopesAreExplicitAndQueryable(): void
    {
        $identity = new SiteEditorClientIdentity(7, 'sync-key-1', ['snapshot:read', 'plans:write']);

        self::assertTrue($identity->allows('snapshot:read'));
        self::assertFalse($identity->allows('restore:write'));
    }

    public function testInvalidScopeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SiteEditorClientIdentity(7, 'client', ['Snapshot Read']);
    }

    public function testAuthenticationExceptionOnlyAcceptsClientErrorStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SiteEditorAuthenticationException('broken', statusCode: 500);
    }
}
