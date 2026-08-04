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
    public function testCreatesOnlyTheExplicitRegistrationShape(): void
    {
        $members = $this->createMock(MemberRepository::class);
        $memberService = $this->createStub(MemberService::class);
        $queries = $this->createStub(MemberQueryInterface::class);
        $members->expects($this->once())->method('create')
            ->with($this->callback(static fn (array $row): bool =>
                $row['domain_id'] === 3
                && $row['origin_domain_id'] === 3
                && $row['user_id'] === 'sns-user'
                && $row['password'] === 'secret-hash'
                && $row['status'] === 'active'
            ))
            ->willReturn(17);

        $request = new MemberRegistrationRequest(3, 'sns-user', 'secret-hash', '닉네임');
        $this->assertSame(17, (new MemberAccountGateway($members, $memberService, $queries))->create($request));
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
