<?php

namespace Tests\Unit\Service\Member;

use Mublo\Repository\Member\MemberFieldRepository;
use Mublo\Service\Member\MemberCustomFieldQueryService;
use Mublo\Service\Member\MemberService;
use PHPUnit\Framework\TestCase;

class MemberCustomFieldQueryServiceTest extends TestCase
{
    public function testFindValueHidesFieldIdLookupFromConsumer(): void
    {
        $fields = $this->createMock(MemberFieldRepository::class);
        $fields->expects($this->once())
            ->method('findByDomainAndName')
            ->with(7, 'phone')
            ->willReturn(['field_id' => 12]);

        $members = $this->createMock(MemberService::class);
        $members->method('getFieldValues')->with(31)->willReturn([
            ['field_id' => 9, 'field_value' => 'ignored'],
            ['field_id' => 12, 'field_value' => '010-1234-5678'],
        ]);

        $query = new MemberCustomFieldQueryService($fields, $members);

        $this->assertSame('010-1234-5678', $query->findValue(31, 7, 'phone'));
    }

    public function testUnknownFieldReturnsNullWithoutReadingValues(): void
    {
        $fields = $this->createMock(MemberFieldRepository::class);
        $fields->method('findByDomainAndName')->willReturn(null);
        $members = $this->createMock(MemberService::class);
        $members->expects($this->never())->method('getFieldValues');

        $this->assertNull(
            (new MemberCustomFieldQueryService($fields, $members))->findValue(31, 7, 'missing')
        );
    }
}
