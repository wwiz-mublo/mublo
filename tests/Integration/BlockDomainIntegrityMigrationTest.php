<?php

namespace Tests\Integration;

use Mublo\Infrastructure\Database\SqlStatementSplitter;
use PDOException;

/** 023 마이그레이션이 블록 계층의 교차 도메인 참조를 DB에서 차단한다. */
class BlockDomainIntegrityMigrationTest extends DatabaseTestCase
{
    private const MIGRATION = __DIR__ . '/../../database/migrations/023_enforce_block_domain_integrity.sql';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('domain_configs', '
            domain_id BIGINT UNSIGNED PRIMARY KEY
        ');
        $this->createTable('block_pages', '
            page_id BIGINT UNSIGNED PRIMARY KEY,
            domain_id BIGINT UNSIGNED NOT NULL,
            CONSTRAINT fk_test_page_domain FOREIGN KEY (domain_id)
                REFERENCES domain_configs(domain_id) ON DELETE CASCADE
        ');
        $this->createTable('block_rows', '
            row_id BIGINT UNSIGNED PRIMARY KEY,
            domain_id BIGINT UNSIGNED NOT NULL,
            page_id BIGINT UNSIGNED NULL,
            position VARCHAR(20) NULL,
            CONSTRAINT fk_test_row_domain FOREIGN KEY (domain_id)
                REFERENCES domain_configs(domain_id) ON DELETE CASCADE,
            CONSTRAINT fk_test_row_page FOREIGN KEY (page_id)
                REFERENCES block_pages(page_id) ON DELETE CASCADE
        ');
        $this->createTable('block_columns', '
            column_id BIGINT UNSIGNED PRIMARY KEY,
            row_id BIGINT UNSIGNED NOT NULL,
            domain_id BIGINT UNSIGNED NOT NULL,
            CONSTRAINT fk_test_column_row FOREIGN KEY (row_id)
                REFERENCES block_rows(row_id) ON DELETE CASCADE,
            CONSTRAINT fk_test_column_domain FOREIGN KEY (domain_id)
                REFERENCES domain_configs(domain_id) ON DELETE CASCADE
        ');

        $this->runMigration();
        $this->seed('domain_configs', [['domain_id' => 1], ['domain_id' => 2]]);
        $this->seed('block_pages', [['page_id' => 100, 'domain_id' => 1]]);
        $this->seed('block_rows', [[
            'row_id' => 200,
            'domain_id' => 1,
            'page_id' => 100,
            'position' => null,
        ]]);
    }

    public function testRowCannotReferencePageFromAnotherDomain(): void
    {
        $this->expectException(PDOException::class);

        $this->seed('block_rows', [[
            'row_id' => 201,
            'domain_id' => 2,
            'page_id' => 100,
            'position' => null,
        ]]);
    }

    public function testColumnCannotReferenceRowFromAnotherDomain(): void
    {
        $this->expectException(PDOException::class);

        $this->seed('block_columns', [[
            'column_id' => 300,
            'row_id' => 200,
            'domain_id' => 2,
        ]]);
    }

    public function testMatchingDomainsRemainWritable(): void
    {
        $this->seed('block_columns', [[
            'column_id' => 300,
            'row_id' => 200,
            'domain_id' => 1,
        ]]);

        $this->assertSame(
            [['column_id' => 300]],
            $this->fetchAll('SELECT column_id FROM block_columns')
        );
    }

    private function runMigration(): void
    {
        $sql = file_get_contents(self::MIGRATION);
        $this->assertNotFalse($sql, '마이그레이션 파일을 읽을 수 없습니다.');
        foreach ((new SqlStatementSplitter())->split($sql) as $statement) {
            self::$pdo->exec($statement);
        }
    }
}
