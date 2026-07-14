<?php

namespace Tests\Unit\Service\Member;

use Mublo\Contract\Member\MemberProfile;
use Mublo\Entity\Member\Member;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Service\Member\MemberQueryService;
use PHPUnit\Framework\TestCase;

final class MemberQueryServiceTest extends TestCase
{
    public function testReturnsStableProfileWithoutExposingMemberEntity(): void
    {
        $repository = $this->createMock(MemberRepository::class);
        $repository->method('find')->with(7)->willReturn(Member::fromArray([
            'member_id' => 7,
            'domain_id' => 3,
            'user_id' => 'member7',
            'password' => 'hash',
            'nickname' => '회원7',
            'level_value' => 4,
            'status' => 'active',
        ]));

        $profile = (new MemberQueryService($repository))->findProfile(7);

        $this->assertInstanceOf(MemberProfile::class, $profile);
        $this->assertSame(7, $profile->memberId);
        $this->assertSame(3, $profile->domainId);
        $this->assertSame('member7', $profile->userId);
        $this->assertSame('회원7', $profile->nickname);
        $this->assertSame(4, $profile->levelValue);
        $this->assertTrue($profile->active);
    }

    public function testReturnsNullWhenMemberDoesNotExist(): void
    {
        $repository = $this->createMock(MemberRepository::class);
        $repository->method('find')->with(99)->willReturn(null);

        $this->assertNull((new MemberQueryService($repository))->findProfile(99));
    }
}
