<?php
/**
 * packages/Shop/tests/Unit/Service/MemberLevelResolverTest.php
 *
 * MemberLevelResolver 단위 테스트
 *
 * 등급별 할인·적립 설정은 levelId 로 저장되고 회원은 levelValue 를 들고 있다.
 * 그 사이를 잇는 해석과, 의존이 없을 때의 안전한 폴백을 검증한다.
 */

namespace Tests\Shop\Unit\Service;

use Mublo\Contract\Member\MemberLevelCatalogInterface;
use Mublo\Contract\Member\MemberLevelDescriptor;
use Mublo\Contract\Member\MemberProfile;
use Mublo\Contract\Member\MemberQueryInterface;
use Mublo\Packages\Shop\Service\MemberLevelResolver;
use Tests\Shop\TestCase;

class MemberLevelResolverTest extends TestCase
{
    private function descriptor(int $levelId, int $levelValue): MemberLevelDescriptor
    {
        return new MemberLevelDescriptor($levelId, $levelValue, '테스트등급', 'MEMBER', false, false, false);
    }

    private function profile(int $memberId, int $levelValue): MemberProfile
    {
        return new MemberProfile($memberId, 1, 'tester', '테스터', $levelValue, true);
    }

    public function testResolvesLevelIdFromMemberId(): void
    {
        $query = $this->createMock(MemberQueryInterface::class);
        $query->method('findProfile')->with(42)->willReturn($this->profile(42, 50));

        $catalog = $this->createMock(MemberLevelCatalogInterface::class);
        $catalog->method('findByValue')->with(50)->willReturn($this->descriptor(5, 50));

        $resolver = new MemberLevelResolver($query, $catalog);

        $this->assertSame(5, $resolver->levelIdFor(42));
    }

    public function testGuestHasNoLevel(): void
    {
        $query = $this->createMock(MemberQueryInterface::class);
        $query->expects($this->never())->method('findProfile');

        $resolver = new MemberLevelResolver($query, $this->createMock(MemberLevelCatalogInterface::class));

        $this->assertNull($resolver->levelIdFor(0));
    }

    public function testUnknownMemberOrLevelResolvesToNull(): void
    {
        $query = $this->createMock(MemberQueryInterface::class);
        $query->method('findProfile')->willReturn(null);

        $resolver = new MemberLevelResolver($query, $this->createMock(MemberLevelCatalogInterface::class));
        $this->assertNull($resolver->levelIdFor(99));

        // 회원은 있는데 카탈로그에 그 등급값이 없는 경우
        $query2 = $this->createMock(MemberQueryInterface::class);
        $query2->method('findProfile')->willReturn($this->profile(7, 999));
        $catalog2 = $this->createMock(MemberLevelCatalogInterface::class);
        $catalog2->method('findByValue')->willReturn(null);

        $this->assertNull((new MemberLevelResolver($query2, $catalog2))->levelIdFor(7));
    }

    public function testMemberLookupIsMemoized(): void
    {
        $query = $this->createMock(MemberQueryInterface::class);
        $query->expects($this->once())->method('findProfile')->willReturn($this->profile(42, 50));

        $catalog = $this->createMock(MemberLevelCatalogInterface::class);
        $catalog->expects($this->once())->method('findByValue')->willReturn($this->descriptor(5, 50));

        $resolver = new MemberLevelResolver($query, $catalog);

        $this->assertSame(5, $resolver->levelIdFor(42));
        $this->assertSame(5, $resolver->levelIdFor(42));
    }

    public function testResolvesFromLevelValueWithoutMemberLookup(): void
    {
        $query = $this->createMock(MemberQueryInterface::class);
        $query->expects($this->never())->method('findProfile');

        $catalog = $this->createMock(MemberLevelCatalogInterface::class);
        $catalog->method('findByValue')->with(50)->willReturn($this->descriptor(5, 50));

        $resolver = new MemberLevelResolver($query, $catalog);

        $this->assertSame(5, $resolver->levelIdForValue(50));
    }

    public function testWithoutDependenciesResolvesToNull(): void
    {
        $resolver = new MemberLevelResolver();

        $this->assertNull($resolver->levelIdFor(42));
        $this->assertNull($resolver->levelIdForValue(50));
    }
}
