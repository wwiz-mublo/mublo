<?php
declare(strict_types=1);

namespace Tests\Board\Integration;

use Mublo\Packages\Board\Subscriber\DomainEventSubscriber;
use Tests\Integration\DatabaseTestCase;

/**
 * 패키지 설치·도메인 생성 시드가 새로 만든 그룹·게시판·메뉴만 기록 콜백에 넘긴다.
 * 무시된 INSERT IGNORE 뒤의 lastInsertId(이전 insert ID가 남을 수 있음)를 새 자원으로 착각하지 않는다.
 */
final class BoardSeedRevisionTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createTable('board_groups', '
            group_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            domain_id BIGINT UNSIGNED NOT NULL, group_slug VARCHAR(50) NOT NULL, group_name VARCHAR(100) NOT NULL,
            sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            UNIQUE KEY uk_domain_slug (domain_id, group_slug)
        ');
        $this->createTable('board_configs', '
            board_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            domain_id BIGINT UNSIGNED NOT NULL, group_id BIGINT UNSIGNED NOT NULL,
            board_slug VARCHAR(50) NOT NULL, board_name VARCHAR(100) NOT NULL, board_description TEXT NULL,
            list_level INT NOT NULL DEFAULT 0, read_level INT NOT NULL DEFAULT 0, write_level INT NOT NULL DEFAULT 0,
            comment_level INT NOT NULL DEFAULT 0, board_skin VARCHAR(50) NULL, use_comment TINYINT(1) NOT NULL DEFAULT 1,
            use_reaction TINYINT(1) NOT NULL DEFAULT 0, use_file TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0, is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            UNIQUE KEY uk_domain_slug (domain_id, board_slug)
        ');
        $this->createTable('unique_codes', '
            domain_id BIGINT UNSIGNED NOT NULL, code_type VARCHAR(20) NOT NULL, code VARCHAR(20) NOT NULL,
            UNIQUE KEY uk_code (domain_id, code_type, code)
        ');
        $this->createTable('menu_items', '
            item_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            domain_id BIGINT UNSIGNED NOT NULL, menu_code VARCHAR(20) NOT NULL, label VARCHAR(100) NOT NULL,
            url VARCHAR(255) NULL, visibility VARCHAR(20) NULL, provider_type VARCHAR(20) NULL, provider_name VARCHAR(50) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NULL, updated_at DATETIME NULL
        ');
        $this->createTable('board_articles', '
            article_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            domain_id BIGINT UNSIGNED NOT NULL, board_id BIGINT UNSIGNED NOT NULL, author_name VARCHAR(50) NULL,
            title VARCHAR(255) NULL, content TEXT NULL, status VARCHAR(20) NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL, published_at DATETIME NULL
        ');
    }

    public function testFirstSeedReportsCreatedResourcesInsideTheTransactionAndARerunReportsNothing(): void
    {
        $calls = [];
        $record = function (int $domainId, array $created) use (&$calls): void {
            $this->assertTrue($this->db->getPdo()->inTransaction(), '시드와 같은 트랜잭션에서 기록한다');
            $calls[] = [$domainId, $created];
        };

        DomainEventSubscriber::seedBoards($this->db, 7, $record);

        $this->assertCount(1, $calls);
        [$domainId, $created] = $calls[0];
        $this->assertSame(7, $domainId);
        $this->assertCount(1, $created['groups']);
        $this->assertCount(2, $created['boards']);
        $this->assertCount(6, $created['menus'], '게시판 2개 + 커뮤니티·마이페이지 4개 메뉴');

        // 다시 돌려도(재설치·재시도) 이미 있는 자원은 새로 만든 것으로 보고하지 않는다.
        // 바로 앞의 insert(첫 글)가 lastInsertId를 남겨도 영향이 없어야 한다.
        $calls = [];
        DomainEventSubscriber::seedBoards($this->db, 7, $record);
        $this->assertSame([], $calls);
    }
}
