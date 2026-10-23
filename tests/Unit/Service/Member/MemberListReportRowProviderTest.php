<?php

namespace Tests\Unit\Service\Member;

use Mublo\Service\Member\MemberAdminService;
use Mublo\Service\Member\Report\MemberListReportRowProvider;
use PHPUnit\Framework\TestCase;

/**
 * 회원 엑셀은 목록 화면과 같은 조건으로 같은 회원을 내보내야 한다.
 */
final class MemberListReportRowProviderTest extends TestCase
{
    public function testListConditionsAreTranslatedLikeTheListScreen(): void
    {
        $service = $this->createMock(MemberAdminService::class);
        $service->method('getSearchFields')->willReturn([
            'phone' => ['label' => '연락처', 'field_info' => ['field_id' => 3, 'is_encrypted' => true]],
        ]);
        $service->expects($this->once())
            ->method('getListWithFields')
            ->with(
                5,
                1,
                500,
                ['keyword' => '0101234', 'field' => 'phone', 'field_info' => ['field_id' => 3, 'is_encrypted' => true]],
                null,
                'created_at',
                'desc',
                ['level_value' => 2, 'status' => 'active']
            )
            ->willReturn(['success' => true, 'data' => ['members' => [], 'total' => 0]]);

        $provider = new MemberListReportRowProvider($service, [], 5, [
            'search_field' => 'phone',
            'search_keyword' => '0101234',
            'level' => '2',
            'status' => 'active',
            'sort' => 'created_at',
            'order' => 'desc',
        ]);

        $this->assertSame([], iterator_to_array($provider->rows()));
    }

    public function testRowCarriesLevelNameStatusLabelAndListFields(): void
    {
        $service = $this->createStub(MemberAdminService::class);
        $service->method('getListWithFields')->willReturn(['success' => true, 'data' => [
            'members' => [[
                'member_id' => 9,
                'user_id' => 'buyer',
                'nickname' => null,
                'level_value' => 2,
                'status' => 'active',
                'point' => 1500,
                'created_at' => '2026-10-01 10:00:00',
                'last_login_at' => null,
                'password' => 'hash',
                'field_phone' => '010-1234-5678',
            ]],
            'total' => 1,
        ]]);

        $rows = iterator_to_array(
            (new MemberListReportRowProvider($service, [2 => '병사'], 5, []))->rows()
        );

        $this->assertSame([
            'user_id' => 'buyer',
            'nickname' => '',
            'level_name' => '병사',
            'status_label' => '활성',
            'point' => 1500,
            'created_at' => '2026-10-01 10:00:00',
            'last_login_at' => '',
            'field_phone' => '010-1234-5678',
        ], $rows[0]);
    }
}
