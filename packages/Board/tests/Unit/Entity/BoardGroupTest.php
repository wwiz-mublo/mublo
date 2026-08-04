<?php
/**
 * packages/Board/tests/Unit/Entity/BoardGroupTest.php
 *
 * BoardGroup Entity 테스트
 */

namespace Tests\Board\Unit\Entity;

use PHPUnit\Framework\TestCase;
use Mublo\Packages\Board\Entity\BoardGroup;
use DateTimeImmutable;

class BoardGroupTest extends TestCase
{
    /**
     * 기본 데이터 배열 반환
     *
     * Note: group_admin_ids는 별도 매핑 테이블(board_group_admins)로 이동
     */
    private function getSampleData(): array
    {
        return [
            'group_id' => 1,
            'domain_id' => 1,
            'group_slug' => 'community',
            'group_name' => '커뮤니티',
            'group_description' => '커뮤니티 게시판 그룹',
            'list_level' => 0,
            'read_level' => 0,
            'write_level' => 1,
            'comment_level' => 1,
            'download_level' => 0,
            'sort_order' => 1,
            'is_active' => 1,
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-01 00:00:00',
        ];
    }

    /**
     * fromArray로 Entity 생성 테스트
     */
    public function testFromArrayCreatesEntity(): void
    {
        $data = $this->getSampleData();
        $entity = BoardGroup::fromArray($data);

        $this->assertInstanceOf(BoardGroup::class, $entity);
    }

    /**
     * 기본 getter 메서드 테스트
     */
    public function testGettersReturnCorrectValues(): void
    {
        $data = $this->getSampleData();
        $entity = BoardGroup::fromArray($data);

        $this->assertSame(1, $entity->getGroupId());
        $this->assertSame(1, $entity->getDomainId());
        $this->assertSame('community', $entity->getGroupSlug());
        $this->assertSame('커뮤니티', $entity->getGroupName());
        $this->assertSame('커뮤니티 게시판 그룹', $entity->getGroupDescription());
        // Note: getGroupAdminIds() 제거됨 - 별도 매핑 테이블 사용
        $this->assertSame(0, $entity->getListLevel());
        $this->assertSame(0, $entity->getReadLevel());
        $this->assertSame(1, $entity->getWriteLevel());
        $this->assertSame(1, $entity->getCommentLevel());
        $this->assertSame(0, $entity->getDownloadLevel());
        $this->assertSame(1, $entity->getSortOrder());
        $this->assertTrue($entity->isActive());
    }

    /**
     * toArray 메서드 테스트
     */
    public function testToArrayReturnsCorrectData(): void
    {
        $data = $this->getSampleData();
        $entity = BoardGroup::fromArray($data);
        $result = $entity->toArray();

        $this->assertSame(1, $result['group_id']);
        $this->assertSame(1, $result['domain_id']);
        $this->assertSame('community', $result['group_slug']);
        $this->assertSame('커뮤니티', $result['group_name']);
        $this->assertSame('커뮤니티 게시판 그룹', $result['group_description']);
        // Note: group_admin_ids는 별도 매핑 테이블 사용
        $this->assertSame(0, $result['list_level']);
        $this->assertSame(0, $result['read_level']);
        $this->assertSame(1, $result['write_level']);
        $this->assertSame(1, $result['comment_level']);
        $this->assertSame(0, $result['download_level']);
        $this->assertSame(1, $result['sort_order']);
        $this->assertTrue($result['is_active']);
    }

    /**
     * NULL 허용 필드 테스트
     */
    public function testNullableFieldsAcceptNull(): void
    {
        $data = $this->getSampleData();
        $data['group_description'] = null;

        $entity = BoardGroup::fromArray($data);

        $this->assertNull($entity->getGroupDescription());
    }

    /**
     * is_active 불리언 변환 테스트
     */
    public function testIsActiveConvertsToBoolean(): void
    {
        $data = $this->getSampleData();

        // 정수 1 → true
        $data['is_active'] = 1;
        $entity1 = BoardGroup::fromArray($data);
        $this->assertTrue($entity1->isActive());

        // 정수 0 → false
        $data['is_active'] = 0;
        $entity2 = BoardGroup::fromArray($data);
        $this->assertFalse($entity2->isActive());

        // 문자열 '1' → true
        $data['is_active'] = '1';
        $entity3 = BoardGroup::fromArray($data);
        $this->assertTrue($entity3->isActive());
    }

    /**
     * 날짜 필드 DateTimeImmutable 변환 테스트
     */
    public function testDateFieldsConvertToDateTimeImmutable(): void
    {
        $data = $this->getSampleData();
        $entity = BoardGroup::fromArray($data);

        $this->assertInstanceOf(DateTimeImmutable::class, $entity->getCreatedAt());
        $this->assertInstanceOf(DateTimeImmutable::class, $entity->getUpdatedAt());
        $this->assertSame('2025-01-01', $entity->getCreatedAt()->format('Y-m-d'));
    }

    // Note: isAdmin 테스트 제거됨 - 별도 매핑 테이블 사용 (BoardGroupAdminRepository)

    /**
     * canAccess 메서드 테스트 (레벨 기반 접근 권한)
     */
    public function testCanAccessReturnsCorrectly(): void
    {
        $data = $this->getSampleData();
        $data['list_level'] = 0;
        $data['read_level'] = 1;
        $data['write_level'] = 2;
        $data['comment_level'] = 1;
        $data['download_level'] = 3;

        $entity = BoardGroup::fromArray($data);

        // 레벨 0 회원
        $this->assertTrue($entity->canList(0));
        $this->assertFalse($entity->canRead(0));
        $this->assertFalse($entity->canWrite(0));

        // 레벨 1 회원
        $this->assertTrue($entity->canList(1));
        $this->assertTrue($entity->canRead(1));
        $this->assertFalse($entity->canWrite(1));
        $this->assertTrue($entity->canComment(1));

        // 레벨 2 회원
        $this->assertTrue($entity->canWrite(2));

        // 레벨 3 회원
        $this->assertTrue($entity->canDownload(3));
        $this->assertFalse($entity->canDownload(2));
    }

    /**
     * 레벨 기본값 테스트
     */
    public function testDefaultLevelValues(): void
    {
        $data = [
            'group_id' => 1,
            'domain_id' => 1,
            'group_slug' => 'test',
            'group_name' => '테스트',
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-01 00:00:00',
        ];

        $entity = BoardGroup::fromArray($data);

        $this->assertSame(0, $entity->getListLevel());
        $this->assertSame(0, $entity->getReadLevel());
        $this->assertSame(1, $entity->getWriteLevel());
        $this->assertSame(1, $entity->getCommentLevel());
        $this->assertSame(0, $entity->getDownloadLevel());
        $this->assertSame(0, $entity->getSortOrder());
        $this->assertTrue($entity->isActive());
    }
}
