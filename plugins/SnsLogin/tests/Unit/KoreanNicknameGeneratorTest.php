<?php

namespace Tests\SnsLogin\Unit;

use Mublo\Plugin\SnsLogin\Service\KoreanNicknameGenerator;
use PHPUnit\Framework\TestCase;

class KoreanNicknameGeneratorTest extends TestCase
{
    public function testProvidesLargeCombinationSpace(): void
    {
        $generator = new KoreanNicknameGenerator();

        $this->assertGreaterThanOrEqual(250_000, $generator->capacity());
    }

    public function testGeneratesKoreanNicknameWithinMemberLengthLimit(): void
    {
        $generator = new KoreanNicknameGenerator();

        for ($i = 0; $i < 100; $i++) {
            $nickname = $generator->generate();

            $this->assertMatchesRegularExpression('/^[가-힣]+$/u', $nickname);
            $this->assertGreaterThanOrEqual(2, mb_strlen($nickname));
            $this->assertLessThanOrEqual(20, mb_strlen($nickname));
        }
    }
}
