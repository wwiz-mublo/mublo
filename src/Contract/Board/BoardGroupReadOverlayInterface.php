<?php
declare(strict_types=1);

namespace Mublo\Contract\Board;

use Mublo\Core\Result\Result;

/**
 * 운영 DB를 바꾸지 않고 한 요청의 최종 게시판 그룹 목록을 덧씌우는 계약.
 *
 * 게시판 overlay({@see BoardReadOverlayInterface})와 짝을 이룬다. 아직 저장되지 않은 그룹은 음수 임시 ID로 등록하고,
 * 그 그룹에 넣을 게시판은 같은 음수 ID를 group_id로 쓴다. 커뮤니티 그룹 탭·그룹 화면·게시판 그룹 블록이 이 목록을 읽는다.
 */
interface BoardGroupReadOverlayInterface
{
    /**
     * 도메인의 최종 그룹 목록(순서 = 표시 순서)으로 바꾼다.
     *
     * @param list<BoardGroupDescriptor> $groups groupId > 0은 기존 그룹, < 0은 새 그룹
     */
    public function replaceGroups(int $domainId, array $groups): Result;

    /** @return list<BoardGroupDescriptor>|null null이면 해당 도메인에 overlay가 없음 */
    public function groups(int $domainId, bool $activeOnly = false): ?array;

    public function findBySlug(int $domainId, string $slug): ?BoardGroupDescriptor;

    public function findById(int $domainId, int $groupId): ?BoardGroupDescriptor;

    public function clear(): void;
}
