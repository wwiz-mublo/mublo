<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Member;

use Mublo\Infrastructure\Database\Database;
use Mublo\Service\Member\MemberSegmentQueryService;
use PHPUnit\Framework\TestCase;

/**
 * 조건 회원 조회는 사이트·정상 상태·일반 등급으로 늘 좁히고, 잘못된 날짜는 무시한다.
 */
final class MemberSegmentQueryServiceTest extends TestCase
{
    public function testBuildsScopedQuery(): void
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->once())->method('select')
            ->with(
                $this->callback(fn(string $sql) =>
                    str_contains($sql, 'm.domain_id = ?')
                    && str_contains($sql, "m.status = 'active'")
                    && str_contains($sql, 'COALESCE(l.is_admin, 0) = 0')
                    && str_contains($sql, 'COALESCE(l.is_super, 0) = 0')
                    && str_contains($sql, 'm.level_value IN (?,?)')
                    && str_contains($sql, 'm.created_at >= ?')
                    && !str_contains($sql, 'm.created_at <= ?')
                    && str_contains($sql, 'LIMIT 100')),
                [3, 2, 5, '2026-01-01 00:00:00']
            )
            ->willReturn([['member_id' => '7'], ['member_id' => '9']]);

        $ids = (new MemberSegmentQueryService($db))->findActiveMemberIds(3, [2, 5, 0, 2], '2026-01-01', '2026-13-40', 100);

        $this->assertSame([7, 9], $ids);
    }
}
