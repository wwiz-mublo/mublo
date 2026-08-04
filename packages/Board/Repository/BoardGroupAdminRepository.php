<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * Class BoardGroupAdminRepository
 *
 * 게시판 그룹 관리자 매핑 테이블(board_group_admins) 관리
 *
 * 책임:
 * - 그룹 관리자 추가/제거
 * - 관리자 여부 확인
 * - 특정 회원이 관리하는 그룹 목록 조회
 * - 특정 그룹의 관리자 목록 조회
 *
 * 테이블 구조:
 * - group_id INT (PK)
 * - member_id BIGINT (PK)
 * - created_at TIMESTAMP
 */
class BoardGroupAdminRepository
{
    protected string $table = 'board_group_admins';
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * 특정 회원이 그룹 관리자인지 확인
     */
    public function isAdmin(int $groupId, int $memberId): bool
    {
        $result = $this->db->table($this->table)
            ->where('group_id', $groupId)
            ->where('member_id', $memberId)
            ->first();

        return $result !== null;
    }

    /**
     * 특정 그룹의 관리자 ID 목록 조회
     *
     * @return int[] 관리자 member_id 배열
     */
    public function getAdminsByGroup(int $groupId): array
    {
        $rows = $this->db->table($this->table)
            ->where('group_id', $groupId)
            ->get();

        return array_map(fn($row) => (int) $row['member_id'], $rows);
    }

    /**
     * 특정 회원이 관리하는 그룹 ID 목록 조회
     *
     * @return int[] 그룹 group_id 배열
     */
    public function getGroupsByAdmin(int $memberId): array
    {
        $rows = $this->db->table($this->table)
            ->where('member_id', $memberId)
            ->get();

        return array_map(fn($row) => (int) $row['group_id'], $rows);
    }

    /**
     * 그룹 관리자 추가
     *
     * @return bool 성공 여부 (이미 존재하면 false)
     */
    public function addAdmin(int $groupId, int $memberId): bool
    {
        // 중복 체크
        if ($this->isAdmin($groupId, $memberId)) {
            return false;
        }

        $result = $this->db->table($this->table)->insert([
            'group_id' => $groupId,
            'member_id' => $memberId,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return $result > 0;
    }

    /**
     * 그룹 관리자 제거
     *
     * @return bool 성공 여부
     */
    public function removeAdmin(int $groupId, int $memberId): bool
    {
        $deleted = $this->db->table($this->table)
            ->where('group_id', $groupId)
            ->where('member_id', $memberId)
            ->delete();

        return $deleted > 0;
    }

    /**
     * 그룹의 모든 관리자 제거
     *
     * @return int 삭제된 행 수
     */
    public function removeAllAdminsByGroup(int $groupId): int
    {
        return $this->db->table($this->table)
            ->where('group_id', $groupId)
            ->delete();
    }

    /**
     * 그룹 관리자 동기화 (기존 모두 삭제 후 새로 설정)
     *
     * @param int[] $memberIds 관리자 ID 배열
     * @return bool 성공 여부
     */
    public function syncAdmins(int $groupId, array $memberIds): bool
    {
        // 기존 관리자 모두 삭제
        $this->removeAllAdminsByGroup($groupId);

        // 새 관리자 추가
        foreach ($memberIds as $memberId) {
            $this->db->table($this->table)->insert([
                'group_id' => $groupId,
                'member_id' => (int) $memberId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return true;
    }
}
