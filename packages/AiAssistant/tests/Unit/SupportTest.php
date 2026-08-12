<?php
declare(strict_types=1);

namespace Tests\AiAssistant\Unit;

use Mublo\Packages\AiAssistant\Support\CanonicalJson;
use Mublo\Packages\AiAssistant\Support\CursorCodec;
use Mublo\Packages\AiAssistant\Support\Uuid;
use PHPUnit\Framework\TestCase;

final class SupportTest extends TestCase
{
    public function testUuidAndCursorRoundTrip(): void
    {
        $uuid = Uuid::v4();
        self::assertTrue(Uuid::isValid($uuid));
        self::assertSame(987654, CursorCodec::decode(CursorCodec::encode(987654)));
        self::assertNull(CursorCodec::decode('not-a-cursor'));
    }

    public function testCanonicalJsonIgnoresObjectKeyOrder(): void
    {
        self::assertSame(
            CanonicalJson::encode(['b' => 2, 'a' => ['d' => 4, 'c' => 3]]),
            CanonicalJson::encode(['a' => ['c' => 3, 'd' => 4], 'b' => 2])
        );
    }
}
