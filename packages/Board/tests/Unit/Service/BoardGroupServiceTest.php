<?php
/**
 * packages/Board/tests/Unit/Service/BoardGroupServiceTest.php
 *
 * BoardGroupService 테스트
 */

namespace Tests\Board\Unit\Service;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Mublo\Packages\Board\Service\BoardGroupService;
use Mublo\Packages\Board\Repository\BoardGroupRepository;
use Mublo\Packages\Board\Repository\BoardPermissionRepository;
use Mublo\Packages\Board\Entity\BoardGroup;
use Mublo\Packages\Board\Entity\BoardPermission;
use Mublo\Packages\Board\Enum\PermissionTargetType;

class BoardGroupServiceTest extends TestCase
{
    private BoardGroupService $service;
    private MockObject $repositoryMock;
    private MockObject $adminRepositoryMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repositoryMock = $this->createMock(BoardGroupRepository::class);
        $this->adminRepositoryMock = $this->createMock(BoardPermissionRepository::class);
        $this->service = new BoardGroupService($this->repositoryMock, $this->adminRepositoryMock);
    }

    /**
     * 샘플 BoardGroup Entity 생성
     *
     * Note: group_admin_ids는 별도 매핑 테이블로 이동됨
     */
    private function createBoardGroupMock(int $groupId, string $slug, string $name): BoardGroup
    {
        return BoardGroup::fromArray([
            'group_id' => $groupId,
            'domain_id' => 1,
            'group_slug' => $slug,
            'group_name' => $name,
            'group_description' => null,
            'list_level' => 0,
            'read_level' => 0,
            'write_level' => 1,
            'comment_level' => 1,
            'download_level' => 0,
            'sort_order' => 0,
            'is_active' => 1,
            'created_at' => '2025-01-01 00:00:00',
            'updated_at' => '2025-01-01 00:00:00',
        ]);
    }

    /**
     * getGroups - 도메인별 그룹 목록 조회
     */
    public function testGetGroupsReturnsGroupList(): void
    {
        $group1 = $this->createBoardGroupMock(1, 'community', '커뮤니티');
        $group2 = $this->createBoardGroupMock(2, 'notice', '공지사항');

        $this->repositoryMock->expects($this->once())
            ->method('findByDomain')
            ->with(1)
            ->willReturn([$group1, $group2]);

        $result = $this->service->getGroups(1);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
    }

    /**
     * getGroup - 단일 그룹 조회
     */
    public function testGetGroupReturnsGroupEntity(): void
    {
        $group = $this->createBoardGroupMock(1, 'community', '커뮤니티');

        $this->repositoryMock->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($group);

        $result = $this->service->getGroup(1);

        $this->assertInstanceOf(BoardGroup::class, $result);
        $this->assertSame('community', $result->getGroupSlug());
    }

    /**
     * getGroup - 존재하지 않는 그룹
     */
    public function testGetGroupReturnsNullWhenNotFound(): void
    {
        $this->repositoryMock->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $result = $this->service->getGroup(999);

        $this->assertNull($result);
    }

    /**
     * createGroup - 그룹 생성 성공
     */
    public function testCreateGroupSuccess(): void
    {
        $data = [
            'group_slug' => 'new-group',
            'group_name' => '새 그룹',
        ];

        // 슬러그 중복 검사 - 중복 없음
        $this->repositoryMock->expects($this->once())
            ->method('existsBySlug')
            ->with(1, 'new-group')
            ->willReturn(false);

        // 다음 정렬 순서
        $this->repositoryMock->expects($this->once())
            ->method('getNextSortOrder')
            ->with(1)
            ->willReturn(5);

        // 생성
        $this->repositoryMock->expects($this->once())
            ->method('create')
            ->willReturn(10);

        $result = $this->service->createGroup(1, $data);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(10, $result->get('group_id'));
    }

    /**
     * createGroup - 슬러그 중복 실패
     */
    public function testCreateGroupFailsWhenSlugExists(): void
    {
        $data = [
            'group_slug' => 'existing-slug',
            'group_name' => '새 그룹',
        ];

        $this->repositoryMock->expects($this->once())
            ->method('existsBySlug')
            ->with(1, 'existing-slug')
            ->willReturn(true);

        $result = $this->service->createGroup(1, $data);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('슬러그', $result->getMessage());
    }

    /**
     * createGroup - 필수 필드 누락
     */
    public function testCreateGroupFailsWhenRequiredFieldMissing(): void
    {
        $data = [
            'group_slug' => 'new-group',
            // group_name 누락
        ];

        $result = $this->service->createGroup(1, $data);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('필수', $result->getMessage());
    }

    /**
     * updateGroup - 그룹 수정 성공
     */
    public function testUpdateGroupSuccess(): void
    {
        $group = $this->createBoardGroupMock(1, 'community', '커뮤니티');
        $data = [
            'group_slug' => 'updated-slug',
            'group_name' => '수정된 그룹',
        ];

        $this->repositoryMock->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($group);

        // 슬러그 중복 검사 (자기 자신 제외)
        $this->repositoryMock->expects($this->once())
            ->method('existsBySlugExceptSelf')
            ->with(1, 'updated-slug', 1)
            ->willReturn(false);

        $this->repositoryMock->expects($this->once())
            ->method('update')
            ->with(1, $this->isType('array'))
            ->willReturn(1);

        $result = $this->service->updateGroup(1, $data);

        $this->assertTrue($result->isSuccess());
    }

    /**
     * updateGroup - 존재하지 않는 그룹
     */
    public function testUpdateGroupFailsWhenNotFound(): void
    {
        $data = [
            'group_name' => '수정된 그룹',
        ];

        $this->repositoryMock->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $result = $this->service->updateGroup(999, $data);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('찾을 수 없습니다', $result->getMessage());
    }

    /**
     * deleteGroup - 그룹 삭제 성공
     */
    public function testDeleteGroupSuccess(): void
    {
        $group = $this->createBoardGroupMock(1, 'community', '커뮤니티');

        $this->repositoryMock->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($group);

        // 그룹에 속한 게시판 수 확인 - 0개
        $this->repositoryMock->expects($this->once())
            ->method('getBoardCount')
            ->with(1)
            ->willReturn(0);

        $this->repositoryMock->expects($this->once())
            ->method('delete')
            ->with(1)
            ->willReturn(1);

        $result = $this->service->deleteGroup(1);

        $this->assertTrue($result->isSuccess());
    }

    /**
     * deleteGroup - 게시판이 있어서 삭제 실패
     */
    public function testDeleteGroupFailsWhenHasBoards(): void
    {
        $group = $this->createBoardGroupMock(1, 'community', '커뮤니티');

        $this->repositoryMock->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($group);

        // 그룹에 속한 게시판 수 확인 - 3개
        $this->repositoryMock->expects($this->once())
            ->method('getBoardCount')
            ->with(1)
            ->willReturn(3);

        $result = $this->service->deleteGroup(1);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('게시판', $result->getMessage());
    }

    /**
     * updateOrder - 정렬 순서 변경
     */
    public function testUpdateOrderSuccess(): void
    {
        $groupIds = [3, 1, 2];

        $this->repositoryMock->expects($this->once())
            ->method('updateOrder')
            ->with($groupIds)
            ->willReturn(true);

        $result = $this->service->updateOrder(1, $groupIds);

        $this->assertTrue($result->isSuccess());
    }

    /**
     * getSelectOptions - 선택 옵션 목록
     */
    public function testGetSelectOptionsReturnsFormattedArray(): void
    {
        $this->repositoryMock->expects($this->once())
            ->method('getSelectOptions')
            ->with(1)
            ->willReturn([
                ['value' => 1, 'label' => '커뮤니티'],
                ['value' => 2, 'label' => '공지사항'],
            ]);

        $result = $this->service->getSelectOptions(1);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]['value']);
        $this->assertSame('커뮤니티', $result[0]['label']);
    }

    /**
     * validateSlug - 유효한 슬러그
     */
    public function testValidateSlugWithValidSlug(): void
    {
        $result = $this->service->validateSlug('valid-slug');
        $this->assertTrue($result->isSuccess());
    }

    /**
     * validateSlug - 잘못된 슬러그 (특수문자)
     */
    public function testValidateSlugWithInvalidCharacters(): void
    {
        $result = $this->service->validateSlug('invalid slug!');
        $this->assertFalse($result->isSuccess());
    }

    /**
     * validateSlug - 예약어
     */
    public function testValidateSlugWithReservedWord(): void
    {
        $result = $this->service->validateSlug('admin');
        $this->assertFalse($result->isSuccess());
    }

    // ========================================
    // 그룹 관리자 관련 테스트 (BoardGroupAdminRepository 위임)
    // ========================================

    /**
     * isGroupAdmin - 관리자 여부 확인
     */
    public function testIsGroupAdminDelegatesToAdminRepository(): void
    {
        $this->adminRepositoryMock->expects($this->once())
            ->method('isGroupAdmin')
            ->with(1, 10)
            ->willReturn(true);

        $result = $this->service->isGroupAdmin(1, 10);

        $this->assertTrue($result);
    }

    /**
     * getGroupAdmins - 그룹 관리자 목록 조회
     */
    public function testGetGroupAdminsReturnsAdminList(): void
    {
        $this->adminRepositoryMock->expects($this->once())
            ->method('getAdminMemberIds')
            ->with('group', 1)
            ->willReturn([10, 20, 30]);

        $result = $this->service->getGroupAdmins(1);

        $this->assertSame([10, 20, 30], $result);
    }

    public function testGetGroupsByAdminReturnsGroupList(): void
    {
        $permissions = [
            BoardPermission::fromArray([
                'permission_id' => 1,
                'domain_id' => 1,
                'target_type' => 'group',
                'target_id' => 10,
                'member_id' => 7,
                'permission_type' => 'admin',
                'created_at' => '2025-01-01 00:00:00',
            ]),
            BoardPermission::fromArray([
                'permission_id' => 2,
                'domain_id' => 1,
                'target_type' => 'group',
                'target_id' => 20,
                'member_id' => 7,
                'permission_type' => 'admin',
                'created_at' => '2025-01-01 00:00:00',
            ]),
        ];

        $this->adminRepositoryMock->expects($this->once())
            ->method('findByMember')
            ->with(7, PermissionTargetType::GROUP->value)
            ->willReturn($permissions);

        $result = $this->service->getGroupsByAdmin(7);

        $this->assertSame([10, 20], $result);
    }

    public function testAddGroupAdminSuccess(): void
    {
        $group = $this->createBoardGroupMock(1, 'community', '커뮤니티');

        $this->repositoryMock->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($group);

        $this->adminRepositoryMock->expects($this->once())
            ->method('grantPermission')
            ->with(3, PermissionTargetType::GROUP->value, 1, 10)
            ->willReturn(55);

        $result = $this->service->addGroupAdmin(3, 1, 10);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(55, $result->get('permission_id'));
    }

    public function testAddGroupAdminFailsWhenAlreadyExists(): void
    {
        $group = $this->createBoardGroupMock(1, 'community', '커뮤니티');

        $this->repositoryMock->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($group);

        $this->adminRepositoryMock->expects($this->once())
            ->method('grantPermission')
            ->with(3, PermissionTargetType::GROUP->value, 1, 10)
            ->willReturn(null);

        $result = $this->service->addGroupAdmin(3, 1, 10);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('이미 등록된 관리자', $result->getMessage());
    }

    public function testSyncGroupAdminsSuccess(): void
    {
        $group = $this->createBoardGroupMock(1, 'community', '커뮤니티');

        $this->repositoryMock->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($group);

        $this->adminRepositoryMock->expects($this->once())
            ->method('setAdmins')
            ->with(3, PermissionTargetType::GROUP->value, 1, [10, 20, 30]);

        $result = $this->service->syncGroupAdmins(3, 1, [10, 20, 30]);

        $this->assertTrue($result->isSuccess());
    }
}
