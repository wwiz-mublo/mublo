<?php
declare(strict_types=1);

namespace Tests\Integration;

use Mublo\Repository\Member\MemberRepository;

/**
 * 공개 회원 검색(MemberRepository::searchActiveByNickname)을 실 DB 로 검증한다.
 *
 * 왜 통합 테스트인가
 *   검증 대상이 전부 방언에 민감하다. `LIKE ? ESCAPE '!'` 의 이스케이프 처리, LIKE 의
 *   대소문자·유니코드 규칙(utf8mb4_unicode_ci), ORDER BY 정렬 기준은 DB 마다 다르다.
 *   가벼운 인메모리 DB 로 대신 돌리면 통과해도 지원 DB 를 보증하지 못하고, 런타임에
 *   쓰지 않는 드라이버가 개발 의존성으로 되살아난다.
 *
 * 스키마
 *   회원 테이블 마이그레이션의 정의에서 이 쿼리가 건드리는 컬럼만 타입·콜레이션 그대로
 *   옮겼다.
 */
class MemberPublicSearchTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('members', "
            member_id BIGINT UNSIGNED PRIMARY KEY,
            public_id CHAR(22) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
            domain_id BIGINT UNSIGNED NOT NULL,
            user_id VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
            nickname VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
            level_value TINYINT UNSIGNED NOT NULL DEFAULT 1,
            status ENUM('active', 'inactive', 'dormant', 'blocked', 'pending', 'withdrawn')
                NOT NULL DEFAULT 'active'
        ");

        $this->createTable('member_levels', "
            level_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            level_value TINYINT UNSIGNED NOT NULL UNIQUE,
            level_name VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
            level_type ENUM('SUPER', 'STAFF', 'PARTNER', 'SELLER', 'SUPPLIER', 'BASIC')
                NOT NULL DEFAULT 'BASIC',
            is_super TINYINT(1) NOT NULL DEFAULT 0,
            is_admin TINYINT(1) NOT NULL DEFAULT 0,
            can_operate_domain TINYINT(1) NOT NULL DEFAULT 0
        ");

        $this->seed('member_levels', [
            ['level_value' => 1, 'level_name' => '일반회원', 'level_type' => 'BASIC'],
        ]);
    }

    public function testSearchUsesOnlyActiveNicknameInCurrentDomain(): void
    {
        $this->seedMembers([
            [1, 1, 'a3f9c2e81b47d06f5a92c1', 'private-one', '테스트회원', 'active'],
            [2, 1, 'b3f9c2e81b47d06f5a92c1', '테스트로그인', '다른닉네임', 'active'],
            [3, 1, 'c3f9c2e81b47d06f5a92c1', 'private-three', '테스트탈퇴', 'withdrawn'],
            [4, 2, 'd3f9c2e81b47d06f5a92c1', 'private-four', '테스트타도메인', 'active'],
            [5, 1, '', 'private-five', '테스트미발급', 'active'],
        ]);

        $members = (new MemberRepository($this->db))->searchActiveByNickname(1, '테스트', 10);

        $this->assertCount(1, $members);
        $this->assertSame(1, $members[0]->getMemberId());
        $this->assertSame('테스트회원', $members[0]->getNickname());
    }

    public function testSearchTreatsLikeWildcardsAsLiteralCharacters(): void
    {
        $this->seedMembers([
            [1, 1, 'a3f9c2e81b47d06f5a92c1', 'private-one', '100%회원', 'active'],
            [2, 1, 'b3f9c2e81b47d06f5a92c1', 'private-two', '100명회원', 'active'],
        ]);

        $members = (new MemberRepository($this->db))->searchActiveByNickname(1, '100%', 10);

        $this->assertCount(1, $members);
        $this->assertSame('100%회원', $members[0]->getNickname());
    }

    /** @param array<int, array{0:int,1:int,2:string,3:string,4:string,5:string}> $rows */
    private function seedMembers(array $rows): void
    {
        $this->seed('members', array_map(
            static fn(array $row): array => [
                'member_id' => $row[0],
                'domain_id' => $row[1],
                'public_id' => $row[2],
                'user_id'   => $row[3],
                'nickname'  => $row[4],
                'status'    => $row[5],
            ],
            $rows,
        ));
    }
}
