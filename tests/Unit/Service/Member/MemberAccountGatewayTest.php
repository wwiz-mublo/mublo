<?php

namespace Tests\Unit\Service\Member;

use Mublo\Contract\Member\MemberProfile;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Contract\Member\MemberRegistrationRequest;
use Mublo\Entity\Member\Member;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Service\Member\MemberAccountGateway;
use Mublo\Service\Member\MemberService;
use PHPUnit\Framework\TestCase;

final class MemberAccountGatewayTest extends TestCase
{
    public function testDelegatesRegistrationAndRelatedPersistenceToMemberService(): void
    {
        $members = $this->createMock(MemberRepository::class);
        $members->expects($this->never())->method('create');
        $service = $this->createMock(MemberService::class);
        $request = new MemberRegistrationRequest(3, 'sns-user', 'secret-hash', '닉네임');
        $related = static function (int $memberId): void {};
        $service->expects($this->once())->method('registerAccount')
            ->with($request, $related)->willReturn(17);
        $gateway = new MemberAccountGateway($members, $service, $this->createStub(MemberQueryInterface::class));
        $this->assertSame(17, $gateway->create($request, $related));
    }

    public function testCredentialVerificationDoesNotExposePasswordHash(): void
    {
        $hash = password_hash('correct', PASSWORD_BCRYPT);
        $member = Member::fromArray([
            'member_id' => 9,
            'domain_id' => 2,
            'user_id' => 'operator',
            'password' => $hash,
            'status' => 'active',
        ]);
        $profile = new MemberProfile(9, 2, 'operator', null, 10, true, admin: true);
        $members = $this->createMock(MemberRepository::class);
        $members->method('findByDomainAndUserId')->with(2, 'operator')->willReturn($member);
        $queries = $this->createMock(MemberQueryInterface::class);
        $queries->expects($this->once())->method('findProfile')->with(9)->willReturn($profile);

        $gateway = new MemberAccountGateway($members, $this->createStub(MemberService::class), $queries);
        $this->assertNull($gateway->verifyCredentials(2, 'operator', 'wrong'));
        $this->assertSame($profile, $gateway->verifyCredentials(2, 'operator', 'correct'));
    }
}
