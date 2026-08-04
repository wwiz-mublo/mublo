<?php
declare(strict_types=1);
namespace Mublo\Repository\Frame;

use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;

/**
 * 도메인 프레임 오버라이드 저장소
 *
 * domain_frame_overrides — 도메인별 header/footer 템플릿 원문({{...}} 유지) 저장.
 * 도메인당 파트 1행 (UNIQUE domain_id, part).
 */
class DomainFrameOverrideRepository
{
    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? DatabaseManager::getInstance()->connect();
    }

    /**
     * 게시본 조회 (프론트 렌더용) — published 상태만
     *
     * @return array{html: string, css: string, js: string, seeded_from_skin: ?string}|null
     */
    public function findPublished(int $domainId, string $part): ?array
    {
        $rows = $this->db->select(
            "SELECT html, css, js, seeded_from_skin
               FROM domain_frame_overrides
              WHERE domain_id = ? AND part = ? AND status = 'published'
              LIMIT 1",
            [$domainId, $part]
        );

        return $rows[0] ?? null;
    }

    /**
     * 전체 행 조회 (에디터용 — draft 포함)
     */
    public function find(int $domainId, string $part): ?array
    {
        $rows = $this->db->select(
            'SELECT * FROM domain_frame_overrides WHERE domain_id = ? AND part = ? LIMIT 1',
            [$domainId, $part]
        );

        return $rows[0] ?? null;
    }

    /**
     * 초안 저장 (upsert) — 게시본은 건드리지 않는다
     */
    public function saveDraft(
        int $domainId,
        string $part,
        string $html,
        string $css,
        string $js,
        ?string $seededFromSkin,
        ?int $updatedBy
    ): void {
        $this->db->execute(
            'INSERT INTO domain_frame_overrides
                (domain_id, part, status, draft_html, draft_css, draft_js, seeded_from_skin, updated_by)
             VALUES (?, ?, \'draft\', ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                draft_html = VALUES(draft_html),
                draft_css = VALUES(draft_css),
                draft_js = VALUES(draft_js),
                seeded_from_skin = COALESCE(domain_frame_overrides.seeded_from_skin, VALUES(seeded_from_skin)),
                updated_by = VALUES(updated_by)',
            [$domainId, $part, $html, $css, $js, $seededFromSkin, $updatedBy]
        );
    }

    /**
     * 게시 — draft를 게시본으로 승격하고 status를 published로 전환
     *
     * @return bool draft가 존재해 게시됐으면 true
     */
    public function publish(int $domainId, string $part, ?int $updatedBy): bool
    {
        $affected = $this->db->execute(
            "UPDATE domain_frame_overrides
                SET html = draft_html, css = draft_css, js = draft_js,
                    status = 'published', updated_by = ?
              WHERE domain_id = ? AND part = ? AND draft_html IS NOT NULL",
            [$updatedBy, $domainId, $part]
        );

        return $affected > 0;
    }

    /**
     * 게시 해제 — "스킨으로 되돌리기" (비파괴)
     *
     * status만 draft로 내린다. 편집 내용(draft_*)과 이전 게시본(html 등)은
     * 그대로 보관되어 언제든 재게시할 수 있다.
     */
    public function unpublish(int $domainId, string $part): void
    {
        $this->db->execute(
            "UPDATE domain_frame_overrides SET status = 'draft'
              WHERE domain_id = ? AND part = ?",
            [$domainId, $part]
        );
    }

    /**
     * 오버라이드 완전 삭제
     *
     * 에디터 UI에서는 쓰지 않는다 — "스킨으로 되돌리기"는 unpublish(보관)이고,
     * 새로 시작하고 싶으면 시드를 다시 불러 저장하면 된다. 도메인 정리 등
     * 관리 목적의 물리 삭제 전용.
     */
    public function delete(int $domainId, string $part): void
    {
        $this->db->execute(
            'DELETE FROM domain_frame_overrides WHERE domain_id = ? AND part = ?',
            [$domainId, $part]
        );
    }
}
