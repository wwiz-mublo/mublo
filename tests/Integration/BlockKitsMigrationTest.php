<?php

namespace Tests\Integration;

/**
 * 011_create_block_kits.sql 을 실 DB 에 태운다.
 *
 * 마이그레이션 SQL 은 어떤 테스트도 실행하지 않는 코드다. PHP 는 그것을 파싱하지 않고,
 * 단위 테스트는 mock 을 쓰며, 리포지토리 통합 테스트조차 createTable() 로 자기 스키마를
 * 따로 만든다. 그래서 오타 하나가 배포 시점의 /admin/system/runMigration 에서 처음 터진다.
 *
 * 이 테스트는 파일을 있는 그대로 실행한다. DDL 문법, 컬럼 타입, 인덱스, 그리고
 * 외래키의 삭제 동작(CASCADE / SET NULL)까지 DB 가 직접 검증한다.
 */
class BlockKitsMigrationTest extends DatabaseTestCase
{
    private const MIGRATION = __DIR__ . '/../../database/migrations/011_create_block_kits.sql';

    /** @var string[] 마이그레이션이 만든 테이블 — 부모 테이블보다 먼저 지워야 한다 */
    private const KIT_TABLES = ['block_kit_applications', 'block_kits'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropKitTables();

        // FK 대상. 실제 스키마의 타입(BIGINT UNSIGNED)과 일치해야 FK 가 걸린다.
        $this->createTable('domain_configs', '
            domain_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            domain_name VARCHAR(100) NOT NULL DEFAULT \'\'
        ');
        $this->createTable('members', '
            member_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id VARCHAR(50) NOT NULL DEFAULT \'\'
        ');

        $this->runMigration();
    }

    protected function tearDown(): void
    {
        // 자식(block_kits)이 부모(domain_configs)를 참조하므로 먼저 지운다.
        $this->dropKitTables();

        parent::tearDown();
    }

    /**
     * DB 가 없으면 setUp 이 스킵하지만 tearDown 은 그래도 돈다. $pdo 는 null 이다.
     */
    private function dropKitTables(): void
    {
        if (self::$pdo === null) {
            return;
        }

        foreach (self::KIT_TABLES as $table) {
            self::$pdo->exec("DROP TABLE IF EXISTS `{$table}`");
        }
    }

    /**
     * 마이그레이션 파일을 문장 단위로 실행한다.
     *
     * 이 파일에는 프로시저나 트리거가 없으므로 세미콜론 분리로 충분하다.
     * 주석 안에 세미콜론이 섞이면 잘못 쪼개지므로 주석을 먼저 걷어낸다.
     */
    private function runMigration(): void
    {
        $sql = file_get_contents(self::MIGRATION);
        $this->assertNotFalse($sql, '마이그레이션 파일을 읽을 수 없습니다.');

        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') {
                self::$pdo->exec($statement);
            }
        }
    }

    private function seedKit(): int
    {
        $this->seed('domain_configs', [['domain_id' => 1, 'domain_name' => 'example.com']]);
        $this->seed('members', [['member_id' => 7, 'user_id' => 'admin']]);
        $this->seed('block_kits', [[
            'kit_id' => 100,
            'domain_id' => 1,
            'kit_name' => '메인 히어로',
            'target_kind' => 'position',
            'target_position' => 'index',
            'kit_json' => '{"format":"mublo-starter-kit"}',
        ]]);
        $this->seed('block_kit_applications', [[
            'application_id' => 500,
            'kit_id' => 100,
            'domain_id' => 1,
            'apply_mode' => 'append',
            'target_kind' => 'position',
            'applied_by' => 7,
        ]]);

        return 100;
    }

    // ========================================
    // DDL 자체
    // ========================================

    public function testMigrationCreatesBothTables(): void
    {
        foreach (self::KIT_TABLES as $table) {
            $this->assertNotEmpty(
                $this->fetchAll("SHOW TABLES LIKE '{$table}'"),
                "{$table} 테이블이 생성되어야 합니다"
            );
        }
    }

    /** 블록 킷 JSON 은 2 MiB 까지 들어온다. TEXT(64 KiB)로는 소리 없이 잘린다. */
    public function testKitJsonColumnHoldsTwoMebibytes(): void
    {
        $this->seedKit();

        $big = str_repeat('a', 2 * 1024 * 1024);
        $statement = self::$pdo->prepare('UPDATE block_kits SET kit_json = ? WHERE kit_id = 100');
        $statement->execute([$big]);

        $stored = $this->fetchAll('SELECT kit_json FROM block_kits WHERE kit_id = 100')[0]['kit_json'];
        $this->assertSame(2 * 1024 * 1024, strlen($stored), '2 MiB 블록 킷이 잘리지 않아야 합니다');
    }

    /** 목록 쿼리는 (domain_id, is_deleted) 로 거른다. */
    public function testListingIndexExists(): void
    {
        // SHOW INDEX 의 행 순서에 기대지 않고 SEQ_IN_INDEX 로 명시 정렬한다.
        $indexes = array_column($this->fetchAll(
            "SELECT COLUMN_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'block_kits'
               AND INDEX_NAME = 'idx_domain_listing'
             ORDER BY SEQ_IN_INDEX"
        ), 'COLUMN_NAME');

        $this->assertSame(['domain_id', 'is_deleted', 'kit_id'], $indexes);
    }

    // ========================================
    // 외래키 삭제 동작 — 여기가 진짜 위험한 곳
    // ========================================

    /** 도메인이 사라지면 그 도메인의 블록 킷도 사라진다. */
    public function testDeletingDomainCascadesToKits(): void
    {
        $this->seedKit();

        self::$pdo->exec('DELETE FROM domain_configs WHERE domain_id = 1');

        $this->assertSame([], $this->fetchAll('SELECT kit_id FROM block_kits'));
    }

    /** 블록 킷이 사라지면 그 블록 킷의 적용 이력도 사라진다. 이력만 남으면 의미가 없다. */
    public function testDeletingKitCascadesToApplications(): void
    {
        $this->seedKit();

        self::$pdo->exec('DELETE FROM block_kits WHERE kit_id = 100');

        $this->assertSame([], $this->fetchAll('SELECT application_id FROM block_kit_applications'));
    }

    /**
     * 회원이 탈퇴해도 "언제 무엇이 적용됐는지" 는 남아야 한다.
     * CASCADE 였다면 적용 이력이 통째로 사라져 되돌리기가 불가능해진다.
     */
    public function testDeletingMemberNullsAppliedByButKeepsHistory(): void
    {
        $this->seedKit();

        self::$pdo->exec('DELETE FROM members WHERE member_id = 7');

        $rows = $this->fetchAll('SELECT application_id, applied_by FROM block_kit_applications');
        $this->assertCount(1, $rows, '적용 이력은 남아야 합니다');
        $this->assertNull($rows[0]['applied_by']);
    }

    /** 존재하지 않는 블록 킷을 가리키는 이력은 만들 수 없다. */
    public function testApplicationRequiresAnExistingKit(): void
    {
        $this->seed('domain_configs', [['domain_id' => 1, 'domain_name' => 'example.com']]);

        $this->expectException(\PDOException::class);

        $this->seed('block_kit_applications', [[
            'kit_id' => 999,
            'domain_id' => 1,
            'apply_mode' => 'append',
            'target_kind' => 'position',
        ]]);
    }
}
