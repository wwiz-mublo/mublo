<?php

namespace Tests\Unit\Entity\Domain;

use Mublo\Entity\Domain\Domain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 회원가입 닉네임 입력 설정(site_config.nickname_mode) 해석 테스트.
 *
 * 핵심 계약: 설정이 없거나 모르는 값이면 기존 동작(필수)을 유지한다.
 */
class DomainNicknameModeTest extends TestCase
{
    public function testMissingSettingKeepsNicknameRequired(): void
    {
        $domain = new Domain(1, 'example.com');

        $this->assertSame('required', $domain->getNicknameMode());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function knownModeProvider(): array
    {
        return [
            'required' => ['required'],
            'optional' => ['optional'],
            'hidden'   => ['hidden'],
        ];
    }

    #[DataProvider('knownModeProvider')]
    public function testKnownModeIsReturnedAsIs(string $mode): void
    {
        $domain = new Domain(1, 'example.com', siteConfig: ['nickname_mode' => $mode]);

        $this->assertSame($mode, $domain->getNicknameMode());
    }

    public function testUnknownModeFallsBackToRequired(): void
    {
        $domain = new Domain(1, 'example.com', siteConfig: ['nickname_mode' => 'off']);

        $this->assertSame('required', $domain->getNicknameMode());
    }
}
