<?php

namespace Tests\Unit\Contract\Member;

use Mublo\Contract\Member\MemberIdentity;
use PHPUnit\Framework\TestCase;

final class MemberIdentityTest extends TestCase
{
    public function testProvidesPropertyAndGetterAccess(): void
    {
        $identity = new MemberIdentity(7, 3, 'member7', '회원 7');

        $this->assertSame(7, $identity->memberId);
        $this->assertSame(7, $identity->getMemberId());
        $this->assertSame(3, $identity->getDomainId());
        $this->assertSame('member7', $identity->getUserId());
        $this->assertSame('회원 7', $identity->getDisplayName());
    }
}
