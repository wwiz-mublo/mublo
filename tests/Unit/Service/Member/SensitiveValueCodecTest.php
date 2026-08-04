<?php

namespace Tests\Unit\Service\Member;

use Mublo\Service\Member\FieldEncryptionService;
use Mublo\Service\Member\SensitiveValueCodec;
use PHPUnit\Framework\TestCase;

final class SensitiveValueCodecTest extends TestCase
{
    public function testDelegatesEncryptionAndBlindIndexPolicy(): void
    {
        $encryption = $this->createMock(FieldEncryptionService::class);
        $encryption->expects($this->once())->method('encrypt')->with('plain')->willReturn('encoded');
        $encryption->expects($this->once())->method('decrypt')->with('encoded')->willReturn('plain');
        $encryption->expects($this->once())->method('createSearchIndex')->with('Search')->willReturn('index');

        $codec = new SensitiveValueCodec($encryption);
        $this->assertSame('encoded', $codec->encrypt('plain'));
        $this->assertSame('plain', $codec->decrypt('encoded'));
        $this->assertSame('index', $codec->createSearchIndex('Search'));
    }
}
