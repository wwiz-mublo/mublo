<?php

namespace Tests\Integration;

use Mublo\Repository\Member\MemberRepository;

/**
 * 회원 생성을 실 DB 로 검증한다.
 *
 * 왜 필요한가
 *   회원을 만드는 세 경로(일반 가입·관리자 생성·SNS 가입)의 테스트가 전부
 *   createMock(MemberRepository::class) 라서, 실제 INSERT 가 한 번도 실행된 적이 없었다.
 *   컬럼 제약·기본값·인코딩은 mock 뒤에서 전부 통과한다.
 *
 *   public_id 가 그 사각지대의 대표 사례다. 세 경로 모두 값을 넘기지 않고
 *   MemberRepository::create() 의 기본값 분기 하나에 의존하는데, 그 분기를 지워도
 *   전체 스위트가 초록이었다. CHAR(22) NOT NULL 이라 실 DB 에서만 드러난다.
 *
 * 스키마
 *   database/migrations 의 members 정의 중 이 테스트가 검증하는 부분만 옮겼다.
 *   전체를 복제하면 마이그레이션이 바뀔 때마다 여기도 따라 고쳐야 해서, 회원 생성에
 *   실제로 관여하는 컬럼과 제약(public_id CHAR(22) NOT NULL UNIQUE 포함)만 남긴다.
 */
class MemberRepositoryCreateTest extends DatabaseTestCase
{
    private MemberRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('members', "
            member_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(22) NOT NULL,
            domain_id BIGINT UNSIGNED NOT NULL,
            origin_domain_id BIGINT UNSIGNED NULL,
            domain_group VARCHAR(50) NULL,
            user_id VARCHAR(50) NOT NULL,
            password VARCHAR(255) NOT NULL,
            nickname VARCHAR(50) NULL,
            level_value INT NOT NULL DEFAULT 1,
            point_balance INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            UNIQUE KEY uk_member_public_id (public_id),
            UNIQUE KEY uk_domain_user (domain_id, user_id)
        ");

        // 회원 조회는 엔티티 하이드레이션 과정에서 레벨을 함께 읽는다
        // (MemberRepository::find → 레벨 조회). 없으면 조회 경로가 통째로 죽는다.
        $this->createTable('member_levels', "
            level_value INT PRIMARY KEY,
            level_name VARCHAR(50) NOT NULL,
            level_type VARCHAR(20) NOT NULL DEFAULT 'MEMBER',
            is_super TINYINT(1) NOT NULL DEFAULT 0,
            is_admin TINYINT(1) NOT NULL DEFAULT 0,
            can_operate_domain TINYINT(1) NOT NULL DEFAULT 0
        ");
        $this->seed('member_levels', [
            ['level_value' => 1, 'level_name' => '일반회원', 'level_type' => 'MEMBER'],
        ]);

        $this->repository = new MemberRepository($this->db);
    }

    /**
     * 호출자가 public_id 를 넘기지 않는 실제 가입 경로의 모양 그대로.
     * NOT NULL 컬럼이므로 발급이 빠지면 여기서 INSERT 자체가 실패한다.
     */
    public function testCreateSucceedsWithoutAPublicIdAndTheRowIsReadable(): void
    {
        $memberId = $this->repository->create([
            'domain_id' => 1,
            'origin_domain_id' => 1,
            'user_id' => 'sns_google_abc12345_9f2a',
            'password' => 'secret-hash',
            'nickname' => '가입회원',
            'level_value' => 1,
            'status' => 'active',
            'created_at' => '2026-08-06 12:00:00',
            'updated_at' => '2026-08-06 12:00:00',
        ]);

        $this->assertNotNull($memberId);

        $rows = $this->fetchAll('SELECT * FROM members WHERE member_id = ?', [$memberId]);
        $this->assertCount(1, $rows);
        $this->assertSame('가입회원', $rows[0]['nickname']);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{22}\z/', (string) $rows[0]['public_id']);
    }

    /**
     * CHAR(22) 에 정확히 맞아야 한다. 더 길면 STRICT_TRANS_TABLES 에서 INSERT 가 거부되고,
     * 짧으면 CHAR 패딩 때문에 조회 정규식(findByPublicId)과 어긋난다.
     */
    public function testIssuedPublicIdFitsTheColumnExactly(): void
    {
        $memberId = $this->repository->create([
            'domain_id' => 1,
            'user_id' => 'width-check',
            'password' => 'hash',
            'status' => 'active',
        ]);

        $rows = $this->fetchAll('SELECT public_id, CHAR_LENGTH(public_id) AS len FROM members WHERE member_id = ?', [$memberId]);
        $this->assertSame(22, (int) $rows[0]['len']);
    }

    /** UNIQUE KEY 가 있으므로 발급이 겹치면 두 번째 가입부터 터진다. */
    public function testConcurrentRegistrationsDoNotCollideOnPublicId(): void
    {
        for ($i = 1; $i <= 50; $i++) {
            $this->repository->create([
                'domain_id' => 1,
                'user_id' => 'member' . $i,
                'password' => 'hash',
                'nickname' => '회원' . $i,
                'status' => 'active',
            ]);
        }

        $rows = $this->fetchAll('SELECT COUNT(DISTINCT public_id) AS distinct_count, COUNT(*) AS total FROM members');
        $this->assertSame(50, (int) $rows[0]['total']);
        $this->assertSame(50, (int) $rows[0]['distinct_count']);
    }

    /** 설치 시 최초 관리자(Installer)가 직접 넣는 경로. */
    public function testExplicitPublicIdSurvivesTheInsert(): void
    {
        $explicit = 'a1b2c3d4e5f6a7b8c9d0e1';

        $memberId = $this->repository->create([
            'public_id' => $explicit,
            'domain_id' => 1,
            'user_id' => 'admin',
            'password' => 'hash',
            'status' => 'active',
        ]);

        $rows = $this->fetchAll('SELECT public_id FROM members WHERE member_id = ?', [$memberId]);
        $this->assertSame($explicit, (string) $rows[0]['public_id']);
    }

    /**
     * 발급된 값으로 실제 조회 경로가 동작해야 한다. findByPublicId 는 정규식 게이트를
     * 통과한 값만 질의하므로, 발급 형식과 게이트가 어긋나면 방금 만든 회원을 못 찾는다.
     */
    public function testIssuedPublicIdIsResolvableThroughFindByPublicId(): void
    {
        $memberId = $this->repository->create([
            'domain_id' => 7,
            'user_id' => 'lookup',
            'password' => 'hash',
            'nickname' => '조회대상',
            'status' => 'active',
        ]);

        $rows = $this->fetchAll('SELECT public_id FROM members WHERE member_id = ?', [$memberId]);
        $found = $this->repository->findByPublicId(7, (string) $rows[0]['public_id']);

        $this->assertNotNull($found);
        $this->assertSame((int) $memberId, $found->getMemberId());
    }

    /** 도메인 스코프가 적용되어야 한다 — 다른 도메인의 public_id 로는 찾히면 안 된다. */
    public function testFindByPublicIdIsScopedToTheDomain(): void
    {
        $memberId = $this->repository->create([
            'domain_id' => 7,
            'user_id' => 'scoped',
            'password' => 'hash',
            'status' => 'active',
        ]);

        $rows = $this->fetchAll('SELECT public_id FROM members WHERE member_id = ?', [$memberId]);

        $this->assertNull($this->repository->findByPublicId(8, (string) $rows[0]['public_id']));
    }

    /** 한글 닉네임이 깨지지 않고 왕복해야 한다 (utf8mb4). */
    public function testMultibyteNicknameRoundTrips(): void
    {
        $nickname = '한글닉네임🙂';

        $memberId = $this->repository->create([
            'domain_id' => 1,
            'user_id' => 'utf8mb4',
            'password' => 'hash',
            'nickname' => $nickname,
            'status' => 'active',
        ]);

        $rows = $this->fetchAll('SELECT nickname FROM members WHERE member_id = ?', [$memberId]);
        $this->assertSame($nickname, $rows[0]['nickname']);
    }
}
