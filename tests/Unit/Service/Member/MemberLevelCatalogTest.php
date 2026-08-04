<?php

namespace Tests\Unit\Service\Member;

use Mublo\Entity\Member\MemberLevel;
use Mublo\Service\Member\MemberLevelCatalog;
use Mublo\Service\Member\MemberLevelService;
use PHPUnit\Framework\TestCase;

final class MemberLevelCatalogTest extends TestCase
{
    public function testMapsInternalLevelToStableDescriptor(): void
    {
        $level = MemberLevel::fromArray([
            'level_id' => 2,
            'level_value' => 90,
            'level_name' => '운영자',
            'level_type' => 'STAFF',
            'is_admin' => 1,
            'can_operate_domain' => 1,
        ]);
        $service = $this->createMock(MemberLevelService::class);
        $service->expects($this->once())->method('findByValue')->with(90)->willReturn($level);

        $descriptor = (new MemberLevelCatalog($service))->findByValue(90);

        $this->assertSame('운영자', $descriptor?->name);
        $this->assertTrue($descriptor?->admin);
        $this->assertTrue($descriptor?->canOperateDomain);
    }
}
