<?php

namespace Tests\Unit\Service\Member;

use Mublo\Infrastructure\Database\Database;
use Mublo\Service\Member\MemberPublicAppearanceQueryService;
use PDO;
use PHPUnit\Framework\TestCase;

final class MemberPublicAppearanceQueryServiceTest extends TestCase
{
    private string $avatarDirectory;
    private string $avatarFile;

    protected function tearDown(): void
    {
        if (isset($this->avatarFile) && is_file($this->avatarFile)) {
            unlink($this->avatarFile);
        }
        if (isset($this->avatarDirectory) && is_dir($this->avatarDirectory)) {
            rmdir($this->avatarDirectory);
        }
    }

    public function testItBatchesPublicAppearanceAndEnforcesDomainBoundary(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE members (member_id INTEGER PRIMARY KEY, domain_id INTEGER, level_value INTEGER, public_id TEXT)');
        $pdo->exec('CREATE TABLE member_levels (level_value INTEGER PRIMARY KEY, is_super INTEGER)');
        $pdo->exec('CREATE TABLE member_fields (field_id INTEGER PRIMARY KEY, domain_id INTEGER, field_type TEXT)');
        $pdo->exec('CREATE TABLE member_field_values (member_id INTEGER, field_id INTEGER, field_value TEXT)');
        $pdo->exec('INSERT INTO member_levels VALUES (1, 1), (2, 0)');
        $pdo->exec("INSERT INTO members VALUES
            (10, 7, 1, 'a3f9c2e81b47d06f5a92c1'),
            (11, 7, 2, 'b3f9c2e81b47d06f5a92c2'),
            (12, 8, 1, 'c3f9c2e81b47d06f5a92c3')");
        $pdo->exec("INSERT INTO member_fields VALUES (20, 7, 'avatar'), (21, 8, 'avatar')");

        $relativeDirectory = 'test-member-appearance-' . bin2hex(random_bytes(5));
        $this->avatarDirectory = (string) MUBLO_PUBLIC_STORAGE_PATH . '/' . $relativeDirectory;
        mkdir($this->avatarDirectory, 0777, true);
        $this->avatarFile = $this->avatarDirectory . '/avatar.webp';
        file_put_contents($this->avatarFile, 'avatar');

        $metadata = json_encode([
            'stored_name' => 'avatar.webp',
            'relative_path' => $relativeDirectory,
            'disk' => 'public',
        ], JSON_THROW_ON_ERROR);
        $statement = $pdo->prepare('INSERT INTO member_field_values VALUES (?, ?, ?)');
        $statement->execute([10, 20, $metadata]);
        $statement->execute([12, 21, $metadata]);

        $result = (new MemberPublicAppearanceQueryService(new Database($pdo)))
            ->forMembers(7, [10, 11, 12, 10, 0]);

        self::assertSame([10, 11], array_keys($result));
        self::assertSame('a3f9c2e81b47d06f5a92c1', $result[10]->publicId);
        self::assertSame('/storage/' . $relativeDirectory . '/avatar.webp', $result[10]->avatarUrl);
        self::assertTrue($result[10]->super);
        self::assertSame('', $result[11]->avatarUrl);
        self::assertFalse($result[11]->super);
        self::assertArrayNotHasKey(12, $result, '다른 도메인의 회원 표시 정보는 노출하지 않는다');
    }
}
