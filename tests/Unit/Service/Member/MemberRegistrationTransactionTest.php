<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Member;

use Mublo\Contract\Member\MemberRegistrationRequest;
use Mublo\Core\Crypto\PasswordHasher;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Entity\Member\Member;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseException;
use Mublo\Repository\Member\MemberFieldRepository;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Service\Member\Event\MemberRegisteredByUserEvent;
use Mublo\Service\Member\Event\MemberRegisteredEvent;
use Mublo\Service\Member\FieldEncryptionService;
use Mublo\Service\Member\MemberService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class MemberRegistrationTransactionTest extends TestCase
{
    private \PDO&MockObject $pdo;
    private MemberRepository&MockObject $members;
    private Database $db;
    private EventDispatcher $events;
    private MemberService $service;
    private bool $inTransaction = false;
    /** @var list<string> */
    private array $operations = [];
    /** @var array<string, mixed>|null 마지막으로 INSERT 된 회원 행 */
    private ?array $insertedRow = null;

    protected function setUp(): void
    {
        // PDO 연결 없이 실제 Database::transaction()의 커밋·롤백 호출을 검증한다.
        $this->pdo = $this->createMock(\PDO::class);
        $this->pdo->method('inTransaction')->willReturnCallback(fn (): bool => $this->inTransaction);
        $this->pdo->method('beginTransaction')->willReturnCallback(function (): bool {
            $this->operations[] = 'begin';
            $this->inTransaction = true;
            return true;
        });
        $this->pdo->method('commit')->willReturnCallback(function (): bool {
            $this->operations[] = 'commit';
            $this->inTransaction = false;
            return true;
        });
        $this->pdo->method('rollBack')->willReturnCallback(function (): bool {
            $this->operations[] = 'rollback';
            $this->inTransaction = false;
            return true;
        });
        $this->db = new Database($this->pdo);
        $this->events = new EventDispatcher();
        $this->members = $this->createMock(MemberRepository::class);
        $this->members->method('getDb')->willReturn($this->db);
        $this->members->method('create')->willReturnCallback(function (array $row): int {
            $this->assertTrue($this->db->inTransaction());
            $this->insertedRow = $row;
            $this->operations[] = 'member';
            return 1;
        });
        $this->members->method('find')->willReturn(Member::fromArray([
            'member_id' => 1, 'domain_id' => 3, 'user_id' => 'sns-user',
            'password' => 'secret-hash', 'status' => 'active',
        ]));
        $this->service = new MemberService(
            $this->members,
            $this->createStub(MemberFieldRepository::class),
            $this->createStub(FieldEncryptionService::class),
            new PasswordHasher(['algo' => PASSWORD_BCRYPT, 'cost' => 4]),
            eventDispatcher: $this->events,
        );
    }

    public function testRegistrationCommitsRelatedDataBeforeNotifyingBothSubscriberTypes(): void
    {
        $this->pdo->expects($this->once())->method('beginTransaction');
        $this->pdo->expects($this->once())->method('commit');
        $this->pdo->expects($this->never())->method('rollBack');
        $this->members->expects($this->once())->method('create');
        $this->members->expects($this->once())->method('find')->with(1);
        $received = [];
        foreach ([MemberRegisteredByUserEvent::class, MemberRegisteredEvent::class] as $type) {
            $this->events->addListener($type, function (MemberRegisteredEvent $event) use (&$received, $type): void {
                $this->operations[] = $type;
                $received[$type] = [$event->getMemberId(), $event->getDomainId(), $this->db->inTransaction()];
            });
        }
        $id = $this->service->registerAccount($this->request(), function (int $id): void {
            $this->assertSame(1, $id);
            $this->assertTrue($this->db->inTransaction());
            $this->operations[] = 'custom_fields';
            $this->operations[] = 'sns_link';
        });
        $this->assertSame(1, $id);
        $this->assertSame([
            'begin', 'member', 'custom_fields', 'sns_link', 'commit',
            MemberRegisteredByUserEvent::class, MemberRegisteredEvent::class,
        ], $this->operations);
        $this->assertSame([
            MemberRegisteredByUserEvent::class => [1, 3, false],
            MemberRegisteredEvent::class => [1, 3, false],
        ], $received);
    }

    public function testRelatedFailureRollsBackWithoutAnnouncingRegistration(): void
    {
        $this->pdo->expects($this->once())->method('beginTransaction');
        $this->pdo->expects($this->never())->method('commit');
        $this->pdo->expects($this->once())->method('rollBack');
        $this->members->expects($this->once())->method('create');
        $this->members->expects($this->never())->method('find');
        $this->events->addListener(MemberRegisteredEvent::class, function (): void {
            $this->operations[] = 'registered';
        });
        try {
            $this->service->registerAccount($this->request(), function (int $id): void {
                $this->assertSame(1, $id);
                $this->assertTrue($this->db->inTransaction());
                $this->operations[] = 'custom_fields';
                throw new \RuntimeException('SNS link failed');
            });
            $this->fail('Related persistence failure must propagate');
        } catch (DatabaseException $e) {
            $this->assertStringContainsString('SNS link failed', $e->getMessage());
        }
        $this->assertSame(['begin', 'member', 'custom_fields', 'rollback'], $this->operations);
        $this->assertFalse($this->db->inTransaction());
    }

    public function testListenerErrorDoesNotUndoCommittedRegistration(): void
    {
        $this->pdo->expects($this->once())->method('commit');
        $this->pdo->expects($this->never())->method('rollBack');
        $this->events->addListener(MemberRegisteredEvent::class, function (): void {
            $this->operations[] = 'registered';
            throw new \Error('listener failed');
        });
        $previous = ini_set('error_log', '/dev/null');
        try {
            $id = $this->service->registerAccount($this->request());
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $this->assertSame(1, $id);
        $this->assertFalse($this->db->inTransaction());
        $this->assertSame(['begin', 'member', 'commit', 'registered'], $this->operations);
    }

    public function testExternalTransactionIsRejectedBeforeWritingMember(): void
    {
        $this->inTransaction = true;
        $this->pdo->expects($this->never())->method('beginTransaction');
        $this->pdo->expects($this->never())->method('commit');
        $this->pdo->expects($this->never())->method('rollBack');
        $this->members->expects($this->never())->method('create');
        $this->members->expects($this->never())->method('find');
        try {
            $this->service->registerAccount($this->request());
            $this->fail('Cannot announce completion before an external commit');
        } catch (\LogicException $e) {
            $this->assertSame([], $this->operations);
        }
    }

    public function testRequestIsStoredAsAnActiveMemberRowOnItsOriginDomain(): void
    {
        $this->service->registerAccount($this->request());

        // origin_domain_id 는 프로필 완성 경로처럼 요청이 생략하면 가입 도메인으로 채운다.
        // 비면 태생 사이트의 아이디·닉네임 예약이 풀린다.
        $this->assertSame(3, $this->insertedRow['origin_domain_id']);
        $this->assertSame(3, $this->insertedRow['domain_id']);
        $this->assertSame('sns-user', $this->insertedRow['user_id']);
        $this->assertSame('secret-hash', $this->insertedRow['password']);
        $this->assertSame('닉네임', $this->insertedRow['nickname']);
        $this->assertSame('active', $this->insertedRow['status']);
        $this->assertSame(1, $this->insertedRow['level_value']);
        // 타임스탬프는 저장소와 테이블 기본값이 채운다 — 경로마다 따로 찍지 않는다.
        $this->assertArrayNotHasKey('created_at', $this->insertedRow);
        $this->assertArrayNotHasKey('updated_at', $this->insertedRow);
    }

    public function testExplicitOriginDomainSurvivesRegistration(): void
    {
        $this->service->registerAccount(new MemberRegistrationRequest(
            domainId: 3,
            userId: 'sns-user',
            passwordHash: 'secret-hash',
            nickname: '닉네임',
            levelValue: 5,
            originDomainId: 9,
            domainGroup: 'group-a',
        ));

        $this->assertSame(9, $this->insertedRow['origin_domain_id']);
        $this->assertSame(3, $this->insertedRow['domain_id']);
        $this->assertSame('group-a', $this->insertedRow['domain_group']);
        $this->assertSame(5, $this->insertedRow['level_value']);
    }

    private function request(): MemberRegistrationRequest
    {
        return new MemberRegistrationRequest(3, 'sns-user', 'secret-hash', '닉네임');
    }
}
