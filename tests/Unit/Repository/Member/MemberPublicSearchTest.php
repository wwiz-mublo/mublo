<?php
declare(strict_types=1);

namespace Tests\Unit\Repository\Member;

use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Member\MemberRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class MemberPublicSearchTest extends TestCase
{
    public function testSearchUsesOnlyActiveNicknameInCurrentDomain(): void
    {
        $db = $this->database();
        $this->insert($db, 1, 1, 'a3f9c2e81b47d06f5a92c1', 'private-one', '테스트회원', 'active');
        $this->insert($db, 2, 1, 'b3f9c2e81b47d06f5a92c1', '테스트로그인', '다른닉네임', 'active');
        $this->insert($db, 3, 1, 'c3f9c2e81b47d06f5a92c1', 'private-three', '테스트탈퇴', 'withdrawn');
        $this->insert($db, 4, 2, 'd3f9c2e81b47d06f5a92c1', 'private-four', '테스트타도메인', 'active');
        $this->insert($db, 5, 1, '', 'private-five', '테스트미발급', 'active');

        $members = (new MemberRepository($db))->searchActiveByNickname(1, '테스트', 10);

        self::assertCount(1, $members);
        self::assertSame(1, $members[0]->getMemberId());
        self::assertSame('테스트회원', $members[0]->getNickname());
    }

    public function testSearchTreatsLikeWildcardsAsLiteralCharacters(): void
    {
        $db = $this->database();
        $this->insert($db, 1, 1, 'a3f9c2e81b47d06f5a92c1', 'private-one', '100%회원', 'active');
        $this->insert($db, 2, 1, 'b3f9c2e81b47d06f5a92c1', 'private-two', '100명회원', 'active');

        $members = (new MemberRepository($db))->searchActiveByNickname(1, '100%', 10);

        self::assertCount(1, $members);
        self::assertSame('100%회원', $members[0]->getNickname());
    }

    private function database(): Database
    {
        $db = new Database(new PDO('sqlite::memory:'));
        $db->execute('CREATE TABLE members (
            member_id INTEGER PRIMARY KEY,
            public_id TEXT NOT NULL DEFAULT \'\',
            domain_id INTEGER NOT NULL,
            user_id TEXT NOT NULL,
            nickname TEXT,
            level_value INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL
        )');
        $db->execute('CREATE TABLE member_levels (
            level_value INTEGER PRIMARY KEY,
            level_name TEXT,
            level_type TEXT,
            is_super INTEGER NOT NULL DEFAULT 0,
            is_admin INTEGER NOT NULL DEFAULT 0,
            can_operate_domain INTEGER NOT NULL DEFAULT 0
        )');
        $db->execute("INSERT INTO member_levels (level_value, level_name, level_type) VALUES (1, '일반회원', 'MEMBER')");
        return $db;
    }

    private function insert(
        Database $db,
        int $memberId,
        int $domainId,
        string $publicId,
        string $userId,
        string $nickname,
        string $status,
    ): void {
        $db->execute(
            'INSERT INTO members (member_id, public_id, domain_id, user_id, nickname, status) VALUES (?, ?, ?, ?, ?, ?)',
            [$memberId, $publicId, $domainId, $userId, $nickname, $status],
        );
    }
}
