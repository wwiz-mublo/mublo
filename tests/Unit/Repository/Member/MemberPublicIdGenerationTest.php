<?php
declare(strict_types=1);

namespace Tests\Unit\Repository\Member;

use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Member\MemberRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * MemberRepository::create() 가 public_id 를 발급하는 계약을 고정한다.
 *
 * 왜 필요한가
 *   회원이 만들어지는 경로는 세 곳이다 — 일반 가입(MemberService), 관리자 수동 생성
 *   (MemberAdminService), SNS 가입(MemberAccountGateway). 셋 다 public_id 를 넘기지
 *   않고 MemberRepository::create() 의 기본값 분기 하나에 의존한다.
 *
 *   그런데 그 분기를 실행하는 테스트가 없었다. 세 경로의 단위 테스트는 전부
 *   MemberRepository 를 mock 하므로 create() 본문이 돌지 않고, 회원을 실제로
 *   만드는 통합 테스트도 없었다. 즉 이 발급 로직을 지워도 전체 스위트가 통과한다.
 *
 *   parent::create() 를 감싸기만 한 override 라 리팩터링 중에 "이거 왜 있지" 하고
 *   없애기 쉬운 모양이다. 코드 어디에도 여기가 public_id 가 생기는 유일한 지점이라는
 *   표시가 없다 — 그 표시를 테스트로 남긴다.
 *
 * 실제로 깨지면
 *   members.public_id 는 CHAR(22) NOT NULL 이고 서버가 STRICT_TRANS_TABLES 라
 *   INSERT 가 즉시 거부된다. 데이터가 오염되지는 않지만 가입이 통째로 막히고,
 *   CI 가 못 잡으므로 발견 시점이 프로덕션 첫 가입이 된다.
 *
 * 실 스키마의 제약(길이·NOT NULL·UNIQUE)까지 태우는 검증은
 * Tests\Integration\MemberRepositoryCreateTest 에 있다.
 */
final class MemberPublicIdGenerationTest extends TestCase
{
    /** 런타임 발급 형식이자 조회 게이트(MemberRepository::findByPublicId)의 정규식. */
    private const PUBLIC_ID_PATTERN = '/\A[0-9a-f]{22}\z/';

    public function testCreateIssuesAPublicIdWhenTheCallerOmitsIt(): void
    {
        $db = $this->database();

        $memberId = (new MemberRepository($db))->create([
            'domain_id' => 1,
            'origin_domain_id' => 1,
            'user_id' => 'sns_google_abc12345_9f2a',
            'password' => 'hash',
            'nickname' => '가입회원',
            'level_value' => 1,
            'status' => 'active',
        ]);

        self::assertNotNull($memberId);
        self::assertMatchesRegularExpression(
            self::PUBLIC_ID_PATTERN,
            $this->publicIdOf($db, (int) $memberId)
        );
    }

    /** 발급 값이 회원마다 달라야 UNIQUE 제약과 공개 식별자로서의 의미가 성립한다. */
    public function testEachCreatedMemberGetsADistinctPublicId(): void
    {
        $db = $this->database();
        $repository = new MemberRepository($db);

        $issued = [];
        for ($i = 1; $i <= 20; $i++) {
            $memberId = $repository->create([
                'domain_id' => 1,
                'user_id' => 'user' . $i,
                'password' => 'hash',
                'nickname' => '회원' . $i,
                'status' => 'active',
            ]);
            $issued[] = $this->publicIdOf($db, (int) $memberId);
        }

        self::assertCount(20, array_unique($issued));
    }

    /**
     * 설치 시 최초 관리자(Installer)는 public_id 를 직접 넣는다.
     * 명시 전달을 덮어쓰면 그 경로가 조용히 다른 값을 갖게 된다.
     */
    public function testCreateKeepsAnExplicitlyProvidedPublicId(): void
    {
        $db = $this->database();
        $explicit = 'a1b2c3d4e5f6a7b8c9d0e1';

        $memberId = (new MemberRepository($db))->create([
            'public_id' => $explicit,
            'domain_id' => 1,
            'user_id' => 'admin',
            'password' => 'hash',
            'nickname' => '관리자',
            'status' => 'active',
        ]);

        self::assertSame($explicit, $this->publicIdOf($db, (int) $memberId));
    }

    /**
     * 빈 문자열은 "넘겼다"가 아니라 "없다"로 취급해야 한다. 그러지 않으면 첫 회원은
     * '' 로 들어가고 두 번째 회원이 UNIQUE 에 걸려, 원인에서 먼 곳에서 터진다.
     */
    public function testCreateTreatsAnEmptyPublicIdAsMissing(): void
    {
        $db = $this->database();

        $memberId = (new MemberRepository($db))->create([
            'public_id' => '',
            'domain_id' => 1,
            'user_id' => 'blank',
            'password' => 'hash',
            'nickname' => '빈값',
            'status' => 'active',
        ]);

        self::assertMatchesRegularExpression(
            self::PUBLIC_ID_PATTERN,
            $this->publicIdOf($db, (int) $memberId)
        );
    }

    private function publicIdOf(Database $db, int $memberId): string
    {
        $row = $db->selectOne('SELECT public_id FROM members WHERE member_id = ?', [$memberId]);

        return (string) ($row['public_id'] ?? '');
    }

    private function database(): Database
    {
        $db = new Database(new PDO('sqlite::memory:'));
        $db->execute('CREATE TABLE members (
            member_id INTEGER PRIMARY KEY AUTOINCREMENT,
            public_id TEXT NOT NULL,
            domain_id INTEGER NOT NULL,
            origin_domain_id INTEGER NULL,
            domain_group TEXT NULL,
            user_id TEXT NOT NULL,
            password TEXT NOT NULL,
            nickname TEXT NULL,
            level_value INTEGER NOT NULL DEFAULT 1,
            status TEXT NOT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )');

        return $db;
    }
}
