<?php

namespace Tests\Unit\Service\Site;

use Mublo\Contract\Site\SiteMutationFingerprint;
use PHPUnit\Framework\TestCase;

final class SiteMutationFingerprintTest extends TestCase
{
    public function testAssociativeKeyOrderDoesNotChangeFingerprint(): void
    {
        self::assertSame(
            SiteMutationFingerprint::hash(['a' => 1, 'nested' => ['x' => 2, 'y' => 3]]),
            SiteMutationFingerprint::hash(['nested' => ['y' => 3, 'x' => 2], 'a' => 1])
        );
    }

    public function testListOrderChangesFingerprint(): void
    {
        self::assertNotSame(
            SiteMutationFingerprint::hash(['items' => [1, 2]]),
            SiteMutationFingerprint::hash(['items' => [2, 1]])
        );
    }
}
