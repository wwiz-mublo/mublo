<?php

namespace Tests\Integration;

/**
 * VisitorStats 004_move_admin_menu_permission_code.sql 을 실 DB 에 태운다.
 *
 * 방문자 통계 메뉴 코드가 002_P_VisitorStats_001 → P_VisitorStats_001 로 바뀌었다.
 * 회원 등급별 차단 설정이 새 코드로 옮겨져야 업그레이드 뒤에도 차단이 유지된다.
 */
class VisitorStatsMenuPermissionMigrationTest extends DatabaseTestCase
{
    private const MIGRATION = __DIR__ . '/../../plugins/VisitorStats/database/migrations/004_move_admin_menu_permission_code.sql';

    protected function setUp(): void
    {
        parent::setUp();

        // 실제 스키마(002_create_member_tables.sql)와 같은 키 구성
        $this->createTable('member_level_denied_menus', "
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            domain_id BIGINT UNSIGNED NOT NULL,
            level_value TINYINT UNSIGNED NOT NULL,
            menu_code VARCHAR(50) NOT NULL,
            denied_actions VARCHAR(50) NOT NULL DEFAULT '*',
            UNIQUE KEY uk_domain_level_menu (domain_id, level_value, menu_code)
        ");
    }

    public function testOldCodeRowsMoveToTheNewCode(): void
    {
        $this->seed('member_level_denied_menus', [
            ['domain_id' => 1, 'level_value' => 5, 'menu_code' => '002_P_VisitorStats_001', 'denied_actions' => '*'],
            ['domain_id' => 2, 'level_value' => 5, 'menu_code' => '002_P_VisitorStats_001', 'denied_actions' => 'list'],
            ['domain_id' => 1, 'level_value' => 5, 'menu_code' => '003', 'denied_actions' => '*'],
        ]);

        $this->runMigration();

        $this->assertSame([
            ['domain_id' => 1, 'menu_code' => '003', 'denied_actions' => '*'],
            ['domain_id' => 1, 'menu_code' => 'P_VisitorStats_001', 'denied_actions' => '*'],
            ['domain_id' => 2, 'menu_code' => 'P_VisitorStats_001', 'denied_actions' => 'list'],
        ], $this->rows());
    }

    public function testExistingNewCodeSettingWinsAndOldRowIsRemoved(): void
    {
        $this->seed('member_level_denied_menus', [
            ['domain_id' => 1, 'level_value' => 5, 'menu_code' => '002_P_VisitorStats_001', 'denied_actions' => '*'],
            ['domain_id' => 1, 'level_value' => 5, 'menu_code' => 'P_VisitorStats_001', 'denied_actions' => 'list'],
        ]);

        $this->runMigration();
        $this->runMigration(); // 다시 실행해도 결과가 같다

        $this->assertSame([
            ['domain_id' => 1, 'menu_code' => 'P_VisitorStats_001', 'denied_actions' => 'list'],
        ], $this->rows());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        return array_map(
            static fn (array $row): array => [
                'domain_id' => (int) $row['domain_id'],
                'menu_code' => $row['menu_code'],
                'denied_actions' => $row['denied_actions'],
            ],
            $this->fetchAll('SELECT domain_id, menu_code, denied_actions FROM member_level_denied_menus ORDER BY domain_id, menu_code')
        );
    }

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
}
