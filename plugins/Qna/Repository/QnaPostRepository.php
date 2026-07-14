<?php
namespace Mublo\Plugin\Qna\Repository;

use Mublo\Infrastructure\Database\Database;

/**
 * QnA 글(질문+답변) 통합 데이터 접근.
 *
 * 역할 구분: parent_id IS NULL → 질문(헤드), parent_id = 질문ID → 답변.
 *
 * 보안 원칙:
 *  - 프론트 질문 조회는 domain_id + member_id 스코프를 함께 건다(파밍 차단).
 *  - 답변 조회는 항상 parent_id + domain_id 스코프로 제한한다(크로스-스레드 유출 차단).
 */
class QnaPostRepository
{
    private string $table = 'qna_posts';

    public function __construct(private Database $db)
    {
    }

    // ─────────────────────────────────────────
    // 질문(헤드) 조회
    // ─────────────────────────────────────────

    /** 질문 단건 (domain 스코프 강제) */
    public function findQuestion(int $postId, int $domainId): ?array
    {
        return $this->db->table($this->table)
            ->where('post_id', '=', $postId)
            ->where('domain_id', '=', $domainId)
            ->whereNull('parent_id')
            ->first() ?: null;
    }

    /**
     * 내 질문 목록 (회원 본인 것만) — 프론트 마이페이지용.
     *
     * @return array{items: array, total: int}
     */
    public function findQuestionsByMember(int $domainId, int $memberId, int $page = 1, int $perPage = 20): array
    {
        $base = fn () => $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->whereNull('parent_id')
            ->where('member_id', '=', $memberId);

        $total = $base()->count();
        $items = $base()
            ->orderBy('post_id', 'DESC')
            ->forPage($page, $perPage)
            ->get();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * 관리자 질문 목록 (상태/키워드 필터).
     *
     * @return array{items: array, total: int}
     */
    public function findQuestionsForAdmin(
        int $domainId,
        int $page = 1,
        int $perPage = 20,
        string $status = '',
        string $keyword = ''
    ): array {
        $base = function () use ($domainId, $status, $keyword) {
            $q = $this->db->table($this->table)
                ->where('domain_id', '=', $domainId)
                ->whereNull('parent_id');

            if ($status !== '') {
                $q->where('status', '=', $status);
            }
            if ($keyword !== '') {
                $q->where('title', 'LIKE', '%' . $keyword . '%');
            }
            return $q;
        };

        $total = $base()->count();
        $items = $base()
            ->orderBy('post_id', 'DESC')
            ->forPage($page, $perPage)
            ->get();

        return ['items' => $items, 'total' => $total];
    }

    // ─────────────────────────────────────────
    // 답변(스레드) 조회
    // ─────────────────────────────────────────

    /**
     * 특정 질문의 답변/추가문의 목록.
     *
     * @param bool $includePrivate 운영자 내부 비노출 메모 포함(운영자만 true)
     */
    public function findAnswers(int $questionId, int $domainId, bool $includePrivate = false): array
    {
        $query = $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('parent_id', '=', $questionId);

        if (!$includePrivate) {
            $query->where('is_private', '=', 0);
        }

        return $query->orderBy('post_id', 'ASC')->get();
    }

    /** 글 단건 (질문/답변 무관, domain 스코프) */
    public function findPost(int $postId, int $domainId): ?array
    {
        return $this->db->table($this->table)
            ->where('post_id', '=', $postId)
            ->where('domain_id', '=', $domainId)
            ->first() ?: null;
    }

    // ─────────────────────────────────────────
    // 변경
    // ─────────────────────────────────────────

    public function insert(array $data): ?int
    {
        return $this->db->table($this->table)->insert($data);
    }

    public function update(int $postId, int $domainId, array $data): int
    {
        return $this->db->table($this->table)
            ->where('post_id', '=', $postId)
            ->where('domain_id', '=', $domainId)
            ->update($data);
    }

    /** 질문 삭제 — 답변은 FK ON DELETE CASCADE 로 함께 삭제된다. */
    public function delete(int $postId, int $domainId): int
    {
        return $this->db->table($this->table)
            ->where('post_id', '=', $postId)
            ->where('domain_id', '=', $domainId)
            ->delete();
    }

    /**
     * 질문 일괄 삭제 (헤드만 대상, 답변은 FK CASCADE).
     *
     * @param int[] $ids
     */
    public function deleteManyQuestions(array $ids, int $domainId): int
    {
        if (!$ids) {
            return 0;
        }

        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->whereNull('parent_id')
            ->whereIn('post_id', $ids)
            ->delete();
    }

    /** 조회수 1 증가 */
    public function incrementView(int $postId, int $domainId): void
    {
        $this->db->execute(
            "UPDATE {$this->table} SET view_count = view_count + 1 WHERE post_id = ? AND domain_id = ?",
            [$postId, $domainId]
        );
    }

    /**
     * 질문의 스레드 집계 갱신 (답변 작성·삭제 후 호출).
     * reply_count / last_reply_at / last_reply_role 를 실제 답변 기준으로 재계산한다.
     */
    public function refreshThreadStats(int $questionId, int $domainId): void
    {
        $agg = $this->db->selectOne(
            "SELECT COUNT(*) AS cnt, MAX(created_at) AS last_at
             FROM {$this->table} WHERE parent_id = ?",
            [$questionId]
        );

        $lastRole = null;
        if ((int) ($agg['cnt'] ?? 0) > 0) {
            $last = $this->db->selectOne(
                "SELECT author_type FROM {$this->table}
                 WHERE parent_id = ? ORDER BY post_id DESC LIMIT 1",
                [$questionId]
            );
            $lastRole = $last['author_type'] ?? null;
        }

        $this->update($questionId, $domainId, [
            'reply_count'     => (int) ($agg['cnt'] ?? 0),
            'last_reply_at'   => $agg['last_at'] ?? null,
            'last_reply_role' => $lastRole,
        ]);
    }
}
