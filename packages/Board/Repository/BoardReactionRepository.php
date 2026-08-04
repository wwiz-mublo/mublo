<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Repository;

use Mublo\Packages\Board\Entity\BoardReaction;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\BaseRepository;

/**
 * BoardReaction Repository
 *
 * 반응 데이터베이스 접근 담당
 *
 * 책임:
 * - board_reactions 테이블 CRUD
 * - BoardReaction Entity 반환
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class BoardReactionRepository extends BaseRepository
{
    protected string $table = 'board_reactions';
    protected string $entityClass = BoardReaction::class;
    protected string $primaryKey = 'reaction_id';

    public function __construct(Database $db)
    {
        parent::__construct($db);
    }

    protected function getUpdatedAtField(): ?string
    {
        return null;
    }

    /**
     * 반응 조회 (대상 + 회원)
     *
     * @param string $targetType 대상 타입 (article, comment)
     * @param int $targetId 대상 ID
     * @param int $memberId 회원 ID
     * @return BoardReaction|null
     */
    public function findByTargetAndMember(string $targetType, int $targetId, int $memberId): ?BoardReaction
    {
        return $this->findOneBy([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'member_id' => $memberId,
        ]);
    }

    /**
     * 반응 존재 여부 확인
     */
    public function existsByTargetAndMember(string $targetType, int $targetId, int $memberId): bool
    {
        return $this->existsBy([
            'target_type' => $targetType,
            'target_id' => $targetId,
            'member_id' => $memberId,
        ]);
    }

    /**
     * 대상별 반응 목록 조회
     *
     * @param string $targetType 대상 타입
     * @param int $targetId 대상 ID
     * @return BoardReaction[]
     */
    public function findByTarget(string $targetType, int $targetId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('target_type', '=', $targetType)
            ->where('target_id', '=', $targetId)
            ->orderBy('created_at', 'DESC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 대상별 반응 수 조회
     */
    public function countByTarget(string $targetType, int $targetId): int
    {
        return $this->getDb()->table($this->table)
            ->where('target_type', '=', $targetType)
            ->where('target_id', '=', $targetId)
            ->count();
    }

    /**
     * 대상별 반응 타입별 수 조회
     *
     * @param string $targetType 대상 타입
     * @param int $targetId 대상 ID
     * @return array ['like' => 10, 'love' => 5, ...]
     */
    public function countByTargetGroupByType(string $targetType, int $targetId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->select(['reaction_type', 'COUNT(*) as count'])
            ->where('target_type', '=', $targetType)
            ->where('target_id', '=', $targetId)
            ->groupBy('reaction_type')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['reaction_type']] = (int) $row['count'];
        }

        return $result;
    }

    /**
     * 회원별 반응 목록 조회
     *
     * @param int $memberId 회원 ID
     * @param string|null $targetType 대상 타입 (null: 전체)
     * @param int $limit 조회 개수
     * @return BoardReaction[]
     */
    public function findByMember(int $memberId, ?string $targetType = null, int $limit = 100): array
    {
        $query = $this->getDb()->table($this->table)
            ->where('member_id', '=', $memberId);

        if ($targetType !== null) {
            $query->where('target_type', '=', $targetType);
        }

        $rows = $query
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 회원별 반응 수 조회
     */
    public function countByMember(int $memberId, ?string $targetType = null): int
    {
        $query = $this->getDb()->table($this->table)
            ->where('member_id', '=', $memberId);

        if ($targetType !== null) {
            $query->where('target_type', '=', $targetType);
        }

        return $query->count();
    }

    /**
     * 반응 토글 (있으면 삭제, 없으면 추가)
     *
     * @param int $domainId 도메인 ID
     * @param int $boardId 게시판 ID
     * @param string $targetType 대상 타입
     * @param int $targetId 대상 ID
     * @param int $memberId 회원 ID
     * @param string $reactionType 반응 타입
     * @return array ['action' => 'added'|'removed'|'changed', 'old_type' => ?string]
     */
    public function toggle(
        int $domainId,
        int $boardId,
        string $targetType,
        int $targetId,
        int $memberId,
        string $reactionType
    ): array {
        $existing = $this->findByTargetAndMember($targetType, $targetId, $memberId);

        if ($existing) {
            if ($existing->getReactionType() === $reactionType) {
                // 같은 타입이면 삭제
                // 삭제된 행의 id는 인스턴스별 고유값이라 revoke 멱등키 판별자로 넘긴다.
                $removedId = $existing->getReactionId();
                $this->delete($removedId);
                return ['action' => 'removed', 'old_type' => $reactionType, 'reaction_id' => $removedId];
            } else {
                // 다른 타입이면 변경
                $oldType = $existing->getReactionType();
                $this->update($existing->getReactionId(), [
                    'reaction_type' => $reactionType,
                ]);
                return ['action' => 'changed', 'old_type' => $oldType];
            }
        }

        // 없으면 추가
        $this->create([
            'domain_id' => $domainId,
            'board_id' => $boardId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'member_id' => $memberId,
            'reaction_type' => $reactionType,
        ]);

        return ['action' => 'added', 'old_type' => null];
    }

    /**
     * 대상별 반응 삭제 (게시글/댓글 삭제 시)
     */
    public function deleteByTarget(string $targetType, int $targetId): int
    {
        return $this->getDb()->table($this->table)
            ->where('target_type', '=', $targetType)
            ->where('target_id', '=', $targetId)
            ->delete();
    }

    /**
     * 게시판 + 반응 타입(key)별 영향 대상 조회 (카운트 재동기화용)
     *
     * @return array [['target_type' => string, 'target_id' => int], ...]
     */
    public function getTargetsByBoardAndType(int $boardId, string $reactionType): array
    {
        return $this->getDb()->table($this->table)
            ->select(['target_type', 'target_id'])
            ->where('board_id', '=', $boardId)
            ->where('reaction_type', '=', $reactionType)
            ->groupBy('target_type', 'target_id')
            ->get();
    }

    /**
     * 게시판 + 반응 타입(key)별 반응 삭제 (반응 설정에서 해당 반응 제거 시)
     */
    public function deleteByBoardAndType(int $boardId, string $reactionType): int
    {
        return $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->where('reaction_type', '=', $reactionType)
            ->delete();
    }

    /**
     * 반응자 목록 조회 (작성자 정보 포함)
     *
     * @param string $targetType 대상 타입
     * @param int $targetId 대상 ID
     * @param int $limit 조회 개수
     * @return array
     */
    public function getReactorsWithMember(string $targetType, int $targetId, int $limit = 50): array
    {
        $rows = $this->getDb()->table($this->table . ' AS r')
            ->select([
                'r.*',
                'm.user_id AS userid',
            ])
            ->leftJoin('members AS m', 'r.member_id', '=', 'm.member_id')
            ->where('r.target_type', '=', $targetType)
            ->where('r.target_id', '=', $targetId)
            ->orderBy('r.created_at', 'DESC')
            ->limit($limit)
            ->get();

        // nickname 정보 추가 (member_field_values + member_fields JOIN)
        $memberIds = array_filter(array_column($rows, 'member_id'));
        $nicknames = [];
        if (!empty($memberIds)) {
            $nicknameRows = $this->getDb()->table('member_field_values AS v')
                ->select(['v.member_id', 'v.field_value'])
                ->join('member_fields AS f', 'v.field_id', '=', 'f.field_id')
                ->whereIn('v.member_id', $memberIds)
                ->where('f.field_name', '=', 'nickname')
                ->get();
            foreach ($nicknameRows as $nr) {
                $nicknames[$nr['member_id']] = $nr['field_value'];
            }
        }

        foreach ($rows as &$row) {
            $row['nickname'] = $nicknames[$row['member_id']] ?? null;
        }

        return $rows;
    }

    /**
     * 게시판별 반응 통계
     *
     * @param int $boardId 게시판 ID
     * @return array ['total' => int, 'by_type' => [...]]
     */
    public function getStatsByBoard(int $boardId): array
    {
        $total = $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->count();

        $byType = $this->getDb()->table($this->table)
            ->select(['reaction_type', 'COUNT(*) as count'])
            ->where('board_id', '=', $boardId)
            ->groupBy('reaction_type')
            ->get();

        $typeStats = [];
        foreach ($byType as $row) {
            $typeStats[$row['reaction_type']] = (int) $row['count'];
        }

        return [
            'total' => $total,
            'by_type' => $typeStats,
        ];
    }

    /**
     * 회원이 누른 총 반응 수 (도메인 스코프). 마이보드 요약 카드용.
     */
    public function countByMemberInDomain(int $memberId, int $domainId): int
    {
        return $this->getDb()->table($this->table)
            ->where('member_id', '=', $memberId)
            ->where('domain_id', '=', $domainId)
            ->count();
    }

    /**
     * 회원이 누른 일별 반응 수 (최근 활동 추이용). since 이후 데이터만, 날짜→건수 맵.
     *
     * @return array<string,int> ['Y-m-d' => cnt]
     */
    public function getMemberDailyCounts(int $memberId, int $domainId, string $since): array
    {
        // 빌더 select 검증기가 DATE() 를 허용하지 않아 raw SQL 을 쓴다.
        $table = $this->table;
        $rows = $this->getDb()->select(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c
             FROM {$table}
             WHERE member_id = ? AND domain_id = ? AND created_at >= ?
             GROUP BY DATE(created_at)",
            [$memberId, $domainId, $since]
        );

        $map = [];
        foreach ($rows as $r) { $map[(string) $r['d']] = (int) $r['c']; }
        return $map;
    }
}
