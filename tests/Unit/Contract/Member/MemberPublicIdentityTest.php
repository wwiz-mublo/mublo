<?php
declare(strict_types=1);

namespace Tests\Unit\Contract\Member;

use Mublo\Contract\Auth\AuthenticatedUser;
use Mublo\Contract\Member\MemberExtensionIdentity;
use Mublo\Contract\Member\MemberProfile;
use Mublo\Entity\Member\Member;
use PHPUnit\Framework\TestCase;

final class MemberPublicIdentityTest extends TestCase
{
    private const PUBLIC_ID = 'a3f9c2e81b47d06f5a92c1';

    public function testPublicDisplayNameNeverFallsBackToNameOrLoginId(): void
    {
        $member = Member::fromArray([
            'member_id' => 7,
            'public_id' => self::PUBLIC_ID,
            'domain_id' => 1,
            'user_id' => 'private-login-id',
            'status' => 'active',
        ]);
        $member->setFieldValues(['name' => '실명']);

        $profile = new MemberProfile(
            7,
            1,
            'private-login-id',
            null,
            1,
            true,
            '실명',
            publicId: self::PUBLIC_ID,
        );
        $authenticated = new AuthenticatedUser(
            7,
            1,
            'private-login-id',
            null,
            1,
            false,
            false,
            false,
            null,
            name: '실명',
            publicId: self::PUBLIC_ID,
        );

        self::assertSame('회원 a3f9c2e81b47', $member->getPublicDisplayName());
        self::assertSame('회원 a3f9c2e81b47', $profile->publicDisplayName());
        self::assertSame('회원 a3f9c2e81b47', $authenticated->publicDisplayName());
        self::assertSame(self::PUBLIC_ID, $member->toSafeArray()['public_id']);
    }

    public function testLegacyPositionalConstructionRemainsCompatibleAndSafe(): void
    {
        $profile = new MemberProfile(42, 1, 'member42', null, 1, true);
        $authenticated = new AuthenticatedUser(42, 1, 'member42', null, 1, false, false, false, null);

        self::assertSame('회원', $profile->publicDisplayName());
        self::assertSame('회원', $authenticated->publicDisplayName());
    }

    public function testExtensionIdentitySerializesOnlyPublicFields(): void
    {
        $identity = new MemberExtensionIdentity(42, 3, self::PUBLIC_ID, '회원 이름');

        self::assertSame(42, $identity->getMemberId());
        self::assertSame(3, $identity->getDomainId());
        self::assertSame(
            ['publicId' => self::PUBLIC_ID, 'displayName' => '회원 이름'],
            $identity->jsonSerialize()
        );
    }
}
