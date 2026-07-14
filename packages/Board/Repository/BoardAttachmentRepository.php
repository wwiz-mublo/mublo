<?php
namespace Mublo\Packages\Board\Repository;

use Mublo\Packages\Board\Entity\BoardAttachment;
use Mublo\Infrastructure\Database\Database;
use Mublo\Infrastructure\Database\DatabaseManager;
use Mublo\Repository\BaseRepository;

/**
 * BoardAttachment Repository
 *
 * 첨부파일 데이터베이스 접근 담당
 *
 * 책임:
 * - board_attachments 테이블 CRUD
 * - BoardAttachment Entity 반환
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 * - 실제 파일 처리 (FileService 담당)
 */
class BoardAttachmentRepository extends BaseRepository
{
    protected string $table = 'board_attachments';
    protected string $entityClass = BoardAttachment::class;
    protected string $primaryKey = 'attachment_id';

    public function __construct(?Database $db = null)
    {
        $db = $db ?? DatabaseManager::getInstance()->connect();
        parent::__construct($db);
    }

    /**
     * 게시글별 첨부파일 목록 조회
     *
     * @param int $articleId 게시글 ID
     * @return BoardAttachment[]
     */
    public function findByArticle(int $articleId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('article_id', '=', $articleId)
            ->orderBy('created_at', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 게시글별 이미지 첨부파일 목록 조회
     *
     * @param int $articleId 게시글 ID
     * @return BoardAttachment[]
     */
    public function findImagesByArticle(int $articleId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('article_id', '=', $articleId)
            ->where('is_image', '=', 1)
            ->orderBy('created_at', 'ASC')
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 게시글별 첨부파일 수 조회
     */
    public function countByArticle(int $articleId): int
    {
        return $this->getDb()->table($this->table)
            ->where('article_id', '=', $articleId)
            ->count();
    }

    /**
     * 게시판별 첨부파일 수 조회
     */
    public function countByBoard(int $boardId): int
    {
        return $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->count();
    }

    /**
     * 게시글별 첨부파일 삭제
     *
     * @param int $articleId 게시글 ID
     * @return int 삭제된 행 수
     */
    public function deleteByArticle(int $articleId): int
    {
        return $this->getDb()->table($this->table)
            ->where('article_id', '=', $articleId)
            ->delete();
    }

    /**
     * 다운로드 횟수 증가
     */
    public function incrementDownloadCount(int $attachmentId): void
    {
        $table = $this->getDb()->prefixTable($this->table);
        $this->getDb()->execute(
            "UPDATE {$table} SET download_count = download_count + 1 WHERE attachment_id = ?",
            [$attachmentId]
        );
    }

    /**
     * 저장된 파일명으로 조회
     */
    public function findByStoredName(string $storedName): ?BoardAttachment
    {
        return $this->findOneBy(['stored_name' => $storedName]);
    }

    /**
     * 게시글별 총 파일 크기 조회
     */
    public function getTotalSizeByArticle(int $articleId): int
    {
        $result = $this->getDb()->table($this->table)
            ->where('article_id', '=', $articleId)
            ->sum('file_size');

        return (int) ($result ?? 0);
    }

    /**
     * 게시판별 총 파일 크기 조회
     */
    public function getTotalSizeByBoard(int $boardId): int
    {
        $result = $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->sum('file_size');

        return (int) ($result ?? 0);
    }

    /**
     * 도메인별 총 파일 크기 조회
     */
    public function getTotalSizeByDomain(int $domainId): int
    {
        $result = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->sum('file_size');

        return (int) ($result ?? 0);
    }

    /**
     * 확장자별 파일 수 조회
     *
     * @param int $boardId 게시판 ID
     * @return array ['jpg' => 10, 'pdf' => 5, ...]
     */
    public function countByExtension(int $boardId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->select(['file_extension', 'COUNT(*) as count'])
            ->where('board_id', '=', $boardId)
            ->groupBy('file_extension')
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[$row['file_extension']] = (int) $row['count'];
        }

        return $result;
    }

    /**
     * 최근 업로드된 파일 조회
     *
     * @param int $domainId 도메인 ID
     * @param int $limit 조회 개수
     * @return BoardAttachment[]
     */
    public function getRecentUploads(int $domainId, int $limit = 10): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 대용량 파일 조회
     *
     * @param int $domainId 도메인 ID
     * @param int $minSizeBytes 최소 크기 (bytes)
     * @param int $limit 조회 개수
     * @return BoardAttachment[]
     */
    public function getLargeFiles(int $domainId, int $minSizeBytes = 10485760, int $limit = 50): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('file_size', '>=', $minSizeBytes)
            ->orderBy('file_size', 'DESC')
            ->limit($limit)
            ->get();

        return $this->toEntities($rows);
    }

    /**
     * 파일 통계 (게시판별)
     *
     * @param int $boardId 게시판 ID
     * @return array
     */
    public function getStatsByBoard(int $boardId): array
    {
        $totalFiles = $this->countByBoard($boardId);
        $totalSize = $this->getTotalSizeByBoard($boardId);

        $imageCount = $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->where('is_image', '=', 1)
            ->count();

        $totalDownloads = $this->getDb()->table($this->table)
            ->where('board_id', '=', $boardId)
            ->sum('download_count');

        return [
            'total_files' => $totalFiles,
            'total_size' => $totalSize,
            'image_count' => $imageCount,
            'other_count' => $totalFiles - $imageCount,
            'total_downloads' => (int) ($totalDownloads ?? 0),
        ];
    }

    /**
     * 첨부파일 상세 조회 (게시글 정보 포함)
     */
    public function findWithArticle(int $attachmentId): ?array
    {
        $row = $this->getDb()->table($this->table . ' AS f')
            ->select([
                'f.*',
                'a.title AS article_title',
                'a.member_id AS article_member_id',
                'b.board_name',
                'b.board_slug',
            ])
            ->leftJoin('board_articles AS a', 'f.article_id', '=', 'a.article_id')
            ->leftJoin('board_configs AS b', 'f.board_id', '=', 'b.board_id')
            ->where('f.attachment_id', '=', $attachmentId)
            ->first();

        if (!$row) {
            return null;
        }

        return [
            'attachment' => BoardAttachment::fromArray($row),
            'article_title' => $row['article_title'] ?? '',
            'article_member_id' => $row['article_member_id'] ?? null,
            'board_name' => $row['board_name'] ?? '',
            'board_slug' => $row['board_slug'] ?? '',
        ];
    }
}
